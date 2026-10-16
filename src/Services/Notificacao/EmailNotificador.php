<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

final class EmailNotificador implements Notificador
{
    public function enviarCodigo(string $canal, string $destino, string $codigo, int $validadeMinutos): void
    {
        Email::enviar(
            $destino,
            "Seu código de acesso: {$codigo}",
            "Seu código de acesso é {$codigo}.\n\n"
                . "Ele vale por {$validadeMinutos} minutos. Se você não pediu este código, ignore este e-mail."
        );
    }
}
