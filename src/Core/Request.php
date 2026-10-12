<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** Parâmetros da rota, ex.: /usuarios/{id} */
    public array $params = [];

    /** Preenchido pelo AuthMiddleware: id, nome, papel. */
    public ?array $usuario = null;

    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query,
        public readonly array $corpo,
        private readonly array $cookies,
        private readonly array $servidor,
    ) {
    }

    public static function capturar(): self
    {
        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $caminho = rtrim($caminho, '/') ?: '/';

        // A API só aceita JSON: um <form> de outro site não consegue montar esse corpo.
        $corpo = json_decode(file_get_contents('php://input') ?: '', true);

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $caminho,
            $_GET,
            is_array($corpo) ? $corpo : [],
            $_COOKIE,
            $_SERVER,
        );
    }

    public function input(string $chave): ?string
    {
        $valor = $this->corpo[$chave] ?? null;
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    public function cookie(string $nome): ?string
    {
        $valor = $this->cookies[$nome] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    public function header(string $nome): ?string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));

        return isset($this->servidor[$chave]) ? (string) $this->servidor[$chave] : null;
    }

    public function ip(): string
    {
        return (string) ($this->servidor['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->servidor['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
