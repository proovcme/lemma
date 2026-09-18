<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Простой файловый троттлинг попыток входа (без Redis — контур офлайновый).
 * Ключ = IP + логин: блокируется конкретная пара, а не все пользователи с IP.
 *
 * ВАЖНО: сервис fail-open. Любая ошибка ввода-вывода трактуется как «не заблокировано»,
 * чтобы баг троттлинга никогда не запер легитимного пользователя. Это защита от
 * перебора, а не строгий security-барьер.
 */
final class LoginThrottleService
{
    private const MAX_FAILURES = 10;
    private const WINDOW_SECONDS = 900; // 15 минут

    private static function dir(): string
    {
        return BASE_PATH . '/storage/throttle';
    }

    private static function file(string $login): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $key = hash('sha256', $ip . '|' . mb_strtolower(trim($login)));

        return self::dir() . '/' . $key . '.json';
    }

    /** @return int[] unix-таймстемпы неудач в пределах окна */
    private static function recentFailures(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $cutoff = time() - self::WINDOW_SECONDS;
        $recent = [];
        foreach ($data as $ts) {
            $ts = (int) $ts;
            if ($ts >= $cutoff) {
                $recent[] = $ts;
            }
        }

        return $recent;
    }

    /** Превышен ли лимит неудачных попыток для пары IP+логин. */
    public static function tooManyAttempts(string $login): bool
    {
        try {
            return count(self::recentFailures(self::file($login))) >= self::MAX_FAILURES;
        } catch (\Throwable $e) {
            return false; // fail-open
        }
    }

    /** Зафиксировать неудачную попытку входа. */
    public static function registerFailure(string $login): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = self::file($login);
            $recent = self::recentFailures($file);
            $recent[] = time();
            @file_put_contents($file, json_encode($recent), LOCK_EX);
        } catch (\Throwable $e) {
            // fail-open: молча игнорируем
        }
    }

    /** Сбросить счётчик после успешного входа. */
    public static function clear(string $login): void
    {
        try {
            $file = self::file($login);
            if (is_file($file)) {
                @unlink($file);
            }
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
