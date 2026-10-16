<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Request;
use App\Core\Response;
use App\Services\Contato;
use App\Services\OtpService;
use App\Services\SessionService;

final class AuthClienteController
{
    private const CANAIS = ['email', 'sms', 'whatsapp'];

    // POST /api/auth/cliente/codigo  {nome, email | telefone, canal?}
    public function solicitarCodigo(Request $request): Response
    {
        $nome = $request->input('nome');
        if ($nome === null || mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            return Response::erro('Informe o nome.', 422);
        }

        $contato = $this->contato($request);
        if ($contato === null) {
            return Response::erro('Informe um e-mail ou telefone válido.', 422);
        }
        [$campo, $destino, $canal] = $contato;

        try {
            (new OtpService())->solicitar($nome, $campo, $destino, $canal);
        } catch (\PDOException $e) {
            // Erro de banco não é falha de envio: deixa virar 500.
            throw $e;
        } catch (\RuntimeException $e) {
            error_log($e->getMessage());

            return Response::erro('Não foi possível enviar o código. Tente novamente.', 502);
        }

        // Mesma resposta tenha o código saído ou não (limite de reenvio,
        // contato de staff...), para não dar pistas a quem está sondando.
        return Response::json(['mensagem' => 'Se os dados estiverem corretos, o código foi enviado.']);
    }

    // POST /api/auth/cliente/verificar  {email | telefone, codigo}
    public function verificar(Request $request): Response
    {
        $contato = $this->contato($request);
        $codigo = $request->input('codigo');

        if ($contato === null || $codigo === null || !preg_match('/^\d{6}$/', $codigo)) {
            return Response::erro('Código inválido ou expirado.', 401);
        }
        [$campo, $destino] = $contato;

        $usuario = (new OtpService())->verificar($campo, $destino, $codigo);
        if ($usuario === null) {
            return Response::erro('Código inválido ou expirado.', 401);
        }

        return (new SessionService())->iniciar($usuario, $request);
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null [campo, destino, canal]
     */
    private function contato(Request $request): ?array
    {
        $email = Contato::email($request->input('email'));
        $telefone = Contato::telefone($request->input('telefone'));

        // Sem canal explícito: e-mail se veio e-mail, senão WhatsApp.
        $canal = $request->input('canal') ?? ($email !== null ? 'email' : 'whatsapp');
        if (!in_array($canal, self::CANAIS, true)) {
            return null;
        }

        if ($canal === 'email') {
            return $email !== null ? ['email', $email, $canal] : null;
        }

        return $telefone !== null ? ['telefone', $telefone, $canal] : null;
    }
}
