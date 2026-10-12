<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CorsMiddleware;

require __DIR__ . '/../vendor/autoload.php';

Env::carregar(__DIR__ . '/../.env');

$router = new Router();

// Cada área tem o seu arquivo; a proteção por papel está no grupo de cada um.
foreach (['publico', 'autenticado', 'cliente', 'supervisor', 'admin'] as $area) {
    (require __DIR__ . "/../routes/{$area}.php")($router);
}

try {
    $response = (new CorsMiddleware())->processar(
        Request::capturar(),
        static fn (Request $request): Response => $router->despachar($request)
    );
} catch (Throwable $e) {
    // O detalhe vai para o log; o cliente só recebe uma mensagem genérica.
    error_log((string) $e);
    $response = Response::erro('Erro interno.', 500);
}

$response->enviar();
