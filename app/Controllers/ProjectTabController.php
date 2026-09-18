<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\DictionaryService;
use App\Services\CostPlanService;
use App\Services\PermissionService;
use App\Services\ProjectTemplateService;
use App\Services\SbcCatalogService;
use App\Services\TaskWorkflowService;

final class ProjectTabController extends BaseController
{
    private const CSV_MAX_BYTES = 2 * 1024 * 1024;
    private const CSV_MAX_ROWS = 5000;

    private const TABLES = [
        'schedule' => 'project_schedule',
        'sections' => 'project_sections',
        'issues' => 'project_issues',
        'data' => 'project_data_registry',
        'exchange' => 'project_task_exchange',
        'costs' => 'project_cost_plan',
    ];

    private const CSV_COLUMNS = [
        'schedule' => [
            ['key' => 'task_id', 'label' => 'Задача'],
            ['key' => 'volume', 'label' => 'Том'],
            ['key' => 'section', 'label' => 'Раздел'],
            ['key' => 'rd_date_plan', 'label' => 'Плановая дата'],
            ['key' => 'assignee_name', 'label' => 'Кому'],
            ['key' => 'date_issued', 'label' => 'Факт выдачи'],
            ['key' => 'issue_status', 'label' => 'Статус'],
            ['key' => 'comments', 'label' => 'Комментарий'],
        ],
        'sections' => [
            ['key' => 'task_id', 'label' => 'Задача'],
            ['key' => 'volume', 'label' => 'Том'],
            ['key' => 'code', 'label' => 'Шифр'],
            ['key' => 'title', 'label' => 'Наименование'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'date_start', 'label' => 'Дата начала'],
            ['key' => 'date_end', 'label' => 'Дата окончания'],
            ['key' => 'assignee_name', 'label' => 'Ответственный'],
            ['key' => 'reviewer_name', 'label' => 'Проверяющий'],
            ['key' => 'comments', 'label' => 'Комментарий'],
        ],
        'issues' => [
            ['key' => 'blocking_task_id', 'label' => 'Блокирует задачу'],
            ['key' => 'num', 'label' => 'Номер'],
            ['key' => 'section_code', 'label' => 'Шифр/марка'],
            ['key' => 'issue', 'label' => 'Вопрос'],
            ['key' => 'assignee_name', 'label' => 'Ответственный'],
            ['key' => 'stage', 'label' => 'Стадия'],
            ['key' => 'date_raised', 'label' => 'Дата вопроса'],
            ['key' => 'answer', 'label' => 'Ответ'],
            ['key' => 'notes', 'label' => 'Примечание'],
            ['key' => 'status', 'label' => 'Статус'],
        ],
        'data' => [
            ['key' => 'blocking_task_ids', 'label' => 'Блокирует задачи'],
            ['key' => 'num', 'label' => 'Номер'],
            ['key' => 'section_code', 'label' => 'Марка/шифр'],
            ['key' => 'missing_data', 'label' => 'Отсутствующие ИД'],
            ['key' => 'responsible', 'label' => 'Ответственный'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'date_requested', 'label' => 'Дата запроса'],
            ['key' => 'date_received_plan', 'label' => 'Дата получения'],
            ['key' => 'impact', 'label' => 'Влияние'],
            ['key' => 'comments', 'label' => 'Комментарий'],
        ],
        'exchange' => [
            ['key' => 'direction', 'label' => 'Тип'],
            ['key' => 'task_id', 'label' => 'Задача'],
            ['key' => 'num', 'label' => 'Номер'],
            ['key' => 'assignment', 'label' => 'Задание'],
            ['key' => 'from_party_name', 'label' => 'От кого'],
            ['key' => 'to_party_name', 'label' => 'Кому / от кого ждём'],
            ['key' => 'from_section', 'label' => 'От раздела'],
            ['key' => 'to_section', 'label' => 'К разделу'],
            ['key' => 'file_url', 'label' => 'Samba'],
            ['key' => 'date_issued', 'label' => 'Дата выдачи'],
            ['key' => 'deadline', 'label' => 'Срок'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'comments', 'label' => 'Комментарий'],
        ],
        'costs' => [
            ['key' => 'sbc_item_id', 'label' => 'ID СБЦ'],
            ['key' => 'num', 'label' => 'Номер'],
            ['key' => 'section_code', 'label' => 'Раздел'],
            ['key' => 'sbc_collection', 'label' => 'Сборник СБЦ'],
            ['key' => 'sbc_table', 'label' => 'Таблица/пункт'],
            ['key' => 'work_name', 'label' => 'Работа'],
            ['key' => 'unit', 'label' => 'Показатель'],
            ['key' => 'labor_hours', 'label' => 'Трудозатраты, чел-ч'],
            ['key' => 'labor_estimate_method', 'label' => 'Метод трудозатрат'],
            ['key' => 'labor_executor_hours', 'label' => 'Оценка исполнителя, чел-ч'],
            ['key' => 'labor_gip_hours', 'label' => 'Оценка ГИПа, чел-ч'],
            ['key' => 'labor_adjustment_hours', 'label' => 'Корректировка, чел-ч'],
            ['key' => 'labor_directive_hours', 'label' => 'Директива, чел-ч'],
            ['key' => 'labor_norm_hours', 'label' => 'Норма, чел-ч'],
            ['key' => 'labor_productivity_rate', 'label' => 'Выработка, ед./чел-день'],
            ['key' => 'labor_productivity_coeff', 'label' => 'К модели трудозатрат'],
            ['key' => 'labor_basis', 'label' => 'Обоснование трудозатрат'],
            ['key' => 'labor_approval_status', 'label' => 'Утверждение трудозатрат'],
            ['key' => 'labor_approved_by_name', 'label' => 'Утвердил трудозатраты'],
            ['key' => 'labor_approved_at', 'label' => 'Дата утверждения трудозатрат'],
            ['key' => 'labor_approval_comment', 'label' => 'Комментарий утверждения трудозатрат'],
            ['key' => 'quantity', 'label' => 'Количество'],
            ['key' => 'base_price', 'label' => 'Базовая цена'],
            ['key' => 'stage_percent', 'label' => 'Стадия, %'],
            ['key' => 'complexity_coeff', 'label' => 'К сложности'],
            ['key' => 'deflator_coeff', 'label' => 'Индекс'],
            ['key' => 'adjustment_coeff', 'label' => 'К прочий'],
            ['key' => 'planned_cost', 'label' => 'Деньги, тыс. руб.'],
            ['key' => 'price_level', 'label' => 'Уровень цен'],
            ['key' => 'justification', 'label' => 'Обоснование'],
            ['key' => 'comments', 'label' => 'Комментарий'],
        ],
    ];

    public function schedule(int $id): void
    {
        $this->show($id, 'schedule');
    }

    public function sections(int $id): void
    {
        $this->show($id, 'sections');
    }

    public function issues(int $id): void
    {
        $this->show($id, 'issues');
    }

    public function data(int $id): void
    {
        $this->show($id, 'data');
    }

    public function exchange(int $id): void
    {
        $this->show($id, 'exchange');
    }

    public function costs(int $id): void
    {
        $this->show($id, 'costs');
    }

    public function syncSchedule(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $stmt = $this->db()->prepare('
            SELECT t.id,
                   t.title,
                   t.volume,
                   t.section,
                   t.discipline,
                   t.date_end,
                   t.closed_at,
                   t.status,
                   t.approval_stage,
                   t.assignee_id,
                   ti.issued_at AS last_issued_at,
                   ti.status AS last_issuance_status
            FROM tasks t
            LEFT JOIN (
                SELECT i.task_id, i.issued_at, i.status
                FROM task_issuances i
                INNER JOIN (
                    SELECT task_id, MAX(issue_number) AS issue_number
                    FROM task_issuances
                    GROUP BY task_id
                ) latest ON latest.task_id = i.task_id AND latest.issue_number = i.issue_number
            ) ti ON ti.task_id = t.id
            WHERE t.project_id = ?
              AND (
                    COALESCE(t.volume, "") != ""
                    OR COALESCE(t.section, "") != ""
                    OR COALESCE(t.discipline, "") != ""
                    OR COALESCE(t.approval_stage, "") = "issued"
              )
            ORDER BY t.date_end IS NULL, t.date_end, t.id
        ');
        $stmt->execute([$id]);

        $existingByTask = $this->db()->prepare('
            SELECT id
            FROM project_schedule
            WHERE project_id = ? AND task_id = ?
            LIMIT 1
        ');
        $legacyMatch = $this->db()->prepare('
            SELECT id
            FROM project_schedule
            WHERE project_id = ?
              AND task_id IS NULL
              AND COALESCE(volume, "") = ?
              AND COALESCE(section, "") = ?
              AND COALESCE(rd_date_plan, "") = ?
            ORDER BY id
            LIMIT 1
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_schedule (project_id, task_id, volume, section, rd_date_plan, date_issued, issue_status, rd_readiness_label, assignee_id, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $update = $this->db()->prepare('
            UPDATE project_schedule
            SET task_id = ?,
                volume = ?,
                section = ?,
                rd_date_plan = ?,
                date_issued = ?,
                issue_status = ?,
                rd_readiness_label = ?,
                assignee_id = ?,
                comments = ?
            WHERE id = ?
        ');

        $created = 0;
        $updated = 0;
        foreach ($stmt->fetchAll() as $task) {
            $taskId = (int) $task['id'];
            $volume = (string) ($task['volume'] ?: $task['section'] ?: $task['discipline'] ?: ('Задача #' . $taskId));
            $section = (string) ($task['section'] ?: $task['discipline']);
            $datePlan = (string) ($task['date_end'] ?? '');

            $status = !empty($task['last_issuance_status'])
                ? task_issuance_status_label((string) $task['last_issuance_status'])
                : $this->issueStatusFromTask((string) $task['status']);
            $dateIssued = !empty($task['last_issued_at'])
                ? substr((string) $task['last_issued_at'], 0, 10)
                : (($task['status'] === 'done' && !empty($task['closed_at']))
                ? substr((string) $task['closed_at'], 0, 10)
                : null);
            $comments = 'Авто из задачи #' . $taskId . ': ' . $task['title'];
            if (!empty($task['last_issued_at'])) {
                $comments .= '; последняя выдача: ' . $status . ' ' . substr((string) $task['last_issued_at'], 0, 10);
            }

            $existingByTask->execute([$id, $taskId]);
            $scheduleId = $existingByTask->fetchColumn();
            if (!$scheduleId) {
                $legacyMatch->execute([$id, $volume, $section, $datePlan]);
                $scheduleId = $legacyMatch->fetchColumn();
            }

            if ($scheduleId) {
                $update->execute([
                    $taskId,
                    $volume,
                    $section,
                    $datePlan !== '' ? $datePlan : null,
                    $dateIssued,
                    $status,
                    $status,
                    $task['assignee_id'] ?: null,
                    $comments,
                    (int) $scheduleId,
                ]);
                $updated++;
                continue;
            }

            $insert->execute([
                $id,
                $taskId,
                $volume,
                $section,
                $datePlan !== '' ? $datePlan : null,
                $dateIssued,
                $status,
                $status,
                $task['assignee_id'] ?: null,
                $comments,
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.schedule_synced',
            'График РД обновлён из задач',
            'Добавлено строк: ' . $created . ', обновлено: ' . $updated . '.'
        );
        flash('success', 'График РД обновлён из задач. Добавлено строк: ' . $created . ', обновлено: ' . $updated . '.');
        redirect('/projects/' . $id . '/schedule');
    }

    public function seedDataTemplate(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_data_registry
            WHERE project_id = ? AND COALESCE(section_code, "") = ? AND COALESCE(missing_data, "") = ?
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_data_registry (project_id, num, section_code, missing_data, responsible, status, date_requested, date_received_plan, impact, comments)
            VALUES (?, ?, ?, ?, ?, "waiting", NULL, NULL, ?, ?)
        ');

        $num = $this->nextNum('project_data_registry', $id);
        $created = 0;
        foreach (ProjectTemplateService::initialDataItems() as $item) {
            $sectionCode = (string) $item['section_code'];
            $missingData = (string) $item['missing_data'];
            $exists->execute([$id, $sectionCode, $missingData]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                $id,
                $num++,
                $sectionCode,
                $missingData,
                (string) $item['responsible'],
                'Проверить наличие и дату получения.',
                'Типовой перечень исходных данных',
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.data_template',
            'Добавлен типовой перечень исходных данных',
            'Новых строк: ' . $created . '.'
        );
        flash('success', 'Типовой перечень исходных данных добавлен. Новых строк: ' . $created . '.');
        redirect('/projects/' . $id . '/data');
    }

    public function seedSectionsTemplate(int $id, string $type): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project || !in_array($type, ['pd87', 'rd21'], true)) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_sections
            WHERE project_id = ? AND COALESCE(volume, "") = ? AND COALESCE(code, "") = ?
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_sections (project_id, task_id, volume, code, title, status, date_start, date_end, assignee_id, reviewer_id, comments)
            VALUES (?, NULL, ?, ?, ?, "Планируется", NULL, NULL, NULL, NULL, ?)
        ');

        $created = 0;
        foreach (ProjectTemplateService::sectionsFor($type) as $section) {
            $code = (string) $section['code'];
            $exists->execute([$id, $code, $code]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                $id,
                $code,
                $code,
                (string) $section['title'],
                $type === 'rd21' ? 'Шаблон РД по ГОСТ Р 21.101-2026' : 'Шаблон ПД по Постановлению 87',
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.sections_template',
            'Добавлен шаблон разделов',
            'Тип шаблона: ' . $type . '. Новых строк: ' . $created . '.'
        );
        flash('success', 'Шаблон разделов добавлен. Новых строк: ' . $created . '.');
        redirect('/projects/' . $id . '/sections');
    }

    public function syncSections(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $exists = $this->db()->prepare('
            SELECT id, task_id
            FROM project_sections
            WHERE project_id = ? AND COALESCE(volume, "") = ? AND COALESCE(code, "") = ?
            LIMIT 1
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_sections (project_id, task_id, volume, code, title, status, date_start, date_end, assignee_id, reviewer_id, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $linkTask = $this->db()->prepare('UPDATE project_sections SET task_id = ? WHERE id = ? AND (task_id IS NULL OR task_id = 0)');

        $created = 0;
        $tasks = $this->db()->prepare('
            SELECT id, title, volume, section, discipline, status, date_start, date_end, assignee_id, reviewer_id
            FROM tasks
            WHERE project_id = ?
              AND (COALESCE(volume, "") != "" OR COALESCE(section, "") != "" OR COALESCE(discipline, "") != "")
            ORDER BY date_end IS NULL, date_end, id
        ');
        $tasks->execute([$id]);
        foreach ($tasks->fetchAll() as $task) {
            $volume = trim((string) ($task['volume'] ?: $task['section'] ?: $task['discipline']));
            $code = trim((string) ($task['section'] ?: $task['discipline'] ?: $task['volume']));
            if ($volume === '' && $code === '') {
                continue;
            }
            $exists->execute([$id, $volume, $code]);
            $existing = $exists->fetch();
            if ($existing) {
                $linkTask->execute([(int) $task['id'], (int) $existing['id']]);
                continue;
            }

            $insert->execute([
                $id,
                (int) $task['id'],
                $volume,
                $code,
                (string) $task['title'],
                $this->issueStatusFromTask((string) $task['status']),
                $this->dateOrNull($task['date_start'] ?? ''),
                $this->dateOrNull($task['date_end'] ?? ''),
                $task['assignee_id'] ?: null,
                $task['reviewer_id'] ?: null,
                'Из задачи #' . $task['id'],
            ]);
            $created++;
        }

        $schedule = $this->db()->prepare('
            SELECT id, volume, section, issue_status, rd_readiness_label, rd_date_plan, assignee_id, comments
            FROM project_schedule
            WHERE project_id = ? AND (COALESCE(volume, "") != "" OR COALESCE(section, "") != "")
            ORDER BY rd_date_plan IS NULL, rd_date_plan, id
        ');
        $schedule->execute([$id]);
        foreach ($schedule->fetchAll() as $row) {
            $volume = trim((string) ($row['volume'] ?: $row['section']));
            $code = trim((string) ($row['section'] ?: $row['volume']));
            $exists->execute([$id, $volume, $code]);
            if ($exists->fetch()) {
                continue;
            }

            $insert->execute([
                $id,
                null,
                $volume,
                $code,
                'Выдача тома ' . $volume . ($code !== '' && $code !== $volume ? ' / ' . $code : ''),
                (string) (($row['issue_status'] ?? '') ?: ($row['rd_readiness_label'] ?? '') ?: 'Планируется'),
                null,
                $this->dateOrNull($row['rd_date_plan'] ?? ''),
                $row['assignee_id'] ?: null,
                null,
                'Из графика выдачи РД' . (!empty($row['comments']) ? ': ' . $row['comments'] : ''),
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.sections_synced',
            'Разделы обновлены из задач и графика',
            'Добавлено строк: ' . $created . '.'
        );
        flash('success', 'Разделы обновлены из задач и графика. Добавлено строк: ' . $created . '.');
        redirect('/projects/' . $id . '/sections');
    }

    public function syncIssues(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $tasks = $this->db()->prepare('
            SELECT t.id, t.title, t.section, t.discipline, t.status, t.assignee_id, t.reviewer_id, t.btp, s.what, s.why
            FROM tasks t
            LEFT JOIN task_smart s ON s.task_id = t.id
            WHERE t.project_id = ?
              AND (
                    t.status IN ("blocked", "overdue")
                    OR t.title LIKE ?
                    OR COALESCE(s.what, "") LIKE ?
                    OR COALESCE(s.why, "") LIKE ?
              )
            ORDER BY t.date_end IS NULL, t.date_end, t.id
        ');
        $tasks->execute([$id, '%вопрос%', '%вопрос%', '%вопрос%']);

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_issues
            WHERE project_id = ? AND (blocking_task_id = ? OR notes LIKE ?)
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_issues (project_id, blocking_task_id, num, section_code, issue, assignee_id, stage, date_raised, answer, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "", ?, ?)
        ');

        $num = $this->nextNum('project_issues', $id);
        $created = 0;
        foreach ($tasks->fetchAll() as $task) {
            $marker = 'Из задачи #' . $task['id'];
            $exists->execute([$id, (int) $task['id'], '%' . $marker . '%']);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $isBlocked = in_array((string) $task['status'], ['blocked', 'overdue'], true);
            $insert->execute([
                $id,
                (int) $task['id'],
                $num++,
                (string) ($task['section'] ?: $task['discipline']),
                ($isBlocked ? 'Блокер: ' : 'Вопрос: ') . $task['title'],
                $task['reviewer_id'] ?: ($task['assignee_id'] ?: null),
                (string) ($project['stage'] ?? ''),
                date('Y-m-d'),
                $marker . '. ' . trim((string) (($task['why'] ?? '') ?: ($task['btp'] ?? ''))),
                $isBlocked ? 'open' : 'in_progress',
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.issues_synced',
            'Вопросы обновлены из задач',
            'Добавлено строк: ' . $created . '.'
        );
        flash('success', 'Вопросы обновлены из задач. Добавлено строк: ' . $created . '.');
        redirect('/projects/' . $id . '/issues');
    }

    public function syncExchange(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $stmt = $this->db()->prepare('
            SELECT t.id,
                   t.title,
                   t.parent_id,
                   t.section,
                   t.discipline,
                   t.volume,
                   t.status,
                   t.date_start,
                   t.date_end,
                   t.created_at,
                   t.author_id,
                   t.assignee_id,
                   ts.what AS smart_what,
                   a.name AS assignee_name,
                   a.department AS assignee_department,
                   au.name AS author_name,
                   au.department AS author_department,
                   r.name AS reviewer_name,
                   ptask.assignee_id AS parent_assignee_id,
                   ptask.title AS parent_title,
                   ptask.section AS parent_section,
                   ptask.discipline AS parent_discipline,
                   ptask.volume AS parent_volume,
                   ps.linked_section_code,
                   ps.linked_section_title,
                   ps.linked_section_volume
            FROM tasks t
            LEFT JOIN task_smart ts ON ts.task_id = t.id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users au ON au.id = t.author_id
            LEFT JOIN users r ON r.id = t.reviewer_id
            LEFT JOIN tasks ptask ON ptask.id = t.parent_id
            LEFT JOIN (
                SELECT task_id,
                       MIN(code) AS linked_section_code,
                       MIN(title) AS linked_section_title,
                       MIN(volume) AS linked_section_volume
                FROM project_sections
                WHERE task_id IS NOT NULL
                GROUP BY task_id
            ) ps ON ps.task_id = t.id
            WHERE t.project_id = ?
              AND t.task_type = ?
              AND COALESCE(t.title, "") != ""
            ORDER BY t.date_end IS NULL, t.date_end, t.id
        ');
        $stmt->execute([$id, 'assignment']);

        $existing = $this->db()->prepare('
            SELECT id
            FROM project_task_exchange
            WHERE project_id = ? AND task_id = ?
            LIMIT 1
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_task_exchange (project_id, task_id, direction, from_user_id, to_user_id, num, assignment, from_section, to_section, file_url, date_issued, deadline, status, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $update = $this->db()->prepare('
            UPDATE project_task_exchange
            SET direction = ?,
                assignment = ?,
                from_user_id = ?,
                to_user_id = ?,
                from_section = ?,
                to_section = ?,
                file_url = ?,
                date_issued = ?,
                deadline = ?,
                status = ?,
                comments = ?
            WHERE id = ?
        ');

        $num = $this->nextNum('project_task_exchange', $id);
        $created = 0;
        $updated = 0;
        $coveredSections = [];
        foreach ($stmt->fetchAll() as $task) {
            $taskId = (int) $task['id'];
            $fromSection = $this->exchangeSourceLabel($task);
            $toSection = $this->exchangeTargetLabel($task);
            $sectionKey = $this->sectionKey($toSection);
            if ($sectionKey !== '') {
                $coveredSections[$sectionKey] = true;
            }
            $fromUserId = $this->exchangeSourceUserId($task);
            $toUserId = ((int) ($task['assignee_id'] ?? 0)) ?: null;
            $direction = $this->directionFromTaskTitle((string) ($task['title'] ?? ''));
            $assignment = trim((string) ($task['smart_what'] ?? ''));
            $missingAssignment = $assignment === '';
            if ($missingAssignment) {
                $assignment = 'Нет задания: ' . trim((string) $task['title']);
            }
            $fileUrl = $this->exchangeFileUrl($project, $toSection);
            $missingSection = trim($toSection) === '' || $toSection === 'Исполнитель';
            $dateIssued = $this->dateOrNull($task['date_start'] ?? '') ?: $this->dateOrNull(substr((string) ($task['created_at'] ?? ''), 0, 10));
            $deadline = $this->dateOrNull($task['date_end'] ?? '');
            $status = ($missingAssignment || $missingSection) ? 'blocked' : $this->exchangeStatusFromTask((string) $task['status']);
            $comments = $this->exchangeCommentFromTask($task, $missingAssignment, $missingSection);

            $existing->execute([$id, $taskId]);
            $exchangeId = $existing->fetchColumn();
            if ($exchangeId) {
                $update->execute([
                    $direction,
                    $assignment,
                    $fromUserId,
                    $toUserId,
                    $fromSection,
                    $toSection,
                    $fileUrl,
                    $dateIssued,
                    $deadline,
                    $status,
                    $comments,
                    (int) $exchangeId,
                ]);
                $updated++;
                continue;
            }

            $insert->execute([
                $id,
                $taskId,
                $direction,
                $fromUserId,
                $toUserId,
                $num++,
                $assignment,
                $fromSection,
                $toSection,
                $fileUrl,
                $dateIssued,
                $deadline,
                $status,
                $comments,
            ]);
            $created++;
        }

        $blockers = $this->syncMissingAssignmentBlockers($project, $coveredSections, $num);

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.exchange_synced',
            'Обмен заданиями обновлён из задач',
            'Добавлено строк: ' . $created . ', обновлено: ' . $updated . ', блокеров без задания: ' . ($blockers['created'] + $blockers['updated']) . '.'
        );
        flash('success', 'Обмен заданиями обновлён из задач типа «Задание». Добавлено строк: ' . $created . ', обновлено: ' . $updated . ', блокеров без задания: ' . ($blockers['created'] + $blockers['updated']) . '.');
        redirect('/projects/' . $id . '/exchange');
    }

    public function applyExchangeTemplate(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $templateId = (int) ($_POST['template_set_id'] ?? 0);
        if ($templateId <= 0) {
            flash('error', 'Выберите матрицу обмена заданиями.');
            redirect('/projects/' . $id . '/exchange');
        }

        $setStmt = $this->db()->prepare('SELECT * FROM exchange_template_sets WHERE id = ? AND is_active = 1');
        $setStmt->execute([$templateId]);
        $set = $setStmt->fetch();
        if (!$set) {
            flash('error', 'Матрица обмена не найдена или отключена.');
            redirect('/projects/' . $id . '/exchange');
        }

        $itemsStmt = $this->db()->prepare('
            SELECT *
            FROM exchange_template_items
            WHERE template_set_id = ?
            ORDER BY sort_order, id
        ');
        $itemsStmt->execute([$templateId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            flash('error', 'В выбранной матрице нет пунктов.');
            redirect('/projects/' . $id . '/exchange');
        }

        $existing = $this->db()->prepare('
            SELECT id
            FROM project_task_exchange
            WHERE project_id = ? AND template_item_id = ?
            LIMIT 1
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_task_exchange (
                project_id, task_id, template_item_id, direction, num, assignment, from_section, to_section,
                file_url, date_issued, deadline, status, comments
            )
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)
        ');
        $update = $this->db()->prepare('
            UPDATE project_task_exchange
            SET direction = CASE WHEN COALESCE(direction, "") = "" THEN ? ELSE direction END,
                assignment = CASE WHEN COALESCE(assignment, "") = "" OR comments LIKE "Матрица обмена:%" THEN ? ELSE assignment END,
                from_section = CASE WHEN COALESCE(from_section, "") = "" THEN ? ELSE from_section END,
                to_section = CASE WHEN COALESCE(to_section, "") = "" THEN ? ELSE to_section END,
                file_url = CASE WHEN COALESCE(file_url, "") = "" THEN ? ELSE file_url END,
                comments = CASE WHEN COALESCE(comments, "") = "" OR comments LIKE "Матрица обмена:%" THEN ? ELSE comments END
            WHERE id = ?
        ');

        $num = $this->nextNum('project_task_exchange', $id);
        $created = 0;
        $updated = 0;
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $direction = $this->normalizeExchangeDirection((string) ($item['direction'] ?? 'incoming'));
            $fromSection = trim((string) ($item['from_section'] ?? ''));
            $toSection = trim((string) ($item['to_section'] ?? ''));
            $assignment = trim((string) ($item['assignment'] ?? ''));
            $status = in_array((string) ($item['default_status'] ?? 'pending'), ['pending', 'in_progress', 'done', 'blocked'], true)
                ? (string) $item['default_status']
                : 'pending';
            $comment = trim('Матрица обмена: ' . (string) $set['name'] . '. ' . trim((string) ($item['comments'] ?? '')));
            $fileUrl = $this->exchangeFileUrl($project, $toSection);

            $existing->execute([$id, $itemId]);
            $exchangeId = $existing->fetchColumn();
            if ($exchangeId) {
                $update->execute([
                    $direction,
                    $assignment,
                    $fromSection,
                    $toSection,
                    $fileUrl,
                    $comment,
                    (int) $exchangeId,
                ]);
                $updated++;
                continue;
            }

            $insert->execute([
                $id,
                $itemId,
                $direction,
                $num++,
                $assignment,
                $fromSection,
                $toSection,
                $fileUrl,
                $status,
                $comment,
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.exchange_template_applied',
            'Применена матрица обмена заданиями',
            (string) $set['name'] . '. Добавлено строк: ' . $created . ', найдено существующих: ' . $updated . '.'
        );
        flash('success', 'Матрица применена: добавлено строк ' . $created . ', найдено существующих ' . $updated . '.');
        redirect('/projects/' . $id . '/exchange');
    }

    public function syncCosts(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($kind === 'costs' && !PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        $sections = $this->db()->prepare('
            SELECT code, title, volume, comments
            FROM project_sections
            WHERE project_id = ?
            ORDER BY COALESCE(code, volume, title), id
        ');
        $sections->execute([$id]);

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_cost_plan
            WHERE project_id = ?
              AND COALESCE(section_code, "") = ?
              AND COALESCE(work_name, "") = ?
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_cost_plan (
                project_id, num, section_code, sbc_item_id, sbc_collection, sbc_table, work_name, unit,
                labor_hours, labor_estimate_method, labor_productivity_coeff, labor_basis,
                quantity, base_price, stage_percent, complexity_coeff, deflator_coeff, adjustment_coeff,
                planned_cost, price_level, justification, comments
            )
            VALUES (?, ?, ?, NULL, "", "", ?, "раздел", 0, "manual", 1, "", 1, 0, 100, 1, 1, 1, 0, "база СБЦ", ?, ?)
        ');

        $num = $this->nextNum('project_cost_plan', $id);
        $created = 0;
        foreach ($sections->fetchAll() as $section) {
            $sectionCode = trim((string) ($section['code'] ?: $section['volume'] ?: ''));
            $workName = trim((string) ($section['title'] ?: $sectionCode));
            if ($workName === '') {
                continue;
            }

            $exists->execute([$id, $sectionCode, $workName]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                $id,
                $num++,
                $sectionCode,
                $workName,
                'Черновик из перечня разделов проекта: ' . ($sectionCode !== '' ? $sectionCode . ' - ' : '') . $workName . '. Уточните пункт СБЦ, трудозатраты и коэффициенты.',
                'Из перечня разделов. Заполните сборник, таблицу/пункт, базовую цену и коэффициенты.',
            ]);
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.costs_synced',
            'План затрат обновлён из перечня разделов',
            'Добавлено строк: ' . $created . '.'
        );
        flash('success', 'План затрат обновлён из перечня разделов. Добавлено строк: ' . $created . '.');
        redirect('/projects/' . $id . '/costs');
    }

    public function store(int $id, string $kind): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!array_key_exists($kind, self::TABLES)) {
            $this->notFound();
        }
        if (!PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        try {
            match ($kind) {
                'schedule' => $this->storeSchedule($id),
                'sections' => $this->storeSections($id),
                'issues' => $this->storeIssues($id),
                'data' => $this->storeData($id),
                'exchange' => $this->storeExchange($id),
                'costs' => $this->storeCosts($id),
            };
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/projects/' . $id . '/' . $kind);
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.tab_row_added',
            'Строка добавлена: ' . $this->tabTitle($kind),
            null,
            ['kind' => $kind]
        );
        flash('success', 'Строка добавлена.');
        redirect('/projects/' . $id . '/' . $kind);
    }

    public function importCsv(int $id, string $kind): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!array_key_exists($kind, self::TABLES)) {
            $this->notFound();
        }
        if ($kind === 'costs' && !PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }
        $this->ensureCanEditProjectTabs($user, $project);

        try {
            $csvPath = $this->validatedUploadPath(
                $_FILES['csv_file'] ?? null,
                ['csv'],
                ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/comma-separated-values'],
                self::CSV_MAX_BYTES,
                'CSV'
            );
            $rows = $this->csvRows($csvPath, $kind);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/projects/' . $id . '/' . $kind);
        }

        $created = 0;
        if ($kind === 'costs') {
            try {
                $this->validateCostRows($rows);
            } catch (\RuntimeException $e) {
                flash('error', $e->getMessage());
                redirect('/projects/' . $id . '/' . $kind);
            }
        }
        foreach ($rows as $row) {
            if (!$this->rowHasData($row)) {
                continue;
            }
            match ($kind) {
                'schedule' => $this->storeSchedule($id, $row),
                'sections' => $this->storeSections($id, $row),
                'issues' => $this->storeIssues($id, $row),
                'data' => $this->storeData($id, $row),
                'exchange' => $this->storeExchange($id, $row),
                'costs' => $this->storeCosts($id, $row),
            };
            $created++;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.tab_csv_imported',
            'CSV импортирован: ' . $this->tabTitle($kind),
            'Добавлено строк: ' . $created . '.',
            ['kind' => $kind]
        );
        flash('success', 'CSV импортирован. Добавлено строк: ' . $created . '.');
        redirect('/projects/' . $id . '/' . $kind);
    }

    public function costLaborApproval(int $id, int $rowId): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($kind === 'costs' && !PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }
        if ((string) ($project['status'] ?? 'active') === 'archived') {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id . '/costs');
        }
        if (!PermissionService::canApproveLaborEstimates($user)) {
            $this->forbidden();
        }

        $stmt = $this->db()->prepare('SELECT id FROM project_cost_plan WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$rowId, $id]);
        if (!$stmt->fetch()) {
            $this->notFound();
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Некорректное решение по трудозатратам.');
            redirect('/projects/' . $id . '/costs');
        }
        if ($decision === 'rejected' && $comment === '') {
            flash('error', 'Для возврата трудозатрат обязателен комментарий.');
            redirect('/projects/' . $id . '/costs');
        }

        $status = $decision === 'approved' ? 'approved' : 'rejected';
        $this->db()->prepare('
            UPDATE project_cost_plan
            SET labor_approval_status = ?,
                labor_approved_by = ?,
                labor_approved_at = CURRENT_TIMESTAMP,
                labor_approval_comment = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND project_id = ?
        ')->execute([$status, (int) $user['id'], $comment, $rowId, $id]);

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.cost_labor_' . $status,
            $decision === 'approved' ? 'Трудозатраты подтверждены' : 'Трудозатраты возвращены',
            $comment !== '' ? $comment : 'Строка плана затрат #' . $rowId,
            ['row_id' => $rowId]
        );
        flash('success', $decision === 'approved' ? 'Трудозатраты подтверждены.' : 'Трудозатраты возвращены на корректировку.');
        redirect('/projects/' . $id . '/costs');
    }

    private function show(int $id, string $kind): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($kind === 'costs' && !PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }

        $table = self::TABLES[$kind];
        $order = in_array($kind, ['issues', 'data', 'exchange', 'costs'], true) ? 'COALESCE(r.num, r.id), r.id' : 'r.id';
        $hasAssignee = in_array($kind, ['schedule', 'sections', 'issues'], true);
        $selectAssignee = $hasAssignee ? 'u.name AS assignee_name' : 'NULL AS assignee_name';
        $joinAssignee = $hasAssignee ? 'LEFT JOIN users u ON u.id = r.assignee_id' : '';
        $selectTask = '';
        $joinTask = '';
        if ($kind === 'schedule') {
            $selectTask = ', t.title AS linked_task_title, t.status AS linked_task_status';
            $joinTask = 'LEFT JOIN tasks t ON t.id = r.task_id';
        } elseif ($kind === 'sections') {
            $selectTask = ', t.title AS linked_task_title, t.status AS linked_task_status, reviewer_user.name AS reviewer_name';
            $joinTask = 'LEFT JOIN tasks t ON t.id = r.task_id LEFT JOIN users reviewer_user ON reviewer_user.id = r.reviewer_id';
        } elseif ($kind === 'issues') {
            $selectTask = ', t.title AS linked_task_title, t.status AS linked_task_status';
            $joinTask = 'LEFT JOIN tasks t ON t.id = r.blocking_task_id';
        } elseif ($kind === 'exchange') {
            $selectTask = ',
                t.title AS linked_task_title,
                t.status AS linked_task_status,
                fu.name AS from_user_name,
                tu.name AS to_user_name,
                fcp.company AS from_counterparty_company,
                tcp.company AS to_counterparty_company,
                COALESCE(NULLIF(fu.name, ""), NULLIF(fcp.company, ""), NULLIF(r.from_external_name, ""), "") AS from_party_name,
                COALESCE(NULLIF(tu.name, ""), NULLIF(tcp.company, ""), NULLIF(r.to_external_name, ""), "") AS to_party_name
            ';
            $joinTask = '
                LEFT JOIN tasks t ON t.id = r.task_id
                LEFT JOIN users fu ON fu.id = r.from_user_id
                LEFT JOIN users tu ON tu.id = r.to_user_id
                LEFT JOIN counterparties fcp ON fcp.id = r.from_counterparty_id
                LEFT JOIN counterparties tcp ON tcp.id = r.to_counterparty_id
            ';
        } elseif ($kind === 'costs') {
            $selectTask = ',
                si.collection_code AS sbc_ref_collection_code,
                si.edition AS sbc_ref_edition,
                si.table_code AS sbc_ref_table_code,
                si.item_code AS sbc_ref_item_code,
                si.work_name AS sbc_ref_work_name,
                si.base_price AS sbc_ref_base_price,
                labor_user.name AS labor_approved_by_name
            ';
            $joinTask = '
                LEFT JOIN sbc_items si ON si.id = r.sbc_item_id
                LEFT JOIN users labor_user ON labor_user.id = r.labor_approved_by
            ';
        }
        $stmt = $this->db()->prepare("
            SELECT r.*, {$selectAssignee}{$selectTask}
            FROM {$table} r
            {$joinAssignee}
            {$joinTask}
            WHERE r.project_id = ?
            ORDER BY {$order}
        ");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        if ($kind === 'costs') {
            foreach ($rows as &$row) {
                $row['sbc_item_label'] = SbcCatalogService::labelFromCostRow($row);
            }
            unset($row);
        }

        if (($_GET['template'] ?? '') === 'csv') {
            $this->templateCsv($kind, $project['code']);
        }

        if (($_GET['export'] ?? '') === 'csv') {
            $this->exportCsv($kind, $rows, $project['code']);
        }

        $this->render('projects/tab', [
            'title' => $this->tabTitle($kind) . ': ' . $project['code'],
            'project' => $project,
            'kind' => $kind,
            'rows' => $rows,
            'users' => $this->activeUsers(),
            'counterparties' => $kind === 'exchange' ? $this->counterparties() : [],
            'exchangeTemplateSets' => $kind === 'exchange' ? $this->exchangeTemplateSets() : [],
            'projectTasks' => $this->projectTasks($id),
            'dictionaries' => DictionaryService::forTaskForm($id),
            'csvColumns' => self::CSV_COLUMNS[$kind],
            'viewMode' => $kind === 'schedule' && ($_GET['view'] ?? '') === 'board' ? 'board' : 'table',
            'sbcItems' => $kind === 'costs' ? (new SbcCatalogService())->options($this->db()) : [],
            'canApproveLabor' => PermissionService::canApproveLaborEstimates($user),
            'canViewProjectFinance' => PermissionService::canViewProjectFinance($user, $project),
        ]);
    }

    private function storeSchedule(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $stmt = $this->db()->prepare('
            INSERT INTO project_schedule (`project_id`, task_id, volume, `object`, section, object_type, has_id, id_readiness, rd_readiness, rd_readiness_label, rd_date_plan, date_issued, issue_status, rd_correction, assignee_id, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $issueStatus = trim((string) ($source['issue_status'] ?? ($source['rd_readiness_label'] ?? 'Планируется')));
        $stmt->execute([
            $projectId,
            ($source['task_id'] ?? '') !== '' ? (int) $source['task_id'] : null,
            $source['volume'] ?? '',
            $source['object'] ?? '',
            $source['section'] ?? '',
            ($source['object_type'] ?? '') ?: null,
            isset($source['has_id']) ? 1 : 0,
            (int) ($source['id_readiness'] ?? 0),
            (int) ($source['rd_readiness'] ?? 0),
            $issueStatus,
            $this->dateOrNull($source['rd_date_plan'] ?? ''),
            $this->dateOrNull($source['date_issued'] ?? ''),
            $issueStatus,
            $source['rd_correction'] ?? '',
            $this->resolveAssigneeId($source),
            $source['comments'] ?? '',
        ]);
    }

    private function storeSections(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $assigneeId = $this->resolveAssigneeId($source);
        $reviewerId = $this->resolveUserId($source, 'reviewer_id', 'reviewer_name');
        if ($assigneeId !== null && $assigneeId === $reviewerId) {
            throw new \InvalidArgumentException('Исполнитель и проверяющий раздела должны быть разными сотрудниками.');
        }
        $stmt = $this->db()->prepare('
            INSERT INTO project_sections (project_id, task_id, volume, code, title, status, date_start, date_end, assignee_id, reviewer_id, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            ($source['task_id'] ?? '') !== '' ? (int) $source['task_id'] : null,
            $source['volume'] ?? '',
            $source['code'] ?? '',
            $source['title'] ?? '',
            $source['status'] ?? '',
            $this->dateOrNull($source['date_start'] ?? ''),
            $this->dateOrNull($source['date_end'] ?? ''),
            $assigneeId,
            $reviewerId,
            $source['comments'] ?? '',
        ]);
    }

    private function storeIssues(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $stmt = $this->db()->prepare('
            INSERT INTO project_issues (project_id, blocking_task_id, num, section_code, issue, assignee_id, stage, date_raised, answer, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $blockingTaskId = ($source['blocking_task_id'] ?? '') !== '' ? (int) $source['blocking_task_id'] : null;
        $status = $source['status'] ?? 'open';
        $stmt->execute([
            $projectId,
            $blockingTaskId,
            ($source['num'] ?? '') ?: null,
            $source['section_code'] ?? '',
            $source['issue'] ?? '',
            $this->resolveAssigneeId($source),
            $source['stage'] ?? '',
            $this->dateOrNull($source['date_raised'] ?? ''),
            $source['answer'] ?? '',
            $source['notes'] ?? '',
            $status,
        ]);

        if ($blockingTaskId && $status === 'done') {
            $issueNumber = trim((string) ($source['num'] ?? '')) ?: (string) $this->db()->lastInsertId();
            $this->notifyBlockingIssueClosed($blockingTaskId, $issueNumber);
        }
    }

    private function storeData(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $stmt = $this->db()->prepare('
            INSERT INTO project_data_registry (project_id, blocking_task_ids, num, section_code, missing_data, responsible, status, date_requested, date_received_plan, impact, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $source['blocking_task_ids'] ?? '',
            ($source['num'] ?? '') ?: null,
            $source['section_code'] ?? '',
            $source['missing_data'] ?? '',
            $source['responsible'] ?? '',
            $source['status'] ?? 'waiting',
            $this->dateOrNull($source['date_requested'] ?? ''),
            $this->dateOrNull($source['date_received_plan'] ?? ''),
            $source['impact'] ?? '',
            $source['comments'] ?? '',
        ]);
    }

    private function storeExchange(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $assignment = trim((string) ($source['assignment'] ?? ''));
        $toSection = trim((string) ($source['to_section'] ?? ''));
        $status = (string) ($source['status'] ?? 'pending');
        $comments = trim((string) ($source['comments'] ?? ''));
        if ($assignment === '') {
            $status = 'blocked';
            $comments = trim('Блокер: не заполнено задание. ' . $comments);
        }

        $stmt = $this->db()->prepare('
            INSERT INTO project_task_exchange (
                project_id, task_id, direction, from_user_id, from_counterparty_id, from_external_name,
                to_user_id, to_counterparty_id, to_external_name, num, assignment, from_section, to_section,
                file_url, date_issued, deadline, status, comments
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $fromUserId = $this->resolveUserId($source, 'from_user_id', 'from_party_name');
        $fromCounterpartyId = $this->resolveCounterpartyId($source, 'from_counterparty_id', 'from_party_name');
        $toUserId = $this->resolveUserId($source, 'to_user_id', 'to_party_name');
        $toCounterpartyId = $this->resolveCounterpartyId($source, 'to_counterparty_id', 'to_party_name');
        $stmt->execute([
            $projectId,
            ($source['task_id'] ?? '') !== '' ? (int) $source['task_id'] : null,
            $this->normalizeExchangeDirection((string) ($source['direction'] ?? 'outgoing')),
            $fromUserId,
            $fromCounterpartyId,
            $fromUserId || $fromCounterpartyId ? trim((string) ($source['from_external_name'] ?? '')) : trim((string) (($source['from_external_name'] ?? '') ?: ($source['from_party_name'] ?? ''))),
            $toUserId,
            $toCounterpartyId,
            $toUserId || $toCounterpartyId ? trim((string) ($source['to_external_name'] ?? '')) : trim((string) (($source['to_external_name'] ?? '') ?: ($source['to_party_name'] ?? ''))),
            ($source['num'] ?? '') ?: null,
            $assignment,
            $source['from_section'] ?? '',
            $toSection,
            trim((string) ($source['file_url'] ?? '')),
            $this->dateOrNull($source['date_issued'] ?? ''),
            $this->dateOrNull($source['deadline'] ?? ''),
            $status,
            $comments,
        ]);
    }

    private function storeCosts(int $projectId, ?array $source = null): void
    {
        $source ??= $_POST;
        $item = (new CostPlanService())->buildItem($this->db(), $source);

        $stmt = $this->db()->prepare('
            INSERT INTO project_cost_plan (
                project_id, num, section_code, sbc_item_id, sbc_collection, sbc_table, work_name, unit,
                labor_hours, labor_estimate_method, labor_executor_hours, labor_gip_hours, labor_adjustment_hours,
                labor_directive_hours, labor_norm_hours, labor_productivity_rate, labor_productivity_coeff, labor_basis,
                labor_approval_status, labor_submitted_at,
                quantity, base_price, stage_percent, complexity_coeff, deflator_coeff, adjustment_coeff,
                planned_cost, price_level, justification, comments
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_director", CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $item['num'] !== '' ? $item['num'] : $this->nextNum('project_cost_plan', $projectId),
            $item['section_code'],
            $item['sbc_item_id'],
            $item['sbc_collection'],
            $item['sbc_table'],
            $item['work_name'],
            $item['unit'],
            $item['labor_hours'],
            $item['labor_estimate_method'],
            $item['labor_executor_hours'],
            $item['labor_gip_hours'],
            $item['labor_adjustment_hours'],
            $item['labor_directive_hours'],
            $item['labor_norm_hours'],
            $item['labor_productivity_rate'],
            $item['labor_productivity_coeff'],
            $item['labor_basis'],
            $item['quantity'],
            $item['base_price'],
            $item['stage_percent'],
            $item['complexity_coeff'],
            $item['deflator_coeff'],
            $item['adjustment_coeff'],
            $item['planned_cost'],
            $item['price_level'],
            $item['justification'],
            $item['comments'],
        ]);
    }

    private function csvRows(string $path, string $kind): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Не удалось открыть CSV-файл.');
        }

        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!$headers) {
            fclose($handle);
            return [];
        }

        $keys = $this->csvHeaderKeys($kind, $headers);
        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($rows) >= self::CSV_MAX_ROWS) {
                fclose($handle);
                throw new \RuntimeException('CSV содержит слишком много строк.');
            }

            $row = [];
            foreach ($values as $index => $value) {
                $key = $keys[$index] ?? null;
                if ($key === null) {
                    continue;
                }
                $row[$key] = trim((string) $value);
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function csvHeaderKeys(string $kind, array $headers): array
    {
        $aliases = [
            'assignee_id' => 'assignee_id',
            'исполнитель' => 'assignee_name',
            'ответственный' => 'assignee_name',
            'от инженера' => 'from_party_name',
            'от кого' => 'from_party_name',
            'кому' => 'to_party_name',
            'кому / от кого ждём' => 'to_party_name',
            'кому / от кого ждем' => 'to_party_name',
            'тип' => 'direction',
            'комментарии' => 'comments',
        ];
        foreach (self::CSV_COLUMNS[$kind] as $column) {
            $aliases[$this->normalizeCsvHeader($column['key'])] = $column['key'];
            $aliases[$this->normalizeCsvHeader($column['label'])] = $column['key'];
        }

        return array_map(function (string $header) use ($aliases): ?string {
            return $aliases[$this->normalizeCsvHeader($header)] ?? null;
        }, $headers);
    }

    private function normalizeCsvHeader(string $header): string
    {
        $header = trim(str_replace("\xEF\xBB\xBF", '', $header));
        return mb_strtolower($header);
    }

    private function rowHasData(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function validateCostRows(array $rows): void
    {
        $rowNumber = 1;
        $sbcService = new SbcCatalogService();
        foreach ($rows as $row) {
            if (!$this->rowHasData($row)) {
                $rowNumber++;
                continue;
            }

            $sbcItem = $sbcService->findForCostSource($this->db(), $row);
            if (trim((string) ($row['work_name'] ?? '')) === '' && !$sbcItem) {
                throw new \RuntimeException('CSV плана затрат: в строке ' . $rowNumber . ' не заполнена работа.');
            }
            if (trim((string) ($row['justification'] ?? '')) === '' && !$sbcItem) {
                throw new \RuntimeException('CSV плана затрат: в строке ' . $rowNumber . ' не заполнено обоснование.');
            }
            $rowNumber++;
        }
    }

    private function nextNum(string $table, int $projectId): int
    {
        if (!in_array($table, ['project_issues', 'project_data_registry', 'project_task_exchange', 'project_cost_plan'], true)) {
            return 1;
        }

        $stmt = $this->db()->prepare("SELECT COALESCE(MAX(num), 0) + 1 FROM {$table} WHERE project_id = ?");
        $stmt->execute([$projectId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : null;
    }

    private function resolveAssigneeId(array $source): ?int
    {
        return $this->resolveUserId($source, 'assignee_id', 'assignee_name');
    }

    private function resolveUserId(array $source, string $idKey, string $nameKey): ?int
    {
        if (($source[$idKey] ?? '') !== '') {
            return (int) $source[$idKey];
        }

        $name = trim((string) ($source[$nameKey] ?? ''));
        if ($name === '') {
            return null;
        }

        $stmt = $this->db()->prepare('SELECT id FROM users WHERE name = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function resolveCounterpartyId(array $source, string $idKey, string $nameKey): ?int
    {
        if (($source[$idKey] ?? '') !== '') {
            return (int) $source[$idKey];
        }

        $company = trim((string) ($source[$nameKey] ?? ''));
        if ($company === '') {
            return null;
        }

        $stmt = $this->db()->prepare('SELECT id FROM counterparties WHERE company = ? LIMIT 1');
        $stmt->execute([$company]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function normalizeExchangeDirection(string $direction): string
    {
        $direction = mb_strtolower(trim($direction), 'UTF-8');
        if (in_array($direction, ['incoming', 'входящее', 'ждём', 'ждем', 'получить'], true)) {
            return 'incoming';
        }

        return 'outgoing';
    }

    private function directionFromTaskTitle(string $title): string
    {
        $title = mb_strtolower(trim($title), 'UTF-8');
        if (str_starts_with($title, 'запрос') || str_contains($title, 'получить задание') || str_contains($title, 'ждём задание') || str_contains($title, 'ждем задание')) {
            return 'incoming';
        }

        return 'outgoing';
    }

    private function project(int $id, array $user): ?array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            $stmt = $this->db()->prepare('SELECT * FROM projects WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }

        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.*
            FROM projects p
            WHERE p.id = :project_id AND ' . $where . '
        ');
        $stmt->execute(['project_id' => $id] + $params);

        return $stmt->fetch() ?: null;
    }

    private function activeUsers(): array
    {
        return $this->db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    private function counterparties(): array
    {
        return $this->db()->query('SELECT id, company, role, representative FROM counterparties ORDER BY company, role, representative')->fetchAll();
    }

    private function exchangeTemplateSets(): array
    {
        return $this->db()->query('
            SELECT s.*, COALESCE(ic.items_count, 0) AS items_count
            FROM exchange_template_sets s
            LEFT JOIN (
                SELECT template_set_id, COUNT(*) AS items_count
                FROM exchange_template_items
                GROUP BY template_set_id
            ) ic ON ic.template_set_id = s.id
            WHERE s.is_active = 1
            ORDER BY s.sort_order, s.name, s.id
        ')->fetchAll();
    }

    private function projectTasks(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT id, title, status, volume, section, discipline
            FROM tasks
            WHERE project_id = ?
            ORDER BY date_end IS NULL, date_end, id
            LIMIT 500
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private function notifyBlockingIssueClosed(int $taskId, string $issueNumber): void
    {
        $stmt = $this->db()->prepare('SELECT id, assignee_id FROM tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task || !$task['assignee_id']) {
            return;
        }

        TaskWorkflowService::notify((int) $task['assignee_id'], $taskId, 'project_issue_closed', 'Вопрос #' . $issueNumber . ' по задаче #' . $taskId . ' закрыт.');
    }

    private function tabTitle(string $kind): string
    {
        return [
            'schedule' => 'График выдачи РД',
            'sections' => 'Перечень разделов',
            'issues' => 'Вопросы / блокеры',
            'data' => 'Реестр исходных данных',
            'exchange' => 'Обмен заданиями',
            'costs' => 'План затрат',
        ][$kind] ?? 'Вкладка проекта';
    }

    private function issueStatusFromTask(string $status): string
    {
        return match ($status) {
            'done' => 'Выдано',
            'overdue', 'blocked' => 'Проблема',
            'correction' => 'Замечания',
            'review', 'pending_close' => 'На проверке',
            'in_progress' => 'В работе',
            default => 'Планируется',
        };
    }

    private function exchangeStatusFromTask(string $status): string
    {
        return match ($status) {
            'done', 'issued' => 'done',
            'blocked', 'overdue', 'correction' => 'blocked',
            'new', 'review', 'pending_close' => 'pending',
            default => 'in_progress',
        };
    }

    private function exchangeSourceLabel(array $task): string
    {
        foreach (['parent_section', 'parent_discipline', 'parent_volume', 'author_department'] as $key) {
            $value = trim((string) ($task[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Постановщик';
    }

    private function exchangeTargetLabel(array $task): string
    {
        foreach (['linked_section_code', 'section', 'discipline', 'linked_section_volume', 'volume', 'assignee_department'] as $key) {
            $value = trim((string) ($task[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Исполнитель';
    }

    private function exchangeSourceUserId(array $task): ?int
    {
        foreach (['parent_assignee_id', 'author_id'] as $key) {
            $id = (int) ($task[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    private function exchangeCommentFromTask(array $task, bool $missingAssignment = false, bool $missingSection = false): string
    {
        $parts = ['Из задачи #' . (int) $task['id']];
        if ($missingAssignment) {
            $parts[] = 'Блокер: не заполнена постановка "Что сделать"';
        }
        if ($missingSection) {
            $parts[] = 'Блокер: не указан раздел для папки задания';
        }
        if (!empty($task['parent_id'])) {
            $parts[] = 'источник #' . (int) $task['parent_id'] . ': ' . trim((string) ($task['parent_title'] ?? ''));
        }
        if (!empty($task['author_name'])) {
            $parts[] = 'постановщик: ' . trim((string) $task['author_name']);
        }
        if (!empty($task['assignee_name'])) {
            $parts[] = 'исполнитель: ' . trim((string) $task['assignee_name']);
        }
        if (!empty($task['reviewer_name'])) {
            $parts[] = 'проверяющий: ' . trim((string) $task['reviewer_name']);
        }

        return implode('; ', array_filter($parts));
    }

    private function exchangeFileUrl(array $project, string $section): string
    {
        $root = trim((string) ($project['file_folder_url'] ?? ''));
        $section = trim($section);
        if ($root === '' || $section === '' || $section === 'Исполнитель') {
            return '';
        }

        $stage = in_array((string) ($project['stage'] ?? ''), ['ПД', 'П'], true) ? 'Стадия_П' : 'Стадия_Р';
        $sectionFolder = $this->fileSafeSegment($section);

        return file_path_join($root, '02_Общие_данные (SHARED)/' . $stage . '/F_ЗАДАНИЯ_Исходящие/' . $sectionFolder);
    }

    private function fileSafeSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '_', $value) ?? '';
        $value = preg_replace('/\s+/u', '_', $value) ?? '';
        $value = trim($value, '._ ');

        return $value !== '' ? $value : 'Без_раздела';
    }

    /**
     * @param array<string, bool> $coveredSections
     * @return array{created: int, updated: int, removed: int}
     */
    private function syncMissingAssignmentBlockers(array $project, array $coveredSections, int &$num): array
    {
        $projectId = (int) $project['id'];
        $stmt = $this->db()->prepare('
            SELECT code, title, volume, date_end, assignee_id
            FROM project_sections
            WHERE project_id = ?
            ORDER BY COALESCE(code, title, volume), id
        ');
        $stmt->execute([$projectId]);

        $existing = $this->db()->prepare('
            SELECT id
            FROM project_task_exchange
            WHERE project_id = ?
              AND task_id IS NULL
              AND to_section = ?
              AND comments LIKE ?
            LIMIT 1
        ');
        $insert = $this->db()->prepare('
            INSERT INTO project_task_exchange (project_id, task_id, from_user_id, to_user_id, num, assignment, from_section, to_section, file_url, date_issued, deadline, status, comments)
            VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
        ');
        $update = $this->db()->prepare('
            UPDATE project_task_exchange
            SET to_user_id = ?,
                assignment = ?,
                from_section = ?,
                file_url = ?,
                deadline = ?,
                status = ?,
                comments = ?
            WHERE id = ?
        ');
        $delete = $this->db()->prepare('
            DELETE FROM project_task_exchange
            WHERE project_id = ?
              AND task_id IS NULL
              AND to_section = ?
              AND comments LIKE ?
        ');

        $blockerCommentLike = 'Системный блокер:%';
        $created = 0;
        $updated = 0;
        $removed = 0;
        $seen = [];
        foreach ($stmt->fetchAll() as $sectionRow) {
            $section = $this->sectionFromRow($sectionRow);
            $sectionKey = $this->sectionKey($section);
            if ($sectionKey === '' || isset($seen[$sectionKey])) {
                continue;
            }
            $seen[$sectionKey] = true;

            if (isset($coveredSections[$sectionKey])) {
                $delete->execute([$projectId, $section, $blockerCommentLike]);
                $removed += $delete->rowCount();
                continue;
            }

            $assignment = 'Нет задания по разделу: ' . $section;
            $fromSection = 'Нет задания';
            $fileUrl = $this->exchangeFileUrl($project, $section);
            $deadline = $this->dateOrNull($sectionRow['date_end'] ?? '');
            $comment = 'Системный блокер: отсутствует задача типа "Задание" для раздела ' . $section . '. Создайте задачу с типом «Задание».';

            $existing->execute([$projectId, $section, $blockerCommentLike]);
            $exchangeId = $existing->fetchColumn();
            if ($exchangeId) {
                $update->execute([
                    ((int) ($sectionRow['assignee_id'] ?? 0)) ?: null,
                    $assignment,
                    $fromSection,
                    $fileUrl,
                    $deadline,
                    'blocked',
                    $comment,
                    (int) $exchangeId,
                ]);
                $updated++;
                continue;
            }

            $insert->execute([
                $projectId,
                ((int) ($sectionRow['assignee_id'] ?? 0)) ?: null,
                $num++,
                $assignment,
                $fromSection,
                $section,
                $fileUrl,
                $deadline,
                'blocked',
                $comment,
            ]);
            $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'removed' => $removed];
    }

    private function sectionFromRow(array $row): string
    {
        foreach (['code', 'title', 'volume'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function sectionKey(string $section): string
    {
        return mb_strtolower(trim($section), 'UTF-8');
    }

    private function exportCsv(string $kind, array $rows, string $projectCode): never
    {
        $columns = self::CSV_COLUMNS[$kind];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $projectCode . '_' . $kind) . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn (array $column): string => $column['label'], $columns), ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (array $column): mixed => $row[$column['key']] ?? '', $columns), ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private function templateCsv(string $kind, string $projectCode): never
    {
        $columns = self::CSV_COLUMNS[$kind];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $projectCode . '_' . $kind . '_template') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn (array $column): string => $column['label'], $columns), ';', '"', '\\');
        fclose($out);
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Раздел не найден', 'message' => 'Проектная вкладка недоступна.']);
        exit;
    }

    private function ensureCanEditProjectTabs(array $user, array $project): void
    {
        if (PermissionService::canEditProjectTabs($user, $project)) {
            return;
        }

        $this->forbidden();
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для изменения проектных вкладок.']);
        exit;
    }
}
