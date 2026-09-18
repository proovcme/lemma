<?php

declare(strict_types=1);

namespace App\Services;

final class AuditService
{
    private const MAX_LOG_BYTES = 52428800;

    private const REDACTED_KEYS = [
        '_csrf',
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'hourly_rate',
        'monthly_fot',
        'change_amount',
        'payroll_burden_pct',
        'overhead_pct',
        'DB_PASSWORD',
        'DB_ROOT_PASS',
        'DB_APP_PASS',
    ];

    public static function scheduleHttpMutation(string $method, string $path, string $routePattern, array $params): void
    {
        if (strtoupper($method) !== 'POST') {
            return;
        }

        $startedAt = microtime(true);
        $event = [
            'event' => 'http_mutation',
            'method' => strtoupper($method),
            'path' => $path,
            'route' => $routePattern,
            'route_params' => $params,
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'session' => session_id() ?: null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'post' => self::sanitize($_POST),
        ];

        register_shutdown_function(static function () use ($startedAt, $event): void {
            $event['status'] = http_response_code();
            $event['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $event['fatal'] = [
                    'type' => $error['type'],
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                ];
            }

            self::write($event);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(string $eventName, array $payload = []): void
    {
        self::write([
            'event' => $eventName,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: null,
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'session' => session_id() ?: null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'post' => self::sanitize($_POST),
        ] + $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function sanitize(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            if (in_array($keyString, self::REDACTED_KEYS, true) || str_contains(mb_strtolower($keyString), 'password')) {
                $clean[$keyString] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$keyString] = self::sanitize($value);
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$keyString] = $value;
                continue;
            }

            $text = (string) $value;
            $clean[$keyString] = mb_strlen($text, 'UTF-8') > 500
                ? mb_substr($text, 0, 500, 'UTF-8') . '...'
                : $text;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function write(array $event): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $path = $logDir . '/audit.log';
        if (is_file($path) && (int) @filesize($path) >= self::MAX_LOG_BYTES) {
            @rename($path, $logDir . '/audit-' . date('Ymd-His') . '.log');
        }

        $event = ['ts' => date('c')] + $event;
        @file_put_contents(
            $path,
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
