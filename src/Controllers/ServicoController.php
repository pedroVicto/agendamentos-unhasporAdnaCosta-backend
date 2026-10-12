<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ServicoController
{
    // GET /api/servicos — vitrine, sem login.
    public function listar(Request $request): Response
    {
        $servicos = Database::conexao()
            ->query('SELECT id, nome, preco, descricao FROM servicos ORDER BY nome')
            ->fetchAll();

        return Response::json(['servicos' => $servicos]);
    }
}
