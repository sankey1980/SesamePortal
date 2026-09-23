<?php

declare(strict_types=1);

namespace SesamePortal;

final class Audit
{
    public static function log(string $action, string $details = ''): void
    {
        $user = Auth::user();
        self::logForUser($user['id'] ?? null, $action, $details);
    }

    public static function logForUser(int|string|null $userId, string $action, string $details = ''): void
    {
        DB::pdo()->prepare('INSERT INTO audit_logs(actor_user_id, action, details, created_at) VALUES(?, ?, ?, ?)')
            ->execute([$userId !== null ? (int)$userId : null, $action, $details, Util::now()]);
    }

    public static function clientIp(): string
    {
        $raw = (string)(
            $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );
        $ip = trim(explode(',', $raw, 2)[0]);
        return substr(preg_replace('/[^\w:. -]/', '', $ip) ?: '', 0, 80);
    }

    public static function cleanValue(string $value, int $maxBytes = 160): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }

    public static function fileAppend(string $category, string $line): void
    {
        $category = preg_replace('/[^a-z0-9_.-]+/i', '-', $category) ?: 'portal';
        $dir = rtrim(Config::stateDir(), '/') . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $timezone = (string)Config::get('timezone', 'UTC');
        $dt = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        $path = $dir . '/' . $category . '-' . $dt->format('Y-m-d') . '.log';
        @file_put_contents($path, '[' . $dt->format('c') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);

        $days = max(1, (int)Config::get('log_retention_days', 3));
        $cutoff = time() - $days * 86400;
        foreach ((array)glob($dir . '/' . $category . '-*.log') as $old) {
            if (is_file($old) && filemtime($old) < $cutoff && strpos($old, $dir . '/') === 0) {
                @unlink($old);
            }
        }
    }
}
