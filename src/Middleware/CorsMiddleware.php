<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

final class CorsMiddleware implements Middleware
{
    public function processar(Request $request, callable $proximo): Response
    {
        $permitida = Env::get('FRONTEND_URL');
        $origem = $request->header('Origin');
        $liberar = $permitida !== null && $origem === $permitida;

        // Preflight: o navegador pergunta antes de mandar a requisição de verdade.
        $response = ($request->metodo === 'OPTIONS' && $origem !== null)
            ? Response::vazia()
            : $proximo($request);

        if ($liberar) {
            // Com cookies a origem tem que ser exata; "*" não é aceito pelo navegador.
            $response
                ->comHeader('Access-Control-Allow-Origin', $permitida)
                ->comHeader('Access-Control-Allow-Credentials', 'true')
                ->comHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->comHeader('Access-Control-Allow-Headers', 'Content-Type')
                ->comHeader('Vary', 'Origin');
        }

        return $response;
    }
}
