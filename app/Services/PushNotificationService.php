<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;

final class PushNotificationService
{
    public static function isConfigured(): bool
    {
        return filter_var(getenv('PUSH_ENABLED') ?: '0', FILTER_VALIDATE_BOOL)
            && trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')) !== ''
            && trim((string) getenv('PUSH_VAPID_PRIVATE_KEY')) !== ''
            && class_exists(WebPush::class);
    }

    public static function publicKey(): string
    {
        return self::isConfigured() ? trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')) : '';
    }

    public static function subscriptionCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND is_active = 1');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $payload */
    public static function saveSubscription(int $userId, array $payload, string $userAgent = ''): void
    {
        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));
        $contentEncoding = trim((string) ($payload['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';
        if (strlen($endpoint) > 4096 || !filter_var($endpoint, FILTER_VALIDATE_URL) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('Некорректный push endpoint.');
        }
        if ($p256dh === '' || strlen($p256dh) > 255 || $auth === '' || strlen($auth) > 255) {
            throw new \InvalidArgumentException('Подписка устройства неполная.');
        }
        if (!in_array($contentEncoding, ['aes128gcm', 'aesgcm'], true)) {
            throw new \InvalidArgumentException('Неподдерживаемое шифрование push.');
        }

        $pdo = Database::pdo();
        $hash = hash('sha256', $endpoint);
        $now = date('Y-m-d H:i:s');
        $existing = $pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1');
        $existing->execute([$hash]);
        $id = (int) ($existing->fetchColumn() ?: 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE push_subscriptions SET user_id=?,endpoint=?,p256dh=?,auth_token=?,content_encoding=?,user_agent=?,is_active=1,last_seen_at=?,last_error=NULL,updated_at=? WHERE id=?');
            $stmt->execute([$userId, $endpoint, $p256dh, $auth, $contentEncoding, mb_substr($userAgent, 0, 500), $now, $now, $id]);
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO push_subscriptions (user_id,endpoint_hash,endpoint,p256dh,auth_token,content_encoding,user_agent,is_active,last_seen_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?,?)');
        $stmt->execute([$userId, $hash, $endpoint, $p256dh, $auth, $contentEncoding, mb_substr($userAgent, 0, 500), $now, $now, $now]);
    }

    public static function removeSubscription(int $userId, string $endpoint): void
    {
        if ($endpoint === '') return;
        $stmt = Database::pdo()->prepare('UPDATE push_subscriptions SET is_active=0,updated_at=? WHERE user_id=? AND endpoint_hash=?');
        $stmt->execute([date('Y-m-d H:i:s'), $userId, hash('sha256', $endpoint)]);
    }

    public static function enqueue(int $userId, string $type, string $title, string $body, string $targetUrl, ?int $entityId, string $dedupeKey): void
    {
        if ($userId <= 0 || self::subscriptionCount($userId) === 0) return;
        $targetUrl = self::safeTargetUrl($targetUrl);
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM push_outbox WHERE dedupe_key=? LIMIT 1');
        $exists->execute([$dedupeKey]);
        if ($exists->fetchColumn()) return;
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO push_outbox (user_id,type,entity_id,title,body,target_url,status,attempts,dedupe_key,available_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,0,?,?,?,?)');
        $stmt->execute([$userId, mb_substr($type, 0, 60), $entityId, mb_substr($title, 0, 180), mb_substr($body, 0, 500), $targetUrl, 'pending', mb_substr($dedupeKey, 0, 180), $now, $now, $now]);
    }

    public static function enqueueTest(int $userId): void
    {
        self::enqueue($userId, 'push_test', 'Лемма', 'Push-уведомления на этом устройстве работают.', '/notifications', null, 'push_test:' . $userId . ':' . bin2hex(random_bytes(8)));
    }

    /** @return array{sent:int,failed:int,skipped:int,message:string} */
    public static function processPending(int $limit = 50): array
    {
        if (!self::isConfigured()) return ['sent'=>0,'failed'=>0,'skipped'=>0,'message'=>'Push отключён или не настроен'];
        $pdo = Database::pdo();
        $limit = max(1, min(200, $limit));
        $rows = $pdo->query("SELECT * FROM push_outbox WHERE status IN ('pending','failed') AND attempts < 3 AND available_at <= CURRENT_TIMESTAMP ORDER BY id ASC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        $result = ['sent'=>0,'failed'=>0,'skipped'=>0,'message'=>'processed'];
        foreach ($rows as $row) {
            self::processRow($row, $result);
        }
        return $result;
    }

    /** @param array<string,mixed> $row @param array{sent:int,failed:int,skipped:int,message:string} $result */
    private static function processRow(array $row, array &$result): void
    {
        $pdo = Database::pdo();
        $subs = $pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id=? AND is_active=1 ORDER BY id');
        $subs->execute([(int) $row['user_id']]);
        $subscriptions = $subs->fetchAll(PDO::FETCH_ASSOC);
        if (!$subscriptions) {
            self::finishOutbox((int) $row['id'], 'skipped', 'Нет активных устройств');
            $result['skipped']++;
            return;
        }
        $webPush = new WebPush(['VAPID'=>[
            'subject'=>trim((string) (getenv('PUSH_VAPID_SUBJECT') ?: 'https://pm.example.org')),
            'publicKey'=>trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')),
            'privateKey'=>trim((string) getenv('PUSH_VAPID_PRIVATE_KEY')),
        ]], ['TTL'=>3600,'urgency'=>'normal','batchSize'=>50,'contentType'=>'application/json'], 12);
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
        $unreadStmt->execute([(int) $row['user_id']]);
        $badgeCount = (int) $unreadStmt->fetchColumn();
        $payload = json_encode([
            'title'=>(string) $row['title'], 'body'=>(string) $row['body'],
            'url'=>self::safeTargetUrl((string) $row['target_url']),
            'icon'=>'/pwa/icon-192.png', 'badge'=>'/pwa/badge-96.png',
            'badgeCount'=>$badgeCount,
            'tag'=>(string) $row['type'] . '-' . (string) ($row['entity_id'] ?? $row['id']),
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $successes = 0;
        $errors = [];
        foreach ($subscriptions as $subscriptionRow) {
            $subscription = Subscription::create([
                'endpoint'=>(string) $subscriptionRow['endpoint'],
                'keys'=>['p256dh'=>(string) $subscriptionRow['p256dh'],'auth'=>(string) $subscriptionRow['auth_token']],
                'contentEncoding'=>(string) $subscriptionRow['content_encoding'],
            ]);
            $report = $webPush->sendOneNotification($subscription, $payload, ['TTL'=>3600,'urgency'=>'normal']);
            if ($report->isSuccess()) {
                $pdo->prepare('UPDATE push_subscriptions SET last_success_at=?,last_error=NULL,updated_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),(int)$subscriptionRow['id']]);
                $successes++;
            } else {
                $reason = mb_substr($report->getReason(), 0, 900);
                $pdo->prepare('UPDATE push_subscriptions SET is_active=?,last_error=?,updated_at=? WHERE id=?')->execute([$report->isSubscriptionExpired()?0:1,$reason,date('Y-m-d H:i:s'),(int)$subscriptionRow['id']]);
                $errors[] = $reason;
            }
        }
        if ($successes > 0) {
            self::finishOutbox((int) $row['id'], 'sent', $errors ? implode('; ', $errors) : null);
            $result['sent']++;
        } else {
            self::finishOutbox((int) $row['id'], 'failed', implode('; ', $errors) ?: 'Push service rejected the message');
            $result['failed']++;
        }
    }

    private static function finishOutbox(int $id, string $status, ?string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = Database::pdo()->prepare('UPDATE push_outbox SET status=?,attempts=attempts+1,sent_at=?,last_error=?,updated_at=? WHERE id=?');
        $stmt->execute([$status,$status==='sent'?$now:null,$error?mb_substr($error,0,900):null,$now,$id]);
    }

    private static function safeTargetUrl(string $url): string
    {
        $url = trim($url);
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? mb_substr($url, 0, 500) : '/notifications';
    }
}
