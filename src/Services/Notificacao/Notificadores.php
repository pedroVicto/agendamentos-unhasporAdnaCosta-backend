<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

use App\Core\Env;

final class Notificadores
{
    // Para trocar de provedor (Z-API, Meta Cloud API...), basta criar outra
    // classe que implemente Notificador e devolvê-la aqui.
    public static function para(string $canal): Notificador
    {
        // NOTIFICADOR=log: nada sai de verdade, o código vai para o log do container.
        if (Env::get('NOTIFICADOR', 'real') === 'log') {
            return new LogNotificador();
        }

        if ($canal === 'email') {
            return new EmailNotificador();
        }

        if (Env::get('TWILIO_SID') === null) {
            if (Env::get('APP_ENV', 'production') === 'local') {
                return new LogNotificador();
            }
            throw new \RuntimeException('Envio por SMS/WhatsApp não configurado (TWILIO_*).');
        }

        return new TwilioNotificador();
    }
}
