<?php

declare(strict_types=1);

use App\Controllers\Admin\UsuarioController;
use App\Controllers\Servicos\ServicoController;
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

    // Serviços: a leitura é pública (routes/publico.php), a escrita é só do admin.
    $router->grupo(
        '/api/servicos',
        [new AuthMiddleware(), new PapelMiddleware('admin')],
        static function (Router $router): void {
            $router->post('', [ServicoController::class, 'criar']);
            $router->put('/{id}', [ServicoController::class, 'atualizar']);
            $router->delete('/{id}', [ServicoController::class, 'remover']);
        }
    );
};
