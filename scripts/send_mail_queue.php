<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$limit = isset($argv[1]) ? (int) $argv[1] : (int) (getenv('MAIL_QUEUE_LIMIT') ?: 20);
$result = \App\Services\NotificationOutboxService::processPending($limit);

echo 'mail_queue sent=' . $result['sent']
    . ' failed=' . $result['failed']
    . ' skipped=' . $result['skipped']
    . ' message=' . $result['message']
    . PHP_EOL;
