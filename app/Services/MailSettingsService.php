<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class MailSettingsService
{
    /**
     * @return array<string,mixed>
     */
    public static function current(): array
    {
        $env = self::envDefaults();
        $stored = self::stored();

        return [
            'enabled' => array_key_exists('enabled', $stored) ? (bool) $stored['enabled'] : (bool) $env['enabled'],
            'host' => (string) ($stored['host'] ?? $env['host']),
            'port' => (int) ($stored['port'] ?? $env['port']),
            'username' => (string) ($stored['username'] ?? $env['username']),
            'password' => array_key_exists('password', $stored)
                ? (string) $stored['password']
                : (string) $env['password'],
            'from_email' => (string) ($stored['from_email'] ?? $env['from_email']),
            'from_name' => (string) ($stored['from_name'] ?? $env['from_name']),
            'encryption' => (string) ($stored['encryption'] ?? $env['encryption']),
            'timeout' => (int) ($stored['timeout'] ?? $env['timeout']),
            'source' => $stored === [] ? 'env' : 'db',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function save(array $payload, int $updatedBy): void
    {
        self::ensureTable();
        $encryption = in_array((string) ($payload['encryption'] ?? 'ssl'), ['ssl', 'tls', 'none'], true)
            ? (string) $payload['encryption']
            : 'ssl';
        if ($encryption === 'none' && in_array((string) config('app.env'), ['production', 'prod'], true)) {
            throw new \InvalidArgumentException('В production SMTP без шифрования запрещён.');
        }

        $submittedPassword = (string) ($payload['password'] ?? '');
        $storedRaw = self::storedRaw();
        $passwordForStorage = '';
        if ($submittedPassword !== '') {
            $passwordForStorage = SecretEncryptionService::encrypt($submittedPassword);
        } elseif ((string) ($storedRaw['password'] ?? '') !== '') {
            $existing = (string) $storedRaw['password'];
            $passwordForStorage = SecretEncryptionService::isEncrypted($existing)
                ? $existing
                : SecretEncryptionService::encrypt($existing);
        }

        $settings = [
            'enabled' => !empty($payload['enabled']) ? '1' : '0',
            'host' => trim((string) ($payload['host'] ?? '')),
            'port' => (string) max(1, min(65535, (int) ($payload['port'] ?? 465))),
            'username' => trim((string) ($payload['username'] ?? '')),
            'from_email' => trim((string) ($payload['from_email'] ?? '')),
            'from_name' => trim((string) ($payload['from_name'] ?? 'Лемма')),
            'encryption' => $encryption,
            'timeout' => (string) max(5, min(120, (int) ($payload['timeout'] ?? 20))),
        ];
        if ($passwordForStorage !== '') {
            $settings['password'] = $passwordForStorage;
        }

        if ($settings['enabled'] === '1') {
            if ($settings['host'] === '' || $settings['from_email'] === '') {
                throw new \InvalidArgumentException('Для включения почты нужны SMTP-хост и email отправителя.');
            }
            if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Email отправителя указан неверно.');
            }
        }

        $pdo = Database::pdo();
        $driver = Database::driver();
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare('
                INSERT INTO mail_settings (setting_key, setting_value, updated_by, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(setting_key) DO UPDATE SET
                    setting_value = excluded.setting_value,
                    updated_by = excluded.updated_by,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO mail_settings (setting_key, setting_value, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ');
        }

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value, $updatedBy]);
        }
    }

    public static function isEnabled(): bool
    {
        $settings = self::current();

        return (bool) $settings['enabled']
            && trim((string) $settings['host']) !== ''
            && trim((string) $settings['from_email']) !== '';
    }

    /**
     * @return array{pending:int,failed:int,sent:int}
     */
    public static function outboxCounters(): array
    {
        try {
            $rows = Database::pdo()->query('
                SELECT status, COUNT(*) AS cnt
                FROM notification_outbox
                GROUP BY status
            ')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return ['pending' => 0, 'failed' => 0, 'sent' => 0];
        }

        $result = ['pending' => 0, 'failed' => 0, 'sent' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $result)) {
                $result[$status] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private static function envDefaults(): array
    {
        return [
            'enabled' => (bool) config('mail.enabled', false),
            'host' => (string) config('mail.host', ''),
            'port' => (int) config('mail.port', 465),
            'username' => (string) config('mail.username', ''),
            'password' => (string) config('mail.password', ''),
            'from_email' => (string) config('mail.from_email', ''),
            'from_name' => (string) config('mail.from_name', 'Лемма'),
            'encryption' => (string) config('mail.encryption', 'ssl'),
            'timeout' => (int) config('mail.timeout', 20),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function stored(): array
    {
        $rows = self::storedRaw();
        if (!$rows) {
            return [];
        }

        $settings = [
            'enabled' => ((string) ($rows['enabled'] ?? '0')) === '1',
            'host' => (string) ($rows['host'] ?? ''),
            'port' => (int) ($rows['port'] ?? 465),
            'username' => (string) ($rows['username'] ?? ''),
            'from_email' => (string) ($rows['from_email'] ?? ''),
            'from_name' => (string) ($rows['from_name'] ?? 'Лемма'),
            'encryption' => (string) ($rows['encryption'] ?? 'ssl'),
            'timeout' => (int) ($rows['timeout'] ?? 20),
        ];
        if (array_key_exists('password', $rows)) {
            $settings['password'] = SecretEncryptionService::decrypt((string) $rows['password']);
        }

        return $settings;
    }

    /**
     * @return array<string,string>
     */
    private static function storedRaw(): array
    {
        try {
            self::ensureTable();
            $rows = Database::pdo()->query('SELECT setting_key, setting_value FROM mail_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? array_map(static fn ($value): string => (string) $value, $rows) : [];
    }

    private static function ensureTable(): void
    {
        $pdo = Database::pdo();
        if (Database::driver() === 'sqlite') {
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS mail_settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT,
                    updated_by INTEGER,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ');
            return;
        }

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS mail_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_by BIGINT UNSIGNED NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mail_settings_updated_by (updated_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
