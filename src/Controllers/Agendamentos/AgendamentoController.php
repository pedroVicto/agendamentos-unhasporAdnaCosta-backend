<?php

declare(strict_types=1);

namespace App\Controllers\Agendamentos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AgendamentoController
{
    // GET /api/cliente/agendamentos
    public function meus(Request $request): Response
    {
        // O filtro usa o id da sessão, nunca um id vindo da requisição:
        // é isso que impede um cliente de ver a agenda de outro.
        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.data_hora, a.status, a.observacoes, s.nome AS servico, s.preco
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
              WHERE a.usuario_id = :usuario_id
              ORDER BY a.data_hora DESC'
        );
        $busca->execute(['usuario_id' => $request->usuario['id']]);

        return Response::json(['agendamentos' => $busca->fetchAll()]);
    }

    // GET /api/supervisor/agendamentos
    public function todos(Request $request): Response
    {
        $agendamentos = Database::conexao()->query(
            'SELECT a.id, a.nome_cliente, a.usuario_id, a.data_hora, a.status, a.observacoes, s.nome AS servico
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
              ORDER BY a.data_hora DESC'
        )->fetchAll();

        return Response::json(['agendamentos' => $agendamentos]);
    }
}
