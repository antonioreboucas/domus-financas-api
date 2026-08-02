<?php

// Envio de email via API transacional da Brevo (https://www.brevo.com).
// Usa cURL puro para não depender de Composer no hosting compartilhado.
class Mailer
{
    public static function send(array $config, string $to, string $subject, string $htmlBody): bool
    {
        if (empty($config['brevo_api_key'])) {
            return false;
        }

        $payload = json_encode([
            'sender' => ['name' => $config['mail_from_name'], 'email' => $config['mail_from_email']],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $htmlBody,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $config['brevo_api_key'],
                'content-type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 300;
    }
}
