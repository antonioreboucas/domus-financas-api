<?php

// JWT (HS256) minimalista, sem dependencias externas — evita depender de Composer
// em hospedagem compartilhada.
class Jwt
{
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    public static function encode(array $payload, string $secret, int $expiresInSeconds): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiresInSeconds;

        $segments = [
            self::b64url(json_encode($header)),
            self::b64url(json_encode($payload)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::b64url($signature);

        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $signature] = $parts;

        $expectedSignature = self::b64url(hash_hmac('sha256', "$header.$payload", $secret, true));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $decoded = json_decode(self::b64urlDecode($payload), true);
        if (!is_array($decoded) || ($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        return $decoded;
    }
}
