<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;

final class Router
{
    private array $rotas = [];
    private string $prefixo = '';

    /** @var Middleware[] */
    private array $middlewares = [];

    public function get(string $caminho, array $acao): void
    {
        $this->adicionar('GET', $caminho, $acao);
    }

    public function post(string $caminho, array $acao): void
    {
        $this->adicionar('POST', $caminho, $acao);
    }

    public function put(string $caminho, array $acao): void
    {
        $this->adicionar('PUT', $caminho, $acao);
    }

    public function patch(string $caminho, array $acao): void
    {
        $this->adicionar('PATCH', $caminho, $acao);
    }

    public function delete(string $caminho, array $acao): void
    {
        $this->adicionar('DELETE', $caminho, $acao);
    }

    /**
     * Todas as rotas definidas dentro de $definir herdam o prefixo e os
     * middlewares do grupo. É aqui que cada área (cliente, supervisor,
     * admin) ganha a sua proteção de uma vez só.
     *
     * @param Middleware[] $middlewares
     */
    public function grupo(string $prefixo, array $middlewares, callable $definir): void
    {
        $prefixoAnterior = $this->prefixo;
        $middlewaresAnteriores = $this->middlewares;

        $this->prefixo .= $prefixo;
        $this->middlewares = [...$this->middlewares, ...$middlewares];

        $definir($this);

        $this->prefixo = $prefixoAnterior;
        $this->middlewares = $middlewaresAnteriores;
    }

    public function despachar(Request $request): Response
    {
        $caminhoExiste = false;

        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['regex'], $request->caminho, $encontrados)) {
                continue;
            }
            $caminhoExiste = true;

            if ($rota['metodo'] !== $request->metodo) {
                continue;
            }

            $request->params = array_filter($encontrados, 'is_string', ARRAY_FILTER_USE_KEY);

            // Monta a "cebola": cada middleware decide se chama o próximo.
            $proximo = static function (Request $r) use ($rota): Response {
                [$classe, $metodo] = $rota['acao'];

                return (new $classe())->{$metodo}($r);
            };
            foreach (array_reverse($rota['middlewares']) as $middleware) {
                $proximo = static fn (Request $r): Response => $middleware->processar($r, $proximo);
            }

            return $proximo($request);
        }

        return $caminhoExiste
            ? Response::erro('Método não permitido.', 405)
            : Response::erro('Rota não encontrada.', 404);
    }

    private function adicionar(string $metodo, string $caminho, array $acao): void
    {
        $completo = rtrim($this->prefixo . $caminho, '/') ?: '/';
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $completo);

        $this->rotas[] = [
            'metodo' => $metodo,
            'regex' => '#^' . $regex . '$#',
            'acao' => $acao,
            'middlewares' => $this->middlewares,
        ];
    }
}
