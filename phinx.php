<?php

// Carrega as variáveis do .env manualmente (sem depender de framework).
// Se você instalar vlucas/phpdotenv depois, pode trocar por ele.
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

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