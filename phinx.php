<?php

// Mesmo carregador de .env usado pela aplicação (public/index.php).
require_once __DIR__ . '/src/Core/Env.php';
App\Core\Env::carregar(__DIR__ . '/.env');

// O mesmo DATABASE_URL que o App\Core\Database aceita (Render, Heroku, Fly e
// Neon injetam essa URL sozinhos). Sem isto, RUN_MIGRATIONS=1 falharia no
// servidor mesmo com a aplicação conseguindo falar com o banco.
$daUrl = static function (): array {
    $url = getenv('DATABASE_URL');
    if (!is_string($url) || $url === '') {
        return [];
    }

    $partes = parse_url($url);
    if (!is_array($partes) || !isset($partes['host'])) {
        return [];
    }

    return [
        'host' => $partes['host'],
        'name' => trim($partes['path'] ?? '', '/'),
        // Percent-encoded na URL: uma senha com "@" ou "/" só volta
        // inteira depois do decode.
        'user' => rawurldecode($partes['user'] ?? ''),
        'pass' => rawurldecode($partes['pass'] ?? ''),
        'port' => $partes['port'] ?? 5432,
    ];
};

$url = $daUrl();

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
            'host' => $url['host'] ?? (getenv('DB_HOST') ?: 'postgres'),
            'name' => $url['name'] ?? (getenv('DB_DATABASE') ?: 'agendamentos'),
            'user' => $url['user'] ?? (getenv('DB_USERNAME') ?: 'agendamentos_user'),
            'pass' => $url['pass'] ?? (getenv('DB_PASSWORD') ?: ''),
            'port' => $url['port'] ?? (getenv('DB_PORT') ?: 5432),
            'charset' => 'utf8',
        ],
    ],

    'version_order' => 'creation',
];
