<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    // Carrega as variáveis do .env manualmente (sem depender de framework).
    public static function carregar(string $caminho): void
    {
        if (!file_exists($caminho)) {
            return;
        }

        foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            if (str_starts_with(trim($linha), '#') || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            putenv(trim($chave) . '=' . trim(trim($valor), "\"'"));
        }
    }

    public static function get(string $chave, ?string $padrao = null): ?string
    {
        $valor = getenv($chave);

        return ($valor === false || $valor === '') ? $padrao : $valor;
    }

    // Para variáveis sem as quais a aplicação não pode rodar com segurança.
    public static function obrigatoria(string $chave): string
    {
        $valor = self::get($chave);
        if ($valor === null) {
            throw new \RuntimeException("Variável de ambiente {$chave} não definida.");
        }

        return $valor;
    }
}
