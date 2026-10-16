<?php

declare(strict_types=1);

use App\Controllers\Secao\SessaoController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;

// Rotas para qualquer usuário logado, independente do papel.
return static function (Router $router): void {
    $router->grupo('/api/auth', [new AuthMiddleware()], static function (Router $router): void {
        $router->get('/me', [SessaoController::class, 'me']);
        $router->post('/logout', [SessaoController::class, 'logout']);
        $router->post('/senha', [SessaoController::class, 'trocarSenha']);
    });
};
