<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\Contato;
use App\Services\SessionService;

final class AuthStaffController
{
    // POST /api/auth/staff/login  {email, senha}
    public function login(Request $request): Response
    {
        $email = Contato::email($request->input('email'));
        $senha = $request->corpo['senha'] ?? null;

        $usuario = ($email !== null && is_string($senha) && $senha !== '')
            ? (new AuthService())->loginStaff($email, $senha)
            : null;

        if ($usuario === null) {
            return Response::erro('E-mail ou senha inválidos.', 401);
        }

        return (new SessionService())->iniciar($usuario, $request);
    }
}
