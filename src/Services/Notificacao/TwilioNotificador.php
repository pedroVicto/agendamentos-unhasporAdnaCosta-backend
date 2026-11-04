<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

use App\Core\Env;

// SMS e WhatsApp pela API REST da Twilio (sem SDK, só curl).
final class TwilioNotificador implements Notificador
{
    public function enviarCodigo(string $canal, string $destino, string $codigo, int $validadeMinutos): void
    {
        $texto = "Seu código de acesso é {$codigo}. Vale por {$validadeMinutos} minutos.";

        if ($canal === 'whatsapp') {
            $de = Env::obrigatoria('TWILIO_FROM_WHATSAPP');
            $campos = [
                'To' => 'whatsapp:' . $destino,
                'From' => 'whatsapp:' . $de,
            ];

            // Mensagem iniciada pela empresa (é o nosso caso: o cliente não
            // escreveu primeiro) só é entregue com template aprovado. Sem
            // template, a Twilio recusa com 21654 "ContentSid Required".
            $template = Env::get('TWILIO_WHATSAPP_CONTENT_SID');
            if ($template !== null) {
                $campos['ContentSid'] = $template;
                $campos['ContentVariables'] = json_encode(['1' => $codigo]);
            } else {
                // Sem template, texto livre só passa dentro da janela de 24h
                // aberta por uma mensagem do próprio cliente (no sandbox, o
                // "join"). Fora dela a Twilio recusa com 21654.
                $campos['Body'] = $texto;
            }
        } else {
            $campos = [
                'To' => $destino,
                'From' => Env::obrigatoria('TWILIO_FROM_SMS'),
                'Body' => $texto,
            ];
        }

        $this->enviar($campos);
    }

    // Texto livre (avisos de agendamento). Só SMS: no WhatsApp, mensagem
    // iniciada pela empresa exige template aprovado.
    public function enviarMensagem(string $destino, string $texto): void
    {
        $this->enviar([
            'To' => $destino,
            'From' => Env::obrigatoria('TWILIO_FROM_SMS'),
            'Body' => $texto,
        ]);
    }

    private function enviar(array $campos): void
    {
        $sid = Env::obrigatoria('TWILIO_SID');
        $canal = str_starts_with($campos['To'], 'whatsapp:') ? 'whatsapp' : 'sms';

        $curl = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($campos),
            CURLOPT_USERPWD => $sid . ':' . Env::obrigatoria('TWILIO_TOKEN'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $resposta = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $erro = curl_error($curl);
        curl_close($curl);

        if ($resposta === false || $status >= 400) {
            throw new \RuntimeException("Falha ao enviar {$canal} (HTTP {$status}): " . ($erro ?: $resposta));
        }
    }
}
