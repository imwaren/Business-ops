<?php

namespace App\Libraries;

use PHPMailer\PHPMailer\PHPMailer;

/** Same Office365 transport as send_email_to_many, with an explicit delivery outcome. */
class Approval_reminder_mailer
{
    public function send(string $subject, string $body, array $to, array $cc): array
    {
        if (!OUTLOOK_EMAIL || !OUTLOOK_EMAIL_PASSWORD) {
            return ['status' => 'failed', 'error' => 'Notification SMTP configuration is missing.'];
        }
        $mail = new PHPMailer(true);
        $accepted = false;
        $mail->action_function = static function ($isSent) use (&$accepted): void {
            $accepted = $accepted || $isSent;
        };
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.office365.com';
            $mail->SMTPAuth = true;
            $mail->Username = OUTLOOK_EMAIL;
            $mail->Password = OUTLOOK_EMAIL_PASSWORD;
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(OUTLOOK_EMAIL, 'CLINICA PRIME - BUSINESS OPERATIONS');
            foreach ($to as $email) {
                $mail->addAddress($email);
            }
            foreach ($cc as $email) {
                $mail->addCC($email);
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return ['status' => 'sent', 'error' => null];
        } catch (\Throwable $e) {
            $smtp = $mail->getSMTPInstance()->getError();
            $code = (int) ($smtp['smtp_code'] ?? 0);
            // A server rejection is explicit. Timeout/disconnection may follow acceptance.
            return $code >= 400 && !$accepted
                ? ['status' => 'failed', 'error' => 'SMTP rejected the message (' . $code . ').']
                : ['status' => 'uncertain', 'error' => 'SMTP outcome requires review before retrying.'];
        } finally {
            $mail->smtpClose();
        }
    }
}
