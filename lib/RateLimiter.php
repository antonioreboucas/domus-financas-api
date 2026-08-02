<?php

// Limitador de tentativas de login. Sem Redis/Memcached (hospedagem
// compartilhada) e sem tabela nova no banco (evita mais uma migração manual
// — ver CLAUDE.md), guarda os timestamps das tentativas recentes num
// arquivo JSON por chave (IP + email) dentro de storage/rate_limit/, que
// tem .htaccess negando qualquer acesso HTTP.
class RateLimiter
{
    private const WINDOW_SECONDS = 15 * 60;
    private const MAX_ATTEMPTS = 5;

    private static function dir(): string
    {
        $dir = __DIR__ . '/../storage/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function fileFor(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    private static function readAttempts(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function recentAttempts(string $key): array
    {
        $attempts = self::readAttempts(self::fileFor($key));
        $cutoff = time() - self::WINDOW_SECONDS;
        return array_values(array_filter($attempts, fn($t) => $t > $cutoff));
    }

    // [bloqueado, segundosParaTentarDeNovo]
    public static function check(string $key): array
    {
        $attempts = self::recentAttempts($key);
        if (count($attempts) < self::MAX_ATTEMPTS) {
            return [false, 0];
        }
        $retryAfter = self::WINDOW_SECONDS - (time() - min($attempts));
        return [true, max($retryAfter, 1)];
    }

    public static function registerFailure(string $key): void
    {
        $attempts = self::recentAttempts($key);
        $attempts[] = time();
        @file_put_contents(self::fileFor($key), json_encode($attempts), LOCK_EX);
    }

    public static function clear(string $key): void
    {
        $file = self::fileFor($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
