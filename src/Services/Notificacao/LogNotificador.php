<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

// Só para desenvolvimento: veja o código com `docker compose logs -f php`.
final class LogNotificador implements Notificador
{
    public function enviarCodigo(string $canal, string $destino, string $codigo, int $validadeMinutos): void
    {
        error_log("[codigo de verificacao] canal={$canal} destino={$destino} codigo={$codigo}");
    }
}
