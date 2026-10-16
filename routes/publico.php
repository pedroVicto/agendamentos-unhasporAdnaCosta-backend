<?php

declare(strict_types=1);

use App\Controllers\Auth\AuthClienteController;
use App\Controllers\Auth\AuthStaffController;
use App\Controllers\Servicos\ServicoController;
use App\Core\Router;

// Rotas sem login: qualquer visitante acessa. "Público" não é um papel,
// é só a ausência do AuthMiddleware.
return static function (Router $router): void {
    $router->grupo('/api', [], static function (Router $router): void {
        $router->get('/servicos', [ServicoController::class, 'listar']);

        // Cliente: código de 6 dígitos
        $router->post('/auth/cliente/codigo', [AuthClienteController::class, 'solicitarCodigo']);
        $router->post('/auth/cliente/verificar', [AuthClienteController::class, 'verificar']);

        // Supervisor e admin: e-mail + senha
        $router->post('/auth/staff/login', [AuthStaffController::class, 'login']);
    });
};
