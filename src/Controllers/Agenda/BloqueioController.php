<?php

declare(strict_types=1);

namespace App\Controllers\Agenda;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Services\AgendaService;
use DateTimeImmutable;

// Períodos sem atendimento dentro do expediente (supervisor e admin).
final class BloqueioController
{
    private const MAX_DIAS = 90;

    // GET /api/supervisor/bloqueios?de=2026-10-01&ate=2026-10-31
    // Sem filtro: de hoje em diante.
    public function listar(Request $request): Response
    {
        $de = isset($request->query['de']) ? AgendaService::lerData($request->query['de']) : new DateTimeImmutable('today');
        $ate = isset($request->query['ate']) ? AgendaService::lerData($request->query['ate']) : null;

        if ($de === null || (isset($request->query['ate']) && $ate === null)) {
            return Response::erro('Informe "de" e "ate" no formato AAAA-MM-DD.', 422);
        }

        $parametros = ['de' => AgendaService::formatoBanco($de)];
        $filtroAte = '';
        if ($ate !== null) {
            $filtroAte = ' AND b.inicio < :ate';
            $parametros['ate'] = AgendaService::formatoBanco($ate->modify('+1 day'));
        }

        $busca = Database::conexao()->prepare(
            'SELECT b.id, b.inicio, b.fim, b.motivo, u.nome AS criado_por, b.created_at
               FROM bloqueios_agenda b
               LEFT JOIN usuarios u ON u.id = b.criado_por
              WHERE b.fim > :de' . $filtroAte . '
              ORDER BY b.inicio'
        );
        $busca->execute($parametros);

        return Response::json(['bloqueios' => $busca->fetchAll()]);
    }

    // POST /api/supervisor/bloqueios  {inicio: "2026-10-20T14:00", fim: "2026-10-20T15:00", motivo: "imprevisto"}
    public function criar(Request $request): Response
    {
        $inicio = AgendaService::lerDataHora($request->corpo['inicio'] ?? null);
        $fim = AgendaService::lerDataHora($request->corpo['fim'] ?? null);
        $motivo = $request->input('motivo');

        $erros = [];
        if ($inicio === null) {
            $erros['inicio'] = 'Informe no formato AAAA-MM-DDTHH:MM. Ex.: 2026-10-20T14:00';
        }
        if ($fim === null) {
            $erros['fim'] = 'Informe no formato AAAA-MM-DDTHH:MM. Ex.: 2026-10-20T15:00';
        }
        if ($inicio !== null && $fim !== null) {
            if ($fim <= $inicio) {
                $erros['fim'] = 'O fim precisa ser depois do início.';
            } elseif ($fim <= new DateTimeImmutable()) {
                $erros['fim'] = 'O período já passou.';
            } elseif ($inicio->diff($fim)->days > self::MAX_DIAS) {
                $erros['fim'] = 'Um bloqueio pode durar no máximo ' . self::MAX_DIAS . ' dias.';
            }
        }
        if ($motivo === null || mb_strlen($motivo) > 150) {
            $erros['motivo'] = 'Informe o motivo (até 150 caracteres). Ex.: "imprevisto".';
        }
        if ($erros) {
            return Response::json([
                'erro'   => 'Não foi possível criar o bloqueio. Corrija os campos indicados.',
                'campos' => $erros,
            ], 422);
        }

        $pdo = Database::conexao();
        $pdo->beginTransaction();
        try {
            AgendaService::travar($pdo);

            // Não cancela clientes em silêncio: quem já tem horário nesse
            // período precisa ser recusado/cancelado antes (e é avisado).
            $conflitos = (new AgendaService())->agendamentosNoPeriodo($inicio, $fim);
            if ($conflitos) {
                $pdo->rollBack();

                return Response::json([
                    'erro'         => 'Há agendamentos neste período. Recuse ou cancele cada um antes de bloquear.',
                    'agendamentos' => $conflitos,
                ], 409);
            }

            $insercao = $pdo->prepare(
                'INSERT INTO bloqueios_agenda (inicio, fim, motivo, criado_por)
                 VALUES (:inicio, :fim, :motivo, :usuario_id)
                 RETURNING id, inicio, fim, motivo, created_at'
            );
            $insercao->execute([
                'inicio'     => AgendaService::formatoBanco($inicio),
                'fim'        => AgendaService::formatoBanco($fim),
                'motivo'     => $motivo,
                'usuario_id' => $request->usuario['id'],
            ]);
            $bloqueio = $insercao->fetch();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return Response::json(['bloqueio' => $bloqueio], 201);
    }

    // DELETE /api/supervisor/bloqueios/{id}
    public function remover(Request $request): Response
    {
        $id = $request->params['id'];
        if (!Uuid::valido($id)) {
            return Response::erro('Bloqueio não encontrado.', 404);
        }

        $remocao = Database::conexao()->prepare('DELETE FROM bloqueios_agenda WHERE id = :id');
        $remocao->execute(['id' => $id]);

        return $remocao->rowCount() === 0
            ? Response::erro('Bloqueio não encontrado.', 404)
            : Response::vazia();
    }
}
