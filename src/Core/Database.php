<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function conexao(): PDO
    {
        if (self::$pdo === null) {
            [$dsn, $usuario, $senha] = self::credenciais();

            self::$pdo = new PDO($dsn, $usuario, $senha, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * Duas formas de dizer onde está o banco:
     *
     *  - DATABASE_URL, uma URL só (postgres://usuario:senha@host:porta/banco).
     *    É o que Render, Heroku, Fly e Neon injetam sozinhos quando o banco é
     *    ligado ao serviço — por isso ela vem primeiro: sem isso o deploy
     *    quebra com "Variável de ambiente DB_HOST não definida" mesmo com o
     *    banco ligado, porque a plataforma nunca cria as DB_* separadas.
     *  - DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME e DB_PASSWORD, que é o
     *    formato do docker-compose de desenvolvimento.
     *
     * @return array{0: string, 1: string, 2: string} dsn, usuário e senha
     */
    private static function credenciais(): array
    {
        $url = Env::get('DATABASE_URL');

        if ($url === null) {
            return [
                self::dsn(
                    Env::obrigatoria('DB_HOST'),
                    Env::get('DB_PORT', '5432') ?? '5432',
                    Env::obrigatoria('DB_DATABASE')
                ),
                Env::obrigatoria('DB_USERNAME'),
                Env::get('DB_PASSWORD', '') ?? '',
            ];
        }

        $partes = parse_url($url);
        $banco = is_array($partes) ? trim($partes['path'] ?? '', '/') : '';

        if (!is_array($partes) || !isset($partes['host'], $partes['user']) || $banco === '') {
            throw new RuntimeException(
                'DATABASE_URL precisa ser postgres://usuario:senha@host:porta/banco.'
            );
        }

        $dsn = self::dsn($partes['host'], (string) ($partes['port'] ?? '5432'), $banco);

        // Bancos gerenciados exigem TLS e põem o sslmode na query da URL
        // (?sslmode=require). O PDO entende a opção no DSN.
        parse_str($partes['query'] ?? '', $query);
        if (isset($query['sslmode']) && is_string($query['sslmode']) && $query['sslmode'] !== '') {
            $dsn .= ';sslmode=' . $query['sslmode'];
        }

        // Usuário e senha vêm percent-encoded na URL: uma senha com "@" ou "/"
        // só volta inteira depois do decode.
        return [$dsn, rawurldecode($partes['user']), rawurldecode($partes['pass'] ?? '')];
    }

    private static function dsn(string $host, string $porta, string $banco): string
    {
        return sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $porta, $banco);
    }
}
