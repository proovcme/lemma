<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\PersonalNoteService;

final class NotesController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        $projects = $this->projectsFor($user);

        $this->render('notes/index', [
            'title' => 'Заметки',
            'subtitle' => 'Личное рабочее пространство: черновики, мысли, будущие задачи.',
            'headerActions' => [
                ['label' => '+ Заметка', 'href' => '/notes/new', 'class' => 'btn-red'],
            ],
            'notes' => PersonalNoteService::list($this->db(), (int) $user['id'], $_GET),
            'projects' => $projects,
            'filters' => $_GET,
        ]);
    }

    public function create(): void
    {
        $user = require_auth();
        $this->render('notes/form', [
            'title' => 'Новая заметка',
            'subtitle' => 'Сохраняется только для вас. В задачу превращается отдельным действием.',
            'headerActions' => [
                ['label' => 'К заметкам', 'href' => '/notes', 'class' => 'btn-outline'],
                ['label' => 'Сохранить', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'note-form', 'class' => 'btn-red'],
            ],
            'note' => null,
            'projects' => $this->projectsFor($user),
            'users' => $this->activeUsers(),
        ]);
    }

    public function store(): void
    {
        $user = require_auth();

        try {
            $id = PersonalNoteService::create($this->db(), (int) $user['id'], $_POST);
            flash('success', 'Заметка сохранена.');
            redirect('/notes/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/notes/new');
        }
    }

    public function show(int $id): void
    {
        $user = require_auth();
        $note = PersonalNoteService::find($this->db(), $id, (int) $user['id']);
        if (!$note) {
            $this->notFound();
        }

        $this->render('notes/form', [
            'title' => 'Заметка',
            'subtitle' => 'Личный черновик, который можно превратить в задачу.',
            'headerActions' => [
                ['label' => 'К заметкам', 'href' => '/notes', 'class' => 'btn-outline'],
                ['label' => 'Сохранить', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'note-form', 'class' => 'btn-red'],
            ],
            'note' => $note,
            'projects' => $this->projectsFor($user),
            'users' => $this->activeUsers(),
        ]);
    }

    public function update(int $id): void
    {
        $user = require_auth();

        try {
            PersonalNoteService::update($this->db(), $id, (int) $user['id'], $_POST);
            flash('success', 'Заметка обновлена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/notes/' . $id);
    }

    public function status(int $id): void
    {
        $user = require_auth();
        $status = (string) ($_POST['status'] ?? 'active');

        try {
            PersonalNoteService::setStatus($this->db(), $id, (int) $user['id'], $status);
            flash('success', $status === 'archived' ? 'Заметка отправлена в архив.' : 'Заметка восстановлена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/notes');
    }

    public function convert(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canCreateTasks($user)) {
            $this->forbidden();
        }

        $note = PersonalNoteService::find($this->db(), $id, (int) $user['id']);
        if (!$note) {
            $this->notFound();
        }

        try {
            $projectId = (int) ($_POST['project_id'] ?? ($note['project_id'] ?? 0));
            if ($projectId > 0 && !in_array($projectId, $this->visibleProjectIds($user), true)) {
                throw new \InvalidArgumentException('Проект недоступен для постановки задачи.');
            }
            $taskId = PersonalNoteService::convertToTask($this->db(), $note, (int) $user['id'], $_POST);
            flash('success', 'Создана задача #' . $taskId . ' из заметки.');
            redirect('/tasks/' . $taskId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/notes/' . $id);
        }
    }

    private function projectsFor(array $user): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query('SELECT id, code, title FROM projects WHERE status = "active" ORDER BY code')->fetchAll();
        }

        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.id, p.code, p.title
            FROM projects p
            WHERE p.status = "active"
              AND ' . $where . '
            ORDER BY p.code
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return int[]
     */
    private function visibleProjectIds(array $user): array
    {
        return array_map(static fn (array $project): int => (int) $project['id'], $this->projectsFor($user));
    }

    private function activeUsers(): array
    {
        return $this->db()->query('SELECT id, name, role, department FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    private function forbidden(): void
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для этого действия.']);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Не найдено', 'message' => 'Заметка не найдена.']);
        exit;
    }
}
