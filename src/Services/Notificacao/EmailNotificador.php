<?php

declare(strict_types=1);

namespace App\Services\Notificacao;

use App\Core\Env;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailNotificador implements Notificador
{
    public function enviarCodigo(string $canal, string $destino, string $codigo, int $validadeMinutos): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = Env::obrigatoria('MAIL_HOST');
            $mail->Port = (int) Env::get('MAIL_PORT', '587');
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

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
            $mail->Subject = "Seu código de acesso: {$codigo}";
            $mail->Body = "Seu código de acesso é {$codigo}.\n\n"
                . "Ele vale por {$validadeMinutos} minutos. Se você não pediu este código, ignore este e-mail.";

            $mail->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Falha ao enviar e-mail: ' . $e->getMessage(), 0, $e);
        }
    }
}
