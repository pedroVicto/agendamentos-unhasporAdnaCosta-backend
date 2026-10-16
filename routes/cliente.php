<?php

declare(strict_types=1);

use App\Controllers\Agendamentos\AgendamentoController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PapelMiddleware;

return static function (Router $router): void {
    $router->grupo(
        '/api/cliente',
        [new AuthMiddleware(), new PapelMiddleware('cliente')],
        static function (Router $router): void {
            $router->get('/agendamentos', [AgendamentoController::class, 'meus']);
        }
    );
};
