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
     *
     * Sem a variável, o valor sai da própria requisição — veja samesite().
     */
    private static function opcoesDoCookie(int $expiraEm): array
    {
        $samesite = self::samesite();

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

    /**
     * COOKIE_SAMESITE manda quando existe. Sem ela, o valor vem da requisição:
     * um cookie entregue em resposta a uma chamada de OUTRO site só volta nas
     * próximas se for "None". Com "Lax" o navegador guarda e nunca mais envia,
     * e aí o login responde 200 e toda rota autenticada responde 401 logo
     * depois — sem erro nenhum em lugar nenhum, que é o pior tipo de falha.
     *
     * A origem já passou pelo CorsMiddleware antes de chegar aqui, então nunca
     * é um site qualquer: ou está em FRONTEND_URL, ou é o desenvolvimento local.
     */
    private static function samesite(): string
    {
        $escolhido = Env::get('COOKIE_SAMESITE');

        if ($escolhido !== null) {
            $valor = ucfirst(strtolower($escolhido));

            return in_array($valor, ['Lax', 'None', 'Strict'], true) ? $valor : 'Lax';
        }

        return self::deOutroSite() ? 'None' : 'Lax';
    }

    /** O Origin da requisição aponta para um host diferente do da API? */
    private static function deOutroSite(): bool
    {
        $origem = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origem) || $origem === '') {
            return false;
        }

        $hostDaOrigem = parse_url($origem, PHP_URL_HOST);
        if (!is_string($hostDaOrigem) || $hostDaOrigem === '') {
            return false;
        }

        // A porta não entra: para o cookie o que separa um site do outro é o host.
        $hostDaApi = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));

        return strcasecmp($hostDaOrigem, (string) $hostDaApi) !== 0;
    }
}
