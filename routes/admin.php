<?php

declare(strict_types=1);

use App\Controllers\Admin\UsuarioController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PapelMiddleware;

return static function (Router $router): void {
    $router->grupo(
        '/api/admin',
        [new AuthMiddleware(), new PapelMiddleware('admin')],
        static function (Router $router): void {
            $router->get('/usuarios', [UsuarioController::class, 'listar']);
            $router->post('/usuarios', [UsuarioController::class, 'criarSupervisor']);
            $router->patch('/usuarios/{id}/ativo', [UsuarioController::class, 'definirAtivo']);
        }
    );
};
