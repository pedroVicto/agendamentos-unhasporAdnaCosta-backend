<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private array $headers = [];
    private array $cookies = [];

    private function __construct(
        private readonly int $status,
        private readonly ?array $dados,
    ) {
    }

    public static function json(array $dados, int $status = 200): self
    {
        return new self($status, $dados);
    }

    public static function vazia(int $status = 204): self
    {
        return new self($status, null);
    }

    public static function erro(string $mensagem, int $status): self
    {
        return new self($status, ['erro' => $mensagem]);
    }

    public function comHeader(string $nome, string $valor): self
    {
        $this->headers[$nome] = $valor;

        return $this;
    }

    // $expiraEm é um timestamp Unix; um valor no passado apaga o cookie.
    public function comCookie(string $nome, string $valor, int $expiraEm): self
    {
        $this->cookies[$nome] = [$valor, $expiraEm];

        return $this;
    }

    public function enviar(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $nome => $valor) {
            header("{$nome}: {$valor}");
        }

        foreach ($this->cookies as $nome => [$valor, $expiraEm]) {
            setcookie($nome, $valor, [
                'expires' => $expiraEm,
                'path' => '/',
                // HttpOnly: o JavaScript da página não consegue ler o token.
                'httponly' => true,
                // Em produção o cookie só trafega por HTTPS.
                'secure' => Env::get('APP_ENV', 'production') !== 'local',
                'samesite' => 'Lax',
            ]);
        }

        if ($this->dados !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}
