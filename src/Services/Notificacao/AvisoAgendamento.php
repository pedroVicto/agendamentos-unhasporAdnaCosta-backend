<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

use App\Core\Database;
use App\Core\Env;

// Avisos do fluxo de agendamento. Todos são chamados depois do commit e
// nunca derrubam a requisição: se o envio falhar, o agendamento continua
// valendo e o erro vai para o log.
final class AvisoAgendamento
{
    // Cliente pediu um horário: avisa a equipe.
    public static function novoPedido(string $agendamentoId): void
    {
        self::seguro(static function () use ($agendamentoId): void {
            $a = self::buscar($agendamentoId);
            $texto = "Novo pedido de agendamento aguardando resposta.\n\n"
                . self::resumo($a)
                . ($a['observacoes'] ? "Observações do cliente: {$a['observacoes']}\n" : '')
                . self::linkPainel();

            self::paraEquipe("Novo pedido: {$a['servico']} em " . self::data($a['data_hora']), $texto);
        });
    }

    // Cliente desistiu: avisa a equipe que o horário foi liberado.
    public static function canceladoPeloCliente(string $agendamentoId): void
    {
        self::seguro(static function () use ($agendamentoId): void {
            $a = self::buscar($agendamentoId);
            self::paraEquipe(
                "Agendamento cancelado pelo cliente: " . self::data($a['data_hora']),
                "O cliente cancelou o agendamento abaixo e o horário ficou livre.\n\n" . self::resumo($a)
            );
        });
    }

    // Supervisor confirmou, recusou ou cancelou: avisa o cliente.
    public static function respostaAoCliente(string $agendamentoId): void
    {
        self::seguro(static function () use ($agendamentoId): void {
            $a = self::buscar($agendamentoId);
            $quando = self::data($a['data_hora']);

            [$assunto, $texto] = match ($a['status']) {
                'confirmado' => [
                    'Agendamento confirmado',
                    "Olá, {$a['nome_cliente']}! Seu agendamento de {$a['servico']} em {$quando} está confirmado.",
                ],
                'recusado' => [
                    'Agendamento não aceito',
                    "Olá, {$a['nome_cliente']}. Infelizmente não conseguimos atender o pedido de {$a['servico']} em {$quando}."
                        . ($a['motivo'] ? " Motivo: {$a['motivo']}." : '') . ' Você pode escolher outro horário.',
                ],
                'cancelado' => [
                    'Agendamento cancelado',
                    "Olá, {$a['nome_cliente']}. Seu agendamento de {$a['servico']} em {$quando} foi cancelado."
                        . ($a['motivo'] ? " Motivo: {$a['motivo']}." : '') . ' Você pode escolher outro horário.',
                ],
                default => [null, null],
            };

            if ($texto === null) {
                return;
            }

            if ($a['email'] !== null) {
                Email::enviar($a['email'], $assunto, $texto . "\n\n" . self::linkPainel());
            } elseif ($a['telefone'] !== null) {
                self::sms($a['telefone'], $texto);
            }
        });
    }

    private static function buscar(string $id): array
    {
        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.nome_cliente, a.data_hora, a.data_hora_fim, a.status, a.motivo, a.observacoes,
                    s.nome AS servico, u.email, u.telefone
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
               LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.id = :id'
        );
        $busca->execute(['id' => $id]);

        return $busca->fetch() ?: throw new \RuntimeException("Agendamento {$id} não encontrado para aviso.");
    }

    private static function paraEquipe(string $assunto, string $texto): void
    {
        $emails = Database::conexao()->query(
            "SELECT email FROM usuarios
              WHERE papel IN ('supervisor', 'admin') AND ativo AND email IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($emails as $email) {
            // Um e-mail com problema não impede os outros.
            self::seguro(static fn () => Email::enviar($email, $assunto, $texto));
        }
    }

    private static function sms(string $telefone, string $texto): void
    {
        if (Env::get('NOTIFICADOR', 'real') === 'log'
            || (Env::get('TWILIO_SID') === null && Env::get('APP_ENV', 'production') === 'local')) {
            error_log("[sms] para={$telefone}\n{$texto}");

            return;
        }

        (new TwilioNotificador())->enviarMensagem($telefone, $texto);
    }

    private static function resumo(array $a): string
    {
        $fim = (new \DateTimeImmutable($a['data_hora_fim']))->format('H:i');

        return "Cliente: {$a['nome_cliente']}\n"
            . "Serviço: {$a['servico']}\n"
            . 'Quando: ' . self::data($a['data_hora']) . " até {$fim}\n";
    }

    private static function data(string $valor): string
    {
        // "\à" no format escaparia só o primeiro byte do "à" (UTF-8).
        $data = new \DateTimeImmutable($valor);

        return $data->format('d/m/Y') . ' às ' . $data->format('H:i');
    }

    private static function linkPainel(): string
    {
        $url = Env::get('FRONTEND_URL');

        return $url !== null ? "\nAcesse: {$url}\n" : '';
    }

    private static function seguro(callable $envio): void
    {
        try {
            $envio();
        } catch (\Throwable $e) {
            error_log('[aviso de agendamento] ' . $e->getMessage());
        }
    }
}
