<?php

declare(strict_types=1);

namespace App\Services;

// Normaliza e-mail e telefone para que o mesmo contato sempre caia
// na mesma linha de "usuarios", não importa como foi digitado.
final class Contato
{
    public static function email(?string $valor): ?string
    {
        $email = mb_strtolower(trim((string) $valor));

        return (strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    /**
     * Devolve o telefone em E.164 (+5511999998888). Números sem código
     * de país são tratados como brasileiros.
     */
    public static function telefone(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        $digitos = preg_replace('/\D/', '', $valor);

        if (str_starts_with($valor, '+')) {
            return preg_match('/^\d{8,15}$/', $digitos) ? '+' . $digitos : null;
        }

        // DDD + número (fixo com 8 dígitos ou celular com 9)
        if (preg_match('/^\d{10,11}$/', $digitos)) {
            return '+55' . $digitos;
        }

        if (preg_match('/^55\d{10,11}$/', $digitos)) {
            return '+' . $digitos;
        }

        return null;
    }
}
