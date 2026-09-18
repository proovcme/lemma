<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

final class IncidentLogService
{
    private const MAX_LOG_BYTES = 10 * 1024 * 1024;

    /** @param array<string,mixed> $context */
    public static function report(Throwable|string $error, array $context = []): string
    {
        $incidentId = 'ERR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $throwable = $error instanceof Throwable ? $error : null;
        $event = [
            'ts' => date(DATE_ATOM),
            'incident_id' => $incidentId,
            'level' => 'error',
            'method' => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : null),
            'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: null,
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'type' => $throwable ? get_class($throwable) : 'RuntimeError',
            'message' => $throwable ? $throwable->getMessage() : (string) $error,
            'file' => $throwable ? $throwable->getFile() : null,
            'line' => $throwable ? $throwable->getLine() : null,
            'trace' => $throwable ? $throwable->getTraceAsString() : null,
            'context' => self::sanitize($context),
        ];

        $logDir = BASE_PATH . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        $logFile = $logDir . '/app-error.log';
        if (is_file($logFile) && (int) @filesize($logFile) >= self::MAX_LOG_BYTES) {
            @rename($logFile, $logDir . '/app-error-' . date('Ymd-His') . '.log');
        }
        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false || @file_put_contents($logFile, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($incidentId . ' ' . $event['type'] . ': ' . $event['message']);
        }

        return $incidentId;
    }

    public static function userMessage(string $incidentId, string $action = 'выполнить действие'): string
    {
        return 'Не удалось ' . $action . '. Повторите попытку. Если проблема повторится, сообщите администратору код ' . $incidentId . '.';
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function sanitize(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $name = (string) $key;
            if (str_contains(mb_strtolower($name, 'UTF-8'), 'password') || str_contains(mb_strtolower($name, 'UTF-8'), 'token') || $name === '_csrf') {
                $safe[$name] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$name] = is_string($value) ? mb_substr($value, 0, 500, 'UTF-8') : $value;
            } else {
                $safe[$name] = '[complex]';
            }
        }

        return $safe;
    }
}
