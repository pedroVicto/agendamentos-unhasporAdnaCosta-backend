<?php

declare(strict_types=1);

use App\Controllers\Agenda\BloqueioController;
use App\Controllers\Agenda\ExpedienteController;
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
            $router->patch('/agendamentos/{id}/confirmar', [AgendamentoController::class, 'confirmar']);
            $router->patch('/agendamentos/{id}/recusar', [AgendamentoController::class, 'recusar']);
            $router->patch('/agendamentos/{id}/cancelar', [AgendamentoController::class, 'cancelarPeloSupervisor']);

            $router->get('/expediente', [ExpedienteController::class, 'listar']);
            $router->put('/expediente', [ExpedienteController::class, 'substituir']);

            $router->get('/bloqueios', [BloqueioController::class, 'listar']);
            $router->post('/bloqueios', [BloqueioController::class, 'criar']);
            $router->delete('/bloqueios/{id}', [BloqueioController::class, 'remover']);
        }
    );
};
