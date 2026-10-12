<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

interface Notificador
{
    /**
     * @param string $canal 'email', 'sms' ou 'whatsapp'
     * @throws \RuntimeException se o envio falhar
     */
    public function enviarCodigo(string $canal, string $destino, string $codigo, int $validadeMinutos): void;
}
