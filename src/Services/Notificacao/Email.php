<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

use App\Core\Env;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

// Envio de e-mail em texto puro pelo SMTP do .env.
final class Email
{
    /**
     * @throws \RuntimeException se o envio falhar
     */
    public static function enviar(string $destino, string $assunto, string $corpo): void
    {
        // NOTIFICADOR=log: nada sai de verdade, o e-mail vai para o log do container.
        if (Env::get('NOTIFICADOR', 'real') === 'log') {
            error_log("[e-mail] para={$destino} assunto={$assunto}\n{$corpo}");

            return;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = Env::obrigatoria('MAIL_HOST');
            $mail->Port = (int) Env::get('MAIL_PORT', '587');
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // Sem isto o PHPMailer espera 300s. Quando a saída SMTP está
            // bloqueada — várias hospedagens barram as portas 25, 465 e 587 —
            // a conexão não é recusada, ela fica pendurada: o nginx corta em 60s
            // e o cliente recebe um 504 em HTML, sem nada no log explicando.
            // Com 10s a falha vira a nossa própria resposta, com o motivo logado.
            $mail->Timeout = (int) Env::get('MAIL_TIMEOUT', '10');

            // tls | ssl | vazio (Mailpit em dev não usa criptografia)
            $mail->SMTPSecure = Env::get('MAIL_ENCRYPTION', '');
            $mail->SMTPAutoTLS = $mail->SMTPSecure !== '';

            if (Env::get('MAIL_USERNAME') !== null) {
                $mail->SMTPAuth = true;
                $mail->Username = Env::get('MAIL_USERNAME');
                $mail->Password = Env::get('MAIL_PASSWORD', '');
            }

            $mail->setFrom(Env::obrigatoria('MAIL_FROM'), Env::get('MAIL_FROM_NAME', 'Agendamentos'));
            $mail->addAddress($destino);
            $mail->Subject = $assunto;
            $mail->Body = $corpo;

            $mail->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Falha ao enviar e-mail: ' . $e->getMessage(), 0, $e);
        }
    }
}
