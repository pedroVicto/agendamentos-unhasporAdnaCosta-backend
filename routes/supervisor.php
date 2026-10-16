<?php

declare(strict_types=1);

use App\Controllers\Agendamentos\AgendamentoController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PapelMiddleware;

// O admin também entra aqui: ele pode tudo que o supervisor pode.
return static function (Router $router): void {
    $router->grupo(
        '/api/supervisor',
        [new AuthMiddleware(), new PapelMiddleware('supervisor', 'admin')],
        static function (Router $router): void {
            $router->get('/agendamentos', [AgendamentoController::class, 'todos']);
        }
    );
};
