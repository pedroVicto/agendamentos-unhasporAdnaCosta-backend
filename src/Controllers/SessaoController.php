<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SessionService;

final class SessaoController
{
    // GET /api/auth/me — o front usa o "papel" para decidir qual área abrir.
    public function me(Request $request): Response
    {
        return Response::json(['usuario' => SessionService::publico($request->usuario)]);
    }

    // POST /api/auth/logout
    public function logout(Request $request): Response
    {
        (new SessionService())->revogar($request->cookie(SessionService::COOKIE));

        return Response::vazia()->comCookie(SessionService::COOKIE, '', time() - 3600);
    }
}
