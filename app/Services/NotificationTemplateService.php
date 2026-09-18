<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationTemplateService
{
    /**
     * Типы шаблонов с метаданными и дефолтными значениями.
     * Дефолт используется как fallback, если запись в БД не найдена.
     */
    private const DEFAULTS = [
        'task_mail' => [
            'label'   => 'Письмо по задаче (кнопка «Отправить по почте»)',
            'subject' => 'DPR #{task_id} · {task_status} · {task_title}',
            'body'    => "Статус: {task_status}\r\nЗадача: #{task_id} {task_title}\r\nПроект: {project_code} · {project_title}\r\nТип: {task_type}\r\nИсполнитель: {assignee}\r\nПостановщик: {author}\r\nПроверяющий: {reviewer}\r\nСрок: {deadline}\r\nПрогресс: {progress}%\r\nПлан: {planned_hours}\r\nФакт: {actual_hours}\r\n\r\nЗадача в DPR: {task_url}\r\n\r\nВложения/файлы:\r\n{attachments}",
        ],
        'task_created_mail' => [
            'label'   => 'Новая задача исполнителю',
            'subject' => 'Новая задача #{task_id}: {task_title}',
            'body'    => "Добрый день, {assignee}!\r\n\r\nВам назначена новая задача.\r\n\r\nЗадача: #{task_id} {task_title}\r\nПроект: {project_code} · {project_title}\r\nТип: {task_type}\r\nПостановщик: {author}\r\nПроверяющий: {reviewer}\r\nСрок: {deadline}\r\nПлан: {planned_hours}\r\n\r\nОткрыть задачу: {task_url}\r\n\r\nСистема: {app_url}",
        ],
        'task_approval_mail' => [
            'label'   => 'Задача на проверку или согласование',
            'subject' => '{mail_action}: задача #{task_id} {task_title}',
            'body'    => "Добрый день, {recipient}!\r\n\r\n{mail_action}.\r\n\r\nЗадача: #{task_id} {task_title}\r\nПроект: {project_code} · {project_title}\r\nТип: {task_type}\r\nСтатус: {task_status}\r\nИсполнитель: {assignee}\r\nПостановщик: {author}\r\nСрок: {deadline}\r\nПлан: {planned_hours}\r\n\r\nОткрыть задачу: {task_url}\r\n\r\nСистема: {app_url}",
        ],
        'credentials_mail' => [
            'label'   => 'Письмо с реквизитами доступа (после импорта пользователей)',
            'subject' => 'Доступ к системе «Лемма»',
            'body'    => "Добрый день, {user_name}!\r\n\r\nВам создан доступ к системе управления проектами «Лемма».\r\n\r\nЛогин: {user_email}\r\nВременный пароль: {password}\r\n\r\nСсылка для входа: {app_url}\r\n\r\nПри первом входе система попросит сменить пароль.\r\n\r\nС уважением,\r\nАдминистратор системы",
        ],
    ];

    /**
     * Возвращает список доступных переменных для каждого типа шаблона.
     */
    public const VARIABLES = [
        'task_mail' => [
            '{task_id}'       => 'Номер задачи',
            '{task_title}'    => 'Название задачи',
            '{task_status}'   => 'Статус задачи',
            '{task_type}'     => 'Тип задачи',
            '{project_code}'  => 'Код проекта',
            '{project_title}' => 'Название проекта',
            '{assignee}'      => 'Исполнитель',
            '{author}'        => 'Постановщик',
            '{reviewer}'      => 'Проверяющий',
            '{deadline}'      => 'Срок',
            '{progress}'      => 'Прогресс (%)',
            '{planned_hours}' => 'Плановые часы',
            '{actual_hours}'  => 'Фактические часы',
            '{task_url}'      => 'Ссылка на задачу в DPR',
            '{attachments}'   => 'Список вложений',
        ],
        'task_created_mail' => [
            '{task_id}'       => 'Номер задачи',
            '{task_title}'    => 'Название задачи',
            '{task_status}'   => 'Статус задачи',
            '{task_type}'     => 'Тип задачи',
            '{project_code}'  => 'Код проекта',
            '{project_title}' => 'Название проекта',
            '{assignee}'      => 'Исполнитель',
            '{author}'        => 'Постановщик',
            '{reviewer}'      => 'Проверяющий',
            '{deadline}'      => 'Срок',
            '{planned_hours}' => 'Плановые часы',
            '{task_url}'      => 'Короткая ссылка на задачу',
            '{app_url}'       => 'Ссылка на систему',
        ],
        'task_approval_mail' => [
            '{mail_action}'   => 'Что требуется: проверка результата или согласование',
            '{recipient}'     => 'Получатель письма',
            '{task_id}'       => 'Номер задачи',
            '{task_title}'    => 'Название задачи',
            '{task_status}'   => 'Статус задачи',
            '{task_type}'     => 'Тип задачи',
            '{project_code}'  => 'Код проекта',
            '{project_title}' => 'Название проекта',
            '{assignee}'      => 'Исполнитель',
            '{author}'        => 'Постановщик',
            '{deadline}'      => 'Срок',
            '{planned_hours}' => 'Плановые часы',
            '{task_url}'      => 'Короткая ссылка на задачу',
            '{app_url}'       => 'Ссылка на систему',
        ],
        'credentials_mail' => [
            '{user_name}'  => 'Имя пользователя',
            '{user_email}' => 'Email (логин)',
            '{password}'   => 'Временный пароль',
            '{app_url}'    => 'Ссылка на систему',
        ],
    ];

    /**
     * Загружает шаблон из БД и подставляет переменные.
     * При отсутствии записи в БД возвращает дефолтные значения.
     *
     * @param  string               $type  Тип шаблона (ключ из DEFAULTS)
     * @param  array<string,string> $vars  Значения переменных вида ['{task_id}' => '42']
     * @return array{subject: string, body: string}
     */
    public static function render(string $type, array $vars): array
    {
        $template = self::load($type);

        $subject = str_replace(array_keys($vars), array_values($vars), $template['subject']);
        $body    = str_replace(array_keys($vars), array_values($vars), $template['body']);

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Загружает шаблон из БД или возвращает дефолт.
     *
     * @return array{label: string, subject: string, body: string}
     */
    public static function load(string $type): array
    {
        $default = self::DEFAULTS[$type] ?? ['label' => $type, 'subject' => '', 'body' => ''];

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT subject, body FROM notification_templates WHERE type = ? LIMIT 1'
            );
            $stmt->execute([$type]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'label'   => $default['label'],
                    'subject' => (string) $row['subject'],
                    'body'    => (string) $row['body'],
                ];
            }
        } catch (\Throwable) {
            // Таблица может не существовать на старых инсталляциях — возвращаем дефолт
        }

        return $default;
    }

    /**
     * Сохраняет или обновляет шаблон в БД.
     */
    public static function save(string $type, string $subject, string $body): void
    {
        if (!isset(self::DEFAULTS[$type])) {
            throw new \InvalidArgumentException("Unknown template type: {$type}");
        }

        $label = self::DEFAULTS[$type]['label'];

        $pdo = Database::pdo();

        // Upsert: работает и на SQLite, и на MySQL
        $existing = $pdo->prepare('SELECT id FROM notification_templates WHERE type = ? LIMIT 1');
        $existing->execute([$type]);

        if ($existing->fetchColumn()) {
            $pdo->prepare(
                'UPDATE notification_templates SET subject = ?, body = ?, updated_at = ? WHERE type = ?'
            )->execute([$subject, $body, date('Y-m-d H:i:s'), $type]);
        } else {
            $pdo->prepare(
                'INSERT INTO notification_templates (type, label, subject, body) VALUES (?, ?, ?, ?)'
            )->execute([$type, $label, $subject, $body]);
        }
    }

    /**
     * Возвращает все типы шаблонов с их текущим состоянием (из БД или дефолт).
     *
     * @return array<string, array{label: string, subject: string, body: string, is_custom: bool}>
     */
    public static function all(): array
    {
        $stored = [];
        try {
            $rows = Database::pdo()->query('SELECT type, subject, body FROM notification_templates')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $stored[(string) $row['type']] = $row;
            }
        } catch (\Throwable) {
            // fallback
        }

        $result = [];
        foreach (self::DEFAULTS as $type => $default) {
            $result[$type] = [
                'label'     => $default['label'],
                'subject'   => isset($stored[$type]) ? (string) $stored[$type]['subject'] : $default['subject'],
                'body'      => isset($stored[$type]) ? (string) $stored[$type]['body']    : $default['body'],
                'is_custom' => isset($stored[$type]),
            ];
        }

        return $result;
    }
}
