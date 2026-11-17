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
            setcookie($nome, $valor, self::opcoesDoCookie($expiraEm));
        }

        if ($this->dados !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * SameSite decide se o navegador manda o cookie de sessão quando o
     * front-end está em outro domínio que a API.
     *
     *  - "Lax" (padrão): front e API no MESMO site registrável
     *    (seudominio.com + seudominio.com/api, ou api.seudominio.com).
     *  - "None": front em outro domínio (Vercel + Railway, por exemplo).
     *    Nesse caso o navegador EXIGE o atributo Secure junto — sem ele o
     *    Set-Cookie é descartado calado, e todo login falha com 401 sem
     *    nenhum erro visível. Por isso o Secure é forçado aqui.
     *
     * Com "None" ainda é preciso que o front mande as requisições com
     * credenciais (fetch: credentials: 'include') e que FRONTEND_URL
     * libere a origem exata no CORS.
     */
    private static function opcoesDoCookie(int $expiraEm): array
    {
        $samesite = ucfirst(strtolower(Env::get('COOKIE_SAMESITE', 'Lax') ?? 'Lax'));
        if (!in_array($samesite, ['Lax', 'None', 'Strict'], true)) {
            $samesite = 'Lax';
        }

        $local = Env::get('APP_ENV', 'production') === 'local';

        return [
            'expires' => $expiraEm,
            'path' => '/',
            // HttpOnly: o JavaScript da página não consegue ler o token.
            'httponly' => true,
            // Em produção o cookie só trafega por HTTPS; com SameSite=None
            // o Secure não é opcional nem em desenvolvimento.
            'secure' => !$local || $samesite === 'None',
            'samesite' => $samesite,
        ];
    }
}
