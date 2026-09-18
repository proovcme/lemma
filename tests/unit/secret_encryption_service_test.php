<?php

declare(strict_types=1);

use App\Services\SecretEncryptionService;
use App\Services\MailSettingsService;
use App\Core\Database;

test('SecretEncryptionService шифрует и расшифровывает секрет', function (): void {
    $previous = $GLOBALS['config']['security']['data_key'] ?? '';
    $GLOBALS['config']['security']['data_key'] = base64_encode(random_bytes(32));

    try {
        $encrypted = SecretEncryptionService::encrypt('smtp-secret');
        assert_true($encrypted !== 'smtp-secret');
        assert_true(SecretEncryptionService::isEncrypted($encrypted));
        assert_same('smtp-secret', SecretEncryptionService::decrypt($encrypted));
    } finally {
        $GLOBALS['config']['security']['data_key'] = $previous;
    }
});

test('SecretEncryptionService обнаруживает подмену шифротекста', function (): void {
    $previous = $GLOBALS['config']['security']['data_key'] ?? '';
    $GLOBALS['config']['security']['data_key'] = base64_encode(random_bytes(32));

    try {
        $encrypted = SecretEncryptionService::encrypt('smtp-secret');
        $last = substr($encrypted, -1);
        $tampered = substr($encrypted, 0, -1) . ($last === 'A' ? 'B' : 'A');
        assert_throws(static fn () => SecretEncryptionService::decrypt($tampered));
    } finally {
        $GLOBALS['config']['security']['data_key'] = $previous;
    }
});

test('SecretEncryptionService не маскирует отсутствие ключа', function (): void {
    $previous = $GLOBALS['config']['security']['data_key'] ?? '';
    $GLOBALS['config']['security']['data_key'] = '';

    try {
        assert_throws(static fn () => SecretEncryptionService::encrypt('smtp-secret'));
    } finally {
        $GLOBALS['config']['security']['data_key'] = $previous;
    }
});

test('MailSettingsService не сохраняет SMTP-пароль открытым текстом', function (): void {
    $previousKey = $GLOBALS['config']['security']['data_key'] ?? '';
    $previousEnv = $GLOBALS['config']['app']['env'] ?? 'local';
    $previousDriver = $GLOBALS['config']['db']['connection'] ?? 'mysql';
    $GLOBALS['config']['security']['data_key'] = base64_encode(random_bytes(32));
    $GLOBALS['config']['app']['env'] = 'production';
    $GLOBALS['config']['db']['connection'] = 'sqlite';
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Database::useConnection($pdo);

    try {
        $payload = [
            'enabled' => '1',
            'host' => 'smtp.example.test',
            'port' => '465',
            'username' => 'locia@example.test',
            'password' => 'smtp-secret',
            'from_email' => 'locia@example.test',
            'from_name' => 'Лемма',
            'encryption' => 'ssl',
            'timeout' => '20',
        ];
        MailSettingsService::save($payload, 1);
        $stored = (string) $pdo->query("SELECT setting_value FROM mail_settings WHERE setting_key = 'password'")->fetchColumn();
        assert_true(SecretEncryptionService::isEncrypted($stored));
        assert_true(!str_contains($stored, 'smtp-secret'));
        assert_same('smtp-secret', (string) MailSettingsService::current()['password']);

        $payload['password'] = '';
        MailSettingsService::save($payload, 1);
        $preserved = (string) $pdo->query("SELECT setting_value FROM mail_settings WHERE setting_key = 'password'")->fetchColumn();
        assert_same($stored, $preserved);
    } finally {
        Database::reset();
        $GLOBALS['config']['security']['data_key'] = $previousKey;
        $GLOBALS['config']['app']['env'] = $previousEnv;
        $GLOBALS['config']['db']['connection'] = $previousDriver;
    }
});

test('MailSettingsService запрещает открытый SMTP в production', function (): void {
    $previousKey = $GLOBALS['config']['security']['data_key'] ?? '';
    $previousEnv = $GLOBALS['config']['app']['env'] ?? 'local';
    $previousDriver = $GLOBALS['config']['db']['connection'] ?? 'mysql';
    $GLOBALS['config']['security']['data_key'] = base64_encode(random_bytes(32));
    $GLOBALS['config']['app']['env'] = 'production';
    $GLOBALS['config']['db']['connection'] = 'sqlite';
    Database::reset();
    Database::useConnection(new PDO('sqlite::memory:'));

    try {
        assert_throws(static fn () => MailSettingsService::save([
            'enabled' => '1',
            'host' => 'smtp.example.test',
            'port' => '25',
            'from_email' => 'locia@example.test',
            'encryption' => 'none',
        ], 1));
    } finally {
        Database::reset();
        $GLOBALS['config']['security']['data_key'] = $previousKey;
        $GLOBALS['config']['app']['env'] = $previousEnv;
        $GLOBALS['config']['db']['connection'] = $previousDriver;
    }
});
