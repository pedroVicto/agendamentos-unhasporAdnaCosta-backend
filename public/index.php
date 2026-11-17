<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CorsMiddleware;

require __DIR__ . '/../vendor/autoload.php';

Env::carregar(__DIR__ . '/../.env');

// Fuso do salão: é nele que a agenda (data_hora, expediente, bloqueios)
// é calculada. O banco continua em UTC para created_at, sessões e códigos.
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$router = new Router();

// Cada área tem o seu arquivo; a proteção por papel está no grupo de cada um.
foreach (['publico', 'autenticado', 'cliente', 'supervisor', 'admin'] as $area) {
    (require __DIR__ . "/../routes/{$area}.php")($router);
}

$cors = new CorsMiddleware();
$request = null;

try {
    $request = Request::capturar();
    $response = $cors->processar(
        $request,
        static fn (Request $request): Response => $router->despachar($request)
    );
} catch (Throwable $e) {
    // O detalhe vai para o log; o cliente só recebe uma mensagem genérica.
    error_log((string) $e);
    $erro = Response::erro('Erro interno.', 500);

    // O erro também passa pelo CORS: sem os cabeçalhos o navegador esconde a
    // resposta e o front mostra uma falha de CORS opaca em vez da mensagem da
    // API — o que faz um 500 do servidor parecer problema do front.
    $response = $request === null
        ? $erro
        : $cors->processar($request, static fn (Request $request): Response => $erro);
}

$response->enviar();
