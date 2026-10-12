<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\SessionService;

final class AuthMiddleware implements Middleware
{
    public function processar(Request $request, callable $proximo): Response
    {
        $token = $request->cookie(SessionService::COOKIE);
        $usuario = $token !== null ? (new SessionService())->resolver($token) : null;

        if ($usuario === null) {
            return Response::erro('Não autenticado.', 401);
        }

        // O papel vem do banco a cada requisição, nunca do navegador.
        $request->usuario = $usuario;

        return $proximo($request);
    }
}
