<?php

/**
 * Arquivo temporário só para validar o setup do Docker.
 * Depois que confirmar que tudo funciona, este arquivo deve
 * virar o front controller "de verdade" da aplicação
 * (ou ser substituído por um roteador em src/core).
 */

header('Content-Type: text/plain; charset=utf-8');

echo "PHP está rodando via Nginx + PHP-FPM.\n\n";

echo "Extensão pdo_pgsql carregada? ";
echo extension_loaded('pdo_pgsql') ? "SIM\n" : "NÃO\n";

// Carrega as variáveis de ambiente definidas no docker-compose (env_file: .env)
$host     = getenv('DB_HOST');
$port     = getenv('DB_PORT');
$database = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

echo "\nTentando conectar ao PostgreSQL em {$host}:{$port}...\n";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $versao = $pdo->query('SELECT version()')->fetchColumn();

    echo "Conexão com o banco: OK\n";
    echo "Versão do Postgres: {$versao}\n";
} catch (PDOException $e) {
    echo "Falha ao conectar no banco: " . $e->getMessage() . "\n";
}