<?php

declare(strict_types=1);

namespace App\Controllers\Agenda;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Services\AgendaService;
use DateTimeImmutable;

final class HorarioController
{
    // GET /api/servicos/{id}/horarios?data=2026-10-20 (público)
    // Só devolve os inícios livres: nada de quem agendou nem do motivo
    // dos bloqueios.
    public function listar(Request $request): Response
    {
        $id = $request->params['id'];
        if (!Uuid::valido($id)) {
            return Response::erro("O id \"{$id}\" não é válido. Use o id (UUID) retornado na listagem de serviços.", 400);
        }

        $data = isset($request->query['data'])
            ? AgendaService::lerData($request->query['data'])
            : new DateTimeImmutable('today');

        if ($data === null) {
            return Response::erro('Informe a data no formato AAAA-MM-DD. Ex.: ?data=2026-10-20', 422);
        }

        $busca = Database::conexao()->prepare('SELECT id, duracao_minutos FROM servicos WHERE id = :id AND ativo = true');
        $busca->execute(['id' => $id]);
        $servico = $busca->fetch();

        if (!$servico) {
            return Response::erro("Nenhum serviço ativo encontrado com o id \"{$id}\".", 404);
        }

        $horarios = (new AgendaService())->horariosLivres($servico, $data);

        return Response::json([
            'data'            => $data->format('Y-m-d'),
            'servico_id'      => $servico['id'],
            'duracao_minutos' => (int) $servico['duracao_minutos'],
            // Para o front montar o calendário sem oferecer dias que o backend recusaria.
            'ultimo_dia'      => AgendaService::ultimoDia()->format('Y-m-d'),
            'horarios'        => array_map(static fn (DateTimeImmutable $h): string => $h->format('H:i'), $horarios),
        ]);
    }
}
