<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

// Deve vir sempre depois do AuthMiddleware no grupo de rotas.
final class PapelMiddleware implements Middleware
{
    private array $papeis;

    public function __construct(string ...$papeis)
    {
        $this->papeis = $papeis;
    }

    public function processar(Request $request, callable $proximo): Response
    {
        if (!in_array($request->usuario['papel'] ?? null, $this->papeis, true)) {
            return Response::erro('Acesso negado.', 403);
        }

        // Com a senha temporária, só as rotas de /api/auth (me, logout,
        // trocar senha) respondem; as áreas protegidas ficam fechadas.
        if ($request->usuario['trocar_senha'] ?? false) {
            return Response::erro('Troque a senha temporária antes de continuar.', 403);
        }

        return $proximo($request);
    }
}
