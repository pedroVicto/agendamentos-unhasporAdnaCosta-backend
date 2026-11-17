<?php

declare(strict_types=1);

use App\Controllers\Agenda\HorarioController;
use App\Controllers\Auth\AuthClienteController;
use App\Controllers\Auth\AuthStaffController;
use App\Controllers\SaudeController;
use App\Controllers\Servicos\CategoriaController;
use App\Controllers\Servicos\ServicoController;
use App\Core\Router;

// Rotas sem login: qualquer visitante acessa. "Público" não é um papel,
// é só a ausência do AuthMiddleware.
return static function (Router $router): void {
    $router->grupo('/api', [], static function (Router $router): void {
        // Diagnóstico de deploy: responde se a aplicação alcança o banco e se
        // as migrações rodaram, sem expor host, usuário nem senha.
        $router->get('/saude', [SaudeController::class, 'verificar']);

        $router->get('/servicos', [ServicoController::class, 'listar']);
        $router->get('/servicos/{id}', [ServicoController::class, 'buscar']);
        // Ver horários livres não exige login; pedir um horário, sim (routes/cliente.php).
        $router->get('/servicos/{id}/horarios', [HorarioController::class, 'listar']);

        // É com elas que o cliente monta os filtros do catálogo.
        $router->get('/categorias', [CategoriaController::class, 'listar']);

        // Cliente: código de 6 dígitos
        $router->post('/auth/cliente/codigo', [AuthClienteController::class, 'solicitarCodigo']);
        $router->post('/auth/cliente/verificar', [AuthClienteController::class, 'verificar']);

        // Supervisor e admin: e-mail + senha
        $router->post('/auth/staff/login', [AuthStaffController::class, 'login']);
    });
};
