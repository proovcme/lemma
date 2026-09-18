<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PushNotificationService;

test('push-подписка сохраняется на пользователя, обновляется и отключается', function (): void {
    $pdo = push_test_pdo();
    Database::useConnection($pdo);
    $payload = [
        'endpoint' => 'https://push.example.test/subscription/one',
        'keys' => ['p256dh' => str_repeat('p', 87), 'auth' => str_repeat('a', 22)],
        'contentEncoding' => 'aes128gcm',
    ];

    PushNotificationService::saveSubscription(1, $payload, 'Locia test device');
    assert_same(1, PushNotificationService::subscriptionCount(1));
    PushNotificationService::saveSubscription(2, $payload, 'Locia reassigned device');
    assert_same(0, PushNotificationService::subscriptionCount(1));
    assert_same(1, PushNotificationService::subscriptionCount(2));

    PushNotificationService::removeSubscription(2, $payload['endpoint']);
    assert_same(0, PushNotificationService::subscriptionCount(2));
});

test('push-очередь не дублирует событие и не принимает внешний target URL', function (): void {
    $pdo = push_test_pdo();
    Database::useConnection($pdo);
    PushNotificationService::saveSubscription(1, [
        'endpoint' => 'https://push.example.test/subscription/two',
        'keys' => ['p256dh' => str_repeat('p', 87), 'auth' => str_repeat('a', 22)],
    ]);

    PushNotificationService::enqueue(1, 'task_created', 'Новая задача', 'Проверьте задачу', 'https://evil.example/phish', 42, 'task:42:create');
    PushNotificationService::enqueue(1, 'task_created', 'Дубль', 'Не должен попасть', '/tasks/42', 42, 'task:42:create');

    $rows = $pdo->query('SELECT * FROM push_outbox')->fetchAll(PDO::FETCH_ASSOC);
    assert_same(1, count($rows));
    assert_same('/notifications', $rows[0]['target_url']);
    assert_same('pending', $rows[0]['status']);
});

test('внутреннее уведомление создаёт один источник для счётчика и push', function (): void {
    $pdo = push_test_pdo();
    Database::useConnection($pdo);
    PushNotificationService::saveSubscription(1, [
        'endpoint' => 'https://push.example.test/subscription/three',
        'keys' => ['p256dh' => str_repeat('p', 87), 'auth' => str_repeat('a', 22)],
    ]);

    \App\Services\TaskWorkflowService::notify(1, 42, 'task_created', 'Новая задача #42');

    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id=1 AND read_at IS NULL')->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM push_outbox WHERE user_id=1 AND status="pending"')->fetchColumn());
});

test('PWA не кэширует рабочие страницы и требует явного включения push', function (): void {
    $worker = (string) file_get_contents(__DIR__ . '/../../public/sw.js');
    $client = (string) file_get_contents(__DIR__ . '/../../public/assets/pwa.js');
    $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../public/manifest.webmanifest'), true);

    assert_true(!str_contains($worker, "addEventListener('fetch'"), 'service worker не должен кэшировать конфиденциальные страницы');
    assert_true(str_contains($client, 'Notification.requestPermission()'), 'разрешение должно запрашиваться только пользовательской командой');
    assert_true(str_contains($client, "addEventListener('click', enable)"), 'включение должно быть привязано к явному клику');
    assert_true(str_contains($worker, 'self.navigator.setAppBadge(badgeCount)'), 'service worker должен обновлять бейдж установленного приложения');
    assert_true(str_contains($client, 'navigator.setAppBadge(count)'), 'открытая PWA должна синхронизировать бейдж со счётчиком');
    assert_same('standalone', $manifest['display'] ?? null);
    assert_same('/my-day?source=pwa', $manifest['start_url'] ?? null);
});

function push_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id,name) VALUES (1,'Олег'),(2,'Сотрудник')");
    $pdo->exec('CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, task_id INTEGER,
        type TEXT NOT NULL, body TEXT NOT NULL, read_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE push_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        endpoint_hash TEXT NOT NULL UNIQUE, endpoint TEXT NOT NULL, p256dh TEXT NOT NULL,
        auth_token TEXT NOT NULL, content_encoding TEXT NOT NULL DEFAULT "aes128gcm",
        user_agent TEXT, device_label TEXT, is_active INTEGER NOT NULL DEFAULT 1,
        last_seen_at TEXT, last_success_at TEXT, last_error TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE push_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL,
        entity_id INTEGER, title TEXT NOT NULL, body TEXT NOT NULL, target_url TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending", attempts INTEGER NOT NULL DEFAULT 0,
        dedupe_key TEXT NOT NULL UNIQUE, available_at TEXT NOT NULL, sent_at TEXT,
        last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    return $pdo;
}
