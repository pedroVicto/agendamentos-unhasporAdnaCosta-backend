<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

final class CorsMiddleware implements Middleware
{
    public function processar(Request $request, callable $proximo): Response
    {
        $origem = $request->header('Origin');
        $permitida = $origem !== null && self::liberada($origem) ? $origem : null;

        // Preflight: o navegador pergunta antes de mandar a requisição de verdade.
        $response = ($request->metodo === 'OPTIONS' && $origem !== null)
            ? Response::vazia()
            : $proximo($request);

        // Sempre, mesmo sem liberar: a resposta muda conforme o Origin, e sem
        // o Vary um cache (CDN, proxy) serviria a um site a resposta que
        // liberou outro.
        $response->comHeader('Vary', 'Origin');

        if ($permitida !== null) {
            // Com cookies a origem tem que ser exata; "*" não é aceito pelo navegador.
            $response
                ->comHeader('Access-Control-Allow-Origin', $permitida)
                ->comHeader('Access-Control-Allow-Credentials', 'true')
                ->comHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->comHeader('Access-Control-Allow-Headers', 'Content-Type')
                // Evita um preflight por requisição durante a navegação.
                ->comHeader('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    /**
     * Enquanto FRONTEND_URL estiver vazia, o desenvolvimento local continua
     * funcionando: localhost e 127.0.0.1, em qualquer porta. É uma tolerância
     * estreita e temporária — preencher FRONTEND_URL a desliga sozinha, porque
     * a partir daí só a lista vale.
     *
     * O que ela custa: uma página servida na máquina de quem acessa (localhost
     * dela, não deste servidor) consegue chamar a API com o cookie de sessão
     * junto. Exige código já rodando no computador da vítima, mas é motivo de
     * sobra para cadastrar FRONTEND_URL assim que os fronts forem publicados.
     */
    private const ORIGEM_LOCAL = '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#';

    private static function liberada(string $origem): bool
    {
        $lista = self::origensPermitidas();

        if ($lista !== []) {
            return in_array($origem, $lista, true);
        }

        return preg_match(self::ORIGEM_LOCAL, $origem) === 1;
    }

    /**
     * As origens liberadas, de FRONTEND_URL. São duas aplicações de front — a
     * do cliente e a da equipe — em endereços diferentes, então a variável
     * aceita uma lista separada por vírgula:
     *
     *   FRONTEND_URL=https://cliente.exemplo.com,https://equipe.exemplo.com
     *
     * Uma origem é esquema + host + porta, sem caminho nem barra no fim (é
     * assim que o navegador manda no header Origin); a barra extra é tolerada
     * aqui para um valor digitado à mão não derrubar o CORS calado.
     *
     * @return list<string>
     */
    private static function origensPermitidas(): array
    {
        $bruto = Env::get('FRONTEND_URL', '') ?? '';

        $origens = array_map(
            static fn (string $origem): string => rtrim(trim($origem), '/'),
            explode(',', $bruto)
        );

        return array_values(array_filter($origens, static fn (string $o): bool => $o !== ''));
    }
}
