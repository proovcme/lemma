<?php

declare(strict_types=1);

namespace App\Controllers;

final class NotificationController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        $stmt = $this->db()->prepare('
            SELECT n.id, n.task_id, n.type, n.body, n.target_url, n.read_at, n.created_at,
                   t.title AS task_title, t.status AS task_status,
                   p.code AS project_code
            FROM notifications n
            LEFT JOIN tasks t ON t.id = n.task_id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE n.user_id = ?
            ORDER BY n.read_at IS NULL DESC, n.created_at DESC, n.id DESC
            LIMIT 100
        ');
        $stmt->execute([(int) $user['id']]);

        $this->render('notifications/index', [
            'title' => 'Уведомления',
            'notifications' => $stmt->fetchAll(),
        ]);
    }

    public function markRead(): void
    {
        $user = require_auth();
        $id = (int) ($_POST['id'] ?? 0);
        $readAt = date('Y-m-d H:i:s');

        if ($id > 0) {
            $stmt = $this->db()->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$readAt, $id, (int) $user['id']]);
        } else {
            $stmt = $this->db()->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL');
            $stmt->execute([$readAt, (int) $user['id']]);
        }

        flash('success', 'Уведомления отмечены как прочитанные.');
        redirect('/notifications');
    }
}
