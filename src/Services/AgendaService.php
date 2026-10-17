<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateInterval;
use DateTimeImmutable;
use PDO;

// Regras da agenda num lugar só: a mesma conta que monta a lista de horários
// livres é a que valida o pedido do cliente. O que aparece para ele é
// exatamente o que o backend aceita.
final class AgendaService
{
    // Chave do pg_advisory_xact_lock. Quem cria agendamento ou bloqueio pega
    // essa trava dentro da transação, então as checagens não se atropelam.
    private const TRAVA = 20261017;

    // Pendente e confirmado ocupam o horário; recusado e cancelado o liberam.
    public const STATUS_ATIVOS = "('pendente', 'confirmado')";

    public static function travar(PDO $pdo): void
    {
        $pdo->query('SELECT pg_advisory_xact_lock(' . self::TRAVA . ')');
    }

    /**
     * Aceita "2026-10-20T14:00", "2026-10-20 14:00" ou com ":00" no fim.
     * Devolve null para qualquer coisa fora disso ou para datas impossíveis.
     */
    public static function lerDataHora(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:00)?$/', trim($valor), $m)) {
            return null;
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$m[1]} {$m[2]}");

        // createFromFormat aceita "2026-02-31" e vira março; a volta denuncia.
        return $data !== false && $data->format('Y-m-d H:i') === "{$m[1]} {$m[2]}" ? $data : null;
    }

    public static function lerData(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data !== false && $data->format('Y-m-d') === $valor ? $data : null;
    }

    /**
     * Agora, no fuso do salão, pronto para comparar com data_hora.
     *
     * Não use NOW() do banco contra data_hora: a conexão está em UTC (é nele
     * que created_at, sessões e códigos são gravados), enquanto data_hora
     * guarda o horário local do salão.
     */
    public static function agora(): string
    {
        return self::formatoBanco(new DateTimeImmutable());
    }

    public static function formatoBanco(DateTimeImmutable $data): string
    {
        return $data->format('Y-m-d H:i:s');
    }

    /** Último dia que o cliente pode escolher. */
    public static function ultimoDia(): DateTimeImmutable
    {
        return (new DateTimeImmutable('today'))->modify('+' . self::config('AGENDA_JANELA_DIAS', 60) . ' days');
    }

    /**
     * Inícios livres para o serviço no dia, em ordem.
     *
     * @return DateTimeImmutable[]
     */
    public function horariosLivres(array $servico, DateTimeImmutable $dia): array
    {
        $dia = $dia->setTime(0, 0);
        if ($dia < new DateTimeImmutable('today') || $dia > self::ultimoDia()) {
            return [];
        }

        $pdo = Database::conexao();

        $faixas = $pdo->prepare(
            'SELECT hora_inicio, hora_fim FROM expediente WHERE dia_semana = :dia ORDER BY hora_inicio'
        );
        $faixas->execute(['dia' => (int) $dia->format('w')]);
        $faixas = $faixas->fetchAll();

        if (!$faixas) {
            return [];
        }

        $ocupados = $this->ocupados($dia, $dia->modify('+1 day'));
        $duracao = new DateInterval('PT' . (int) $servico['duracao_minutos'] . 'M');
        // Um intervalo 0 no .env travaria o laço abaixo.
        $passo = new DateInterval('PT' . max(5, self::config('AGENDA_INTERVALO_MINUTOS', 30)) . 'M');
        $limite = (new DateTimeImmutable())->modify('+' . self::config('AGENDA_ANTECEDENCIA_MINUTOS', 120) . ' minutes');

        $livres = [];
        foreach ($faixas as $faixa) {
            $inicio = $this->noDia($dia, $faixa['hora_inicio']);
            $fimFaixa = $this->noDia($dia, $faixa['hora_fim']);

            for (; $inicio->add($duracao) <= $fimFaixa; $inicio = $inicio->add($passo)) {
                $fim = $inicio->add($duracao);
                if ($inicio >= $limite && !$this->sobrepoe($inicio, $fim, $ocupados)) {
                    $livres[] = $inicio;
                }
            }
        }

        return $livres;
    }

    public function disponivel(array $servico, DateTimeImmutable $inicio): bool
    {
        foreach ($this->horariosLivres($servico, $inicio) as $livre) {
            if ($livre == $inicio) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agendamentos ativos que encostam no período (usado ao criar bloqueio).
     */
    public function agendamentosNoPeriodo(DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.nome_cliente, a.data_hora, a.data_hora_fim, a.status, s.nome AS servico
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
              WHERE a.status IN ' . self::STATUS_ATIVOS . '
                AND a.data_hora < :fim AND a.data_hora_fim > :inicio
                AND a.data_hora_fim > :agora
              ORDER BY a.data_hora'
        );
        $busca->execute([
            'inicio' => self::formatoBanco($inicio),
            'fim'    => self::formatoBanco($fim),
            'agora'  => self::agora(),
        ]);

        return $busca->fetchAll();
    }

    /** @return array<array{0: DateTimeImmutable, 1: DateTimeImmutable}> */
    private function ocupados(DateTimeImmutable $de, DateTimeImmutable $ate): array
    {
        // O pdo_pgsql não deixa repetir o mesmo :nome numa consulta.
        $busca = Database::conexao()->prepare(
            'SELECT data_hora AS inicio, data_hora_fim AS fim FROM agendamentos
              WHERE status IN ' . self::STATUS_ATIVOS . ' AND data_hora < :ate1 AND data_hora_fim > :de1
             UNION ALL
             SELECT inicio, fim FROM bloqueios_agenda
              WHERE inicio < :ate2 AND fim > :de2'
        );
        $busca->execute([
            'de1' => self::formatoBanco($de), 'ate1' => self::formatoBanco($ate),
            'de2' => self::formatoBanco($de), 'ate2' => self::formatoBanco($ate),
        ]);

        return array_map(
            static fn (array $linha): array => [new DateTimeImmutable($linha['inicio']), new DateTimeImmutable($linha['fim'])],
            $busca->fetchAll()
        );
    }

    private function sobrepoe(DateTimeImmutable $inicio, DateTimeImmutable $fim, array $ocupados): bool
    {
        foreach ($ocupados as [$ocupadoInicio, $ocupadoFim]) {
            if ($inicio < $ocupadoFim && $ocupadoInicio < $fim) {
                return true;
            }
        }

        return false;
    }

    // "09:30:00" do Postgres -> 09:30 no dia informado.
    private function noDia(DateTimeImmutable $dia, string $hora): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $dia->setTime($h, $m);
    }

    public static function config(string $chave, int $padrao): int
    {
        $valor = Env::get($chave);

        return $valor !== null && ctype_digit($valor) ? (int) $valor : $padrao;
    }
}
