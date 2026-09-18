<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationOutboxService
{
    public static function queueTaskCreated(int $taskId, int $actorId): void
    {
        try {
            $task = self::taskMailContext($taskId);
            if ($task === null) {
                return;
            }
            $email = trim((string) ($task['assignee_email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $link = PublicLinkService::ensureTaskLink(
                $taskId,
                '#' . $taskId . ' ' . (string) ($task['title'] ?? 'Задача'),
                $actorId
            );
            $taskUrl = PublicLinkService::publicUrl($link);
            $deadline = format_date((string) ($task['date_end'] ?? '')) ?: 'не задан';
            $mail = NotificationTemplateService::render('task_created_mail', [
                '{task_id}' => (string) $taskId,
                '{task_title}' => (string) ($task['title'] ?? ''),
                '{task_status}' => task_status_label((string) ($task['status'] ?? 'new')),
                '{task_type}' => task_type_label((string) ($task['task_type'] ?? 'work')),
                '{project_code}' => (string) ($task['project_code'] ?? ''),
                '{project_title}' => (string) ($task['project_title'] ?? ''),
                '{assignee}' => (string) ($task['assignee_name'] ?? ''),
                '{author}' => (string) ($task['author_name'] ?? ''),
                '{reviewer}' => (string) (($task['reviewer_name'] ?? '') ?: 'не назначен'),
                '{deadline}' => $deadline,
                '{planned_hours}' => self::hours($task['planned_hours'] ?? null),
                '{task_url}' => $taskUrl,
                '{app_url}' => app_url(''),
            ]);

            self::enqueue(
                'task_created',
                $taskId,
                $email,
                $mail['subject'],
                $mail['body'],
                'task_created:' . $taskId . ':' . mb_strtolower($email, 'UTF-8')
            );
        } catch (\Throwable $e) {
            self::log('queueTaskCreated failed: ' . $e->getMessage());
        }
    }

    public static function queueTaskReviewSubmitted(int $taskId, int $recipientId, int $actorId): void
    {
        self::queueTaskAction(
            $taskId,
            $recipientId,
            $actorId,
            'task_review_submitted',
            'Вам назначена проверка результата задачи',
            'review:' . $taskId . ':' . $recipientId
        );
    }

    public static function queueTaskApprovalRequested(int $taskId, int $recipientId, int $actorId, string $stageLabel): void
    {
        self::queueTaskAction(
            $taskId,
            $recipientId,
            $actorId,
            'task_approval_requested',
            'Задача ждёт согласования: ' . $stageLabel,
            'approval:' . $taskId . ':' . $recipientId . ':' . mb_strtolower($stageLabel, 'UTF-8')
        );
    }

    /**
     * @return array{sent:int,failed:int,skipped:int,message:string}
     */
    public static function processPending(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        if (!EmailService::isEnabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'SMTP отключён или не настроен'];
        }

        $rows = self::pending($limit);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            try {
                EmailService::send(
                    (string) $row['recipient_email'],
                    (string) $row['subject'],
                    (string) $row['body'],
                    (string) $row['dedupe_key']
                );
                Database::pdo()->prepare('
                    UPDATE notification_outbox
                    SET status = ?, attempts = attempts + 1, sent_at = ?, last_error = NULL, updated_at = ?
                    WHERE id = ?
                ')->execute(['sent', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
                $sent++;
            } catch (\Throwable $e) {
                Database::pdo()->prepare('
                    UPDATE notification_outbox
                    SET status = ?, attempts = attempts + 1, last_error = ?, updated_at = ?
                    WHERE id = ?
                ')->execute(['failed', mb_substr($e->getMessage(), 0, 900, 'UTF-8'), date('Y-m-d H:i:s'), $id]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => 0, 'message' => 'processed'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function pending(int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare('
            SELECT *
            FROM notification_outbox
            WHERE status IN (?, ?) AND attempts < 3
            ORDER BY id ASC
            LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['pending', 'failed']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function enqueue(string $type, int $entityId, string $email, string $subject, string $body, string $dedupeKey): void
    {
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM notification_outbox WHERE dedupe_key = ? LIMIT 1');
        $exists->execute([$dedupeKey]);
        if ($exists->fetchColumn()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('
            INSERT INTO notification_outbox
                (type, entity_id, recipient_email, subject, body, status, dedupe_key, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$type, $entityId, $email, $subject, $body, 'pending', $dedupeKey, $now, $now]);
    }

    private static function queueTaskAction(int $taskId, int $recipientId, int $actorId, string $type, string $mailAction, string $dedupeKey): void
    {
        try {
            $task = self::taskMailContext($taskId);
            $recipient = self::userMailContext($recipientId);
            if ($task === null || $recipient === null) {
                return;
            }
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $link = PublicLinkService::ensureTaskLink(
                $taskId,
                '#' . $taskId . ' ' . (string) ($task['title'] ?? 'Задача'),
                $actorId
            );
            $deadline = format_date((string) ($task['date_end'] ?? '')) ?: 'не задан';
            $mail = NotificationTemplateService::render('task_approval_mail', [
                '{mail_action}' => $mailAction,
                '{recipient}' => (string) ($recipient['name'] ?? ''),
                '{task_id}' => (string) $taskId,
                '{task_title}' => (string) ($task['title'] ?? ''),
                '{task_status}' => task_status_label((string) ($task['status'] ?? 'new')),
                '{task_type}' => task_type_label((string) ($task['task_type'] ?? 'work')),
                '{project_code}' => (string) ($task['project_code'] ?? ''),
                '{project_title}' => (string) ($task['project_title'] ?? ''),
                '{assignee}' => (string) ($task['assignee_name'] ?? ''),
                '{author}' => (string) ($task['author_name'] ?? ''),
                '{deadline}' => $deadline,
                '{planned_hours}' => self::hours($task['planned_hours'] ?? null),
                '{task_url}' => PublicLinkService::publicUrl($link),
                '{app_url}' => app_url(''),
            ]);

            self::enqueue(
                $type,
                $taskId,
                $email,
                $mail['subject'],
                $mail['body'],
                $dedupeKey . ':' . mb_strtolower($email, 'UTF-8')
            );
        } catch (\Throwable $e) {
            self::log('queueTaskAction failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function taskMailContext(int $taskId): ?array
    {
        $stmt = Database::pdo()->prepare('
            SELECT
                t.id,
                t.title,
                t.status,
                t.task_type,
                t.date_end,
                t.planned_hours,
                t.assignee_id,
                p.code AS project_code,
                p.title AS project_title,
                assignee.name AS assignee_name,
                assignee.email AS assignee_email,
                author.name AS author_name,
                reviewer.name AS reviewer_name
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users author ON author.id = t.author_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            WHERE t.id = ?
            LIMIT 1
        ');
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function userMailContext(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare('
            SELECT id, name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function hours(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'не задан';
        }
        $formatted = number_format((float) $value, 1, '.', ' ');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) . ' ч' : $formatted . ' ч';
    }

    private static function log(string $message): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $dir . '/mail-queue.log');
    }
}
