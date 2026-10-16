<?php

declare(strict_types=1);

namespace App\Controllers\Secao;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\SessionService;

final class SessaoController
{
    // GET /api/auth/me — o front usa o "papel" para decidir qual área abrir.
    public function me(Request $request): Response
    {
        return Response::json(['usuario' => SessionService::publico($request->usuario)]);
    }

    // POST /api/auth/senha  {senha_atual, nova_senha} — só supervisor e admin têm senha.
    public function trocarSenha(Request $request): Response
    {
        $atual = $request->corpo['senha_atual'] ?? null;
        $nova = $request->corpo['nova_senha'] ?? null;

        if ($request->usuario['papel'] === 'cliente') {
            return Response::erro('Acesso negado.', 403);
        }
        if (!is_string($atual) || !is_string($nova) || strlen($nova) < AuthService::SENHA_MINIMA) {
            return Response::erro('A nova senha precisa ter ao menos ' . AuthService::SENHA_MINIMA . ' caracteres.', 422);
        }
        if (hash_equals($atual, $nova)) {
            return Response::erro('A nova senha precisa ser diferente da atual.', 422);
        }

        $id = (int) $request->usuario['id'];
        if (!(new AuthService())->trocarSenha($id, $atual, $nova)) {
            return Response::erro('Senha atual incorreta.', 422);
        }

        (new SessionService())->revogarOutras($id, $request->cookie(SessionService::COOKIE));

        return Response::vazia();
    }

    // POST /api/auth/logout
    public function logout(Request $request): Response
    {
        (new SessionService())->revogar($request->cookie(SessionService::COOKIE));

        return Response::vazia()->comCookie(SessionService::COOKIE, '', time() - 3600);
    }
}
