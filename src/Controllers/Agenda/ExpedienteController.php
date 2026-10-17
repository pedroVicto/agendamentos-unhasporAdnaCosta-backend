<?php

declare(strict_types=1);

namespace App\Controllers\Agenda;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AgendaService;

// Horário de funcionamento da semana (supervisor e admin).
final class ExpedienteController
{
    // GET /api/supervisor/expediente
    public function listar(Request $request): Response
    {
        $faixas = Database::conexao()->query(
            "SELECT dia_semana, to_char(hora_inicio, 'HH24:MI') AS inicio, to_char(hora_fim, 'HH24:MI') AS fim
               FROM expediente
              ORDER BY dia_semana, hora_inicio"
        )->fetchAll();

        return Response::json(['faixas' => $faixas]);
    }

    // PUT /api/supervisor/expediente
    // {faixas: [{dia_semana: 1, inicio: "09:00", fim: "12:00"}, ...]}
    // Substitui a semana inteira. Agendamentos já feitos não são mexidos:
    // a mudança vale para os próximos pedidos.
    public function substituir(Request $request): Response
    {
        $faixas = $request->corpo['faixas'] ?? null;
        if (!is_array($faixas) || !array_is_list($faixas) || count($faixas) > 50) {
            return Response::erro('Envie "faixas" como uma lista (pode ser vazia para fechar a semana toda).', 422);
        }

        $erros = [];
        $porDia = [];
        foreach ($faixas as $i => $faixa) {
            if (!is_array($faixa)) {
                $erros["faixas.{$i}"] = 'Cada faixa deve ser um objeto {dia_semana, inicio, fim}.';
                continue;
            }
            $dia = $faixa['dia_semana'] ?? null;
            $inicio = $this->lerHora($faixa['inicio'] ?? null);
            $fim = $this->lerHora($faixa['fim'] ?? null);

            if (!is_int($dia) || $dia < 0 || $dia > 6) {
                $erros["faixas.{$i}.dia_semana"] = 'Use 0 (domingo) a 6 (sábado).';
            }
            if ($inicio === null || $fim === null) {
                $erros["faixas.{$i}"] = 'Informe "inicio" e "fim" no formato HH:MM. Ex.: "09:00".';
            } elseif ($fim <= $inicio) {
                $erros["faixas.{$i}"] = "O fim ({$fim}) precisa ser depois do início ({$inicio}).";
            }

            if (!isset($erros["faixas.{$i}"]) && !isset($erros["faixas.{$i}.dia_semana"])) {
                $porDia[$dia][] = [$inicio, $fim, $i];
            }
        }

        // Faixas do mesmo dia não podem se sobrepor (09-12 e 11-14, por exemplo).
        foreach ($porDia as $lista) {
            usort($lista, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            for ($j = 1; $j < count($lista); $j++) {
                if ($lista[$j][0] < $lista[$j - 1][1]) {
                    $erros["faixas.{$lista[$j][2]}"] = 'Esta faixa se sobrepõe a outra do mesmo dia.';
                }
            }
        }

        if ($erros) {
            return Response::json([
                'erro'   => 'Não foi possível salvar o expediente. Corrija os campos indicados.',
                'campos' => $erros,
            ], 422);
        }

        $pdo = Database::conexao();
        $pdo->beginTransaction();
        try {
            AgendaService::travar($pdo);
            $pdo->exec('DELETE FROM expediente');

            $insercao = $pdo->prepare(
                'INSERT INTO expediente (dia_semana, hora_inicio, hora_fim) VALUES (:dia, :inicio, :fim)'
            );
            foreach ($faixas as $faixa) {
                $insercao->execute([
                    'dia'    => $faixa['dia_semana'],
                    'inicio' => $this->lerHora($faixa['inicio']),
                    'fim'    => $this->lerHora($faixa['fim']),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->listar($request);
    }

    // "9:00" e "09:00" viram "09:00"; o resto vira null.
    private function lerHora(mixed $valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $valor, $m)) {
            return null;
        }

        return sprintf('%02d:%s', $m[1], $m[2]);
    }
}
