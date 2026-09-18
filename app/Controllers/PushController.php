<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\IncidentLogService;
use App\Services\PushNotificationService;

final class PushController extends BaseController
{
    public function config(): void
    {
        $user = require_auth();
        header('Cache-Control: no-store');
        json_response([
            'enabled' => PushNotificationService::isConfigured(),
            'publicKey' => PushNotificationService::publicKey(),
            'devices' => PushNotificationService::subscriptionCount((int) $user['id']),
        ]);
    }

    public function subscribe(): void
    {
        $user = require_auth();
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) json_response(['ok'=>false,'message'=>'Некорректная подписка.'], 422);
        try {
            PushNotificationService::saveSubscription((int) $user['id'], $payload, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            PushNotificationService::enqueueTest((int) $user['id']);
            json_response(['ok'=>true,'message'=>'Устройство подключено. Контрольный push придёт в течение минуты.']);
        } catch (\InvalidArgumentException $e) {
            json_response(['ok'=>false,'message'=>$e->getMessage()], 422);
        } catch (\Throwable $e) {
            $incidentId = IncidentLogService::report($e, ['operation' => 'push_subscribe']);
            json_response(['ok'=>false,'message'=>IncidentLogService::userMessage($incidentId, 'подключить push-уведомления')], 500);
        }
    }

    public function unsubscribe(): void
    {
        $user = require_auth();
        $payload = json_decode((string) file_get_contents('php://input'), true);
        PushNotificationService::removeSubscription((int) $user['id'], trim((string) ($payload['endpoint'] ?? '')));
        json_response(['ok'=>true,'message'=>'Push-уведомления на этом устройстве выключены.']);
    }

    public function test(): void
    {
        $user = require_auth();
        if (PushNotificationService::subscriptionCount((int) $user['id']) < 1) {
            json_response(['ok'=>false,'message'=>'Сначала подключите устройство.'], 422);
        }
        PushNotificationService::enqueueTest((int) $user['id']);
        json_response(['ok'=>true,'message'=>'Контрольный push поставлен в очередь.']);
    }
}
