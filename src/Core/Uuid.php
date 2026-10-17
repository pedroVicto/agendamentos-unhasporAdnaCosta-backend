<?php

declare(strict_types=1);

namespace App\Core;

final class Uuid
{
    // Sem essa checagem, um id como "abc" faria o Postgres lançar erro (500).
    public static function valido(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }
}
