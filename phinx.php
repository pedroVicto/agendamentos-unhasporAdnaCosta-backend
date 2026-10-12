<?php

// Mesmo carregador de .env usado pela aplicação (public/index.php).
require_once __DIR__ . '/src/Core/Env.php';
App\Core\Env::carregar(__DIR__ . '/.env');

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],

    'environments' => [
        'default_migration_table' => 'phinx_migration_log',

        // Rodando o Phinx de DENTRO do container php, o host do
        // banco é "postgres" (nome do serviço no docker-compose),
        // igual usamos no resto da aplicação.
        'default_environment' => 'development',

        'development' => [
            'adapter' => 'pgsql',
            'host' => getenv('DB_HOST') ?: 'postgres',
            'name' => getenv('DB_DATABASE') ?: 'agendamentos',
            'user' => getenv('DB_USERNAME') ?: 'agendamentos_user',
            'pass' => getenv('DB_PASSWORD') ?: '',
            'port' => getenv('DB_PORT') ?: 5432,
            'charset' => 'utf8',
        ],
    ],

    'version_order' => 'creation',
];