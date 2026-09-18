<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\BudgetService;
use App\Services\DictionaryService;
use App\Services\MspImportService;
use App\Services\ModelFolderService;
use App\Services\PermissionService;
use App\Services\ProcessControlService;
use App\Services\ProjectAccountingService;
use App\Services\ProjectControlService;
use App\Services\ProjectFolderService;
use App\Services\ProjectManagementTaskService;
use App\Services\ProjectStructureService;
use App\Services\ProjectTeamStructureService;
use App\Services\ProjectTemplateService;
use App\Services\PublicLinkService;
use App\Services\RoleService;
use App\Services\RevitIntegrationService;
use App\Services\TagService;
use App\Services\TaskWorkflowService;

final class ProjectController extends BaseController
{
    private const MSP_IMPORT_MAX_BYTES = 64 * 1024 * 1024;

    public function index(): void
    {
        $user = require_auth();
        if (!PermissionService::canOpenProjects($user)) {
            $this->forbidden();
        }

        $this->render('projects/index', [
            'title' => 'Проекты',
            'projects' => $this->projectsFor($user),
            'canCreate' => PermissionService::canCreateProject($user),
            'isArchive' => false,
        ]);
    }

    public function archived(): void
    {
        $user = require_auth();
        if (!PermissionService::canSeeAllProjects($user)) {
            $this->forbidden();
        }

        $this->render('projects/index', [
            'title' => 'Архив проектов',
            'projects' => $this->projectsFor($user, true),
            'canCreate' => PermissionService::canCreateProject($user),
            'isArchive' => true,
        ]);
    }

    public function create(): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $formState = $this->consumeProjectFormState('new');
        $this->render('projects/form', [
            'title' => 'Новый проект',
            'project' => $formState['data'] ?: null,
            'formErrors' => $formState['errors'],
            'user' => $user,
            'users' => $this->activeUsers(),
            'structureCatalog' => (new ProjectStructureService($this->db()))->catalog(),
        ]);
    }

    public function store(): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $data = $this->payload();
        $errors = $this->projectFormErrors($data, false, true);
        if ($errors) {
            $this->rememberProjectFormState('new', $data, $errors);
            flash('error', 'Проект не сохранён. Исправьте отмеченные поля — введённые данные сохранены.');
            redirect('/projects/new');
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
        $stmt = $db->prepare('
            INSERT INTO projects (kind, code, title, `object`, address, object_type, area_m2, stages_text, pp, stage, start_date, finish_date, status, color, speckle_stream_url, file_folder_url, model_folder_url, budget_manual_thousand, budget_cost_thousand, budget_profit_thousand, budget_bonus_thousand, gip_user_id, rp_user_id)
            VALUES ("project", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "", ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['code'],
            $data['title'],
            $data['object'],
            $data['address'],
            $data['object_type'],
            $data['area_m2'],
            $data['stages_text'],
            $data['pp'],
            $data['stage'],
            $data['start_date'],
            $data['finish_date'],
            $data['status'],
            $data['color'],
            $data['speckle_stream_url'],
            $data['model_folder_url'],
            $data['budget_total_thousand'],
            $data['budget_cost_thousand'],
            $data['budget_profit_thousand'],
            $data['budget_bonus_thousand'],
            $data['gip_user_id'],
            $data['rp_user_id'],
        ]);
        $projectId = (int) $db->lastInsertId();

        $structureService = new ProjectStructureService($db);
        $structureService->createForProject($projectId, [
            'stage_codes' => $data['structure_stage_codes'],
            'stage_templates' => $data['structure_stage_templates'],
            'activity_codes' => $data['structure_activity_codes'],
        ]);

        (new ProjectManagementTaskService($this->db()))->ensure(
            $projectId,
            (int) $data['gip_user_id'],
            (int) $user['id'],
            (int) $data['rp_user_id']
        );
        $this->syncProjectRoleMembers($projectId, (int) ($data['gip_user_id'] ?? 0), (int) ($data['rp_user_id'] ?? 0));
        ActivityLogService::recordProject(
            $projectId,
            (int) $user['id'],
            'project.created',
            'Проект создан',
            $data['code'] . ' · ' . $data['title']
        );
        $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            $message = $error instanceof \InvalidArgumentException
                ? $error->getMessage()
                : 'Не удалось создать структуру проекта. Повторите сохранение или передайте администратору время ошибки.';
            $this->rememberProjectFormState('new', $data, ['structure' => $message]);
            flash('error', 'Проект не создан. Проверьте структуру и повторите сохранение.');
            redirect('/projects/new');
        }

        flash('success', 'Проект создан.');
        redirect('/projects/' . $projectId);
    }

    public function show(int $id): void
    {
        $user = require_auth();
        TaskWorkflowService::markOverdue();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $isArchived = $this->isArchived($project);
        $canImportMsp = !$isArchived && $this->canImportMsp($user);
        $canArchive = $this->canArchiveProject($user);
        $currentUserId = (int) $user['id'];
        $modelLinks = $this->projectModelLinks($id, $currentUserId);
        $modelFolderScan = $this->modelFolderScan($id);
        $folderModels = $this->folderModels($id, $currentUserId, $modelFolderScan);
        $canManageModelLinks = PermissionService::canManageProjectModels($user, $project);
        $canViewProjectStats = PermissionService::canViewProjectStats($user, $project);
        $canViewProjectFinance = PermissionService::canViewProjectFinance($user, $project);
        $canManageDepartmentBudget = PermissionService::canManageDepartmentBudget($user);
        $projectControl = $canViewProjectStats
            ? (new ProjectControlService($this->db()))->build($id, $canViewProjectFinance)
            : [];
        $processControl = $canViewProjectStats
            ? (new ProcessControlService($this->db()))->project($id)
            : [];
        $atlasFederationHref = $this->atlasFederationHref($id, $modelLinks, $folderModels);
        $canEditProject = !$isArchived && PermissionService::canCreateProject($user);
        $editMode = $canEditProject && ($_GET['edit'] ?? '') === '1';
        $formState = $editMode ? $this->consumeProjectFormState((string) $id) : ['data' => [], 'errors' => []];
        if ($formState['data']) {
            $project = array_replace($project, $formState['data']);
        }
        $headerActions = [];
        if (!$editMode && $canViewProjectStats) {
            $headerActions[] = ['label' => 'Что у нас плохого', 'href' => '/projects/' . (int) $project['id'] . '/health-report', 'class' => 'btn-outline'];
        }
        if ($editMode) {
            $headerActions[] = ['label' => 'Просмотр', 'href' => '/projects/' . (int) $project['id'], 'class' => 'btn-outline'];
            $headerActions[] = ['label' => 'Сохранить проект', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'project-edit', 'class' => 'btn-red'];
        } elseif ($canEditProject) {
            $headerActions[] = ['label' => 'Редактировать проект', 'href' => '/projects/' . (int) $project['id'] . '?edit=1', 'class' => 'btn-red'];
        }
        if (!$isArchived && $canArchive) {
            $headerActions[] = [
                'label' => 'В архив',
                'type' => 'form',
                'action' => '/projects/' . (int) $project['id'] . '/archive',
                'class' => 'btn-outline',
                'confirm' => 'Перевести проект в архив? Все задачи станут только для чтения.',
            ];
        }
        if ($isArchived && $canArchive) {
            $headerActions[] = ['label' => 'Восстановить', 'type' => 'form', 'action' => '/projects/' . (int) $project['id'] . '/restore', 'class' => 'btn-outline'];
            $headerActions[] = ['label' => 'Создать проект из шаблона', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'project-clone', 'class' => 'btn-red'];
        }

        $members = $this->members($id);
        $memberUserIds = array_map(static fn (array $member): int => (int) ($member['user_id'] ?? 0), $members);

        $this->render('projects/show', [
            'title' => $project['code'] . ' ' . $project['title'],
            'headerActions' => $headerActions,
            'project' => $project,
            'formErrors' => $formState['errors'],
            'summary' => $canViewProjectStats ? $this->summary($id) : [],
            'financeSummary' => $canViewProjectFinance ? $this->financeSummary($id) : [],
            'projectControl' => $projectControl,
            'processControl' => $processControl,
            'projectTasks' => $this->tasks($id, $user),
            'contacts' => $this->contacts($id),
            'users' => $this->activeUsers([
                (int) ($project['gip_user_id'] ?? 0),
                (int) ($project['rp_user_id'] ?? 0),
                ...$memberUserIds,
            ]),
            'canEdit' => $canEditProject,
            'editMode' => $editMode,
            'canImportMsp' => $canImportMsp,
            'mspImportResult' => $_SESSION['msp_import_result_' . $id] ?? null,
            'canArchive' => $canArchive,
            'isArchived' => $isArchived,
            'modelLinks' => $modelLinks,
            'revitModels' => (new RevitIntegrationService($this->db()))->modelSeriesWithVersions($id),
            'folderModels' => $folderModels,
            'modelFolderScan' => $modelFolderScan,
            'projectPublicUrl' => PublicLinkService::publicUrl(PublicLinkService::ensureProjectLink($id, (string) $project['code'] . ' ' . (string) $project['title'], $currentUserId)),
            'atlasFederationHref' => $atlasFederationHref,
            'canManageModelLinks' => $canManageModelLinks,
            'canViewProjectStats' => $canViewProjectStats,
            'canViewProjectFinance' => $canViewProjectFinance,
            'canManageDepartmentBudget' => $canManageDepartmentBudget,
            'projectPayments' => $canManageDepartmentBudget ? (new BudgetService($this->db()))->payments($id) : [],
            'autoPrepareModels' => ($_GET['prepare_models'] ?? '') === '1',
            'members' => $members,
            'activityLogs' => ActivityLogService::forProject($id),
        ]);
        unset($_SESSION['msp_import_result_' . $id]);
    }

    public function mspImport(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }
        if (!$this->canImportMsp($user)) {
            $this->forbidden();
        }

        try {
            $file = $_FILES['msp_file'] ?? ($_FILES['msp_xml'] ?? null);
            $sourcePath = $this->validatedUploadPath(
                $file,
                ['xml', 'mspdi', 'mpp'],
                ['application/xml', 'text/xml', 'text/plain', 'application/vnd.ms-office', 'application/octet-stream'],
                self::MSP_IMPORT_MAX_BYTES,
                'MS Project XML/MPP'
            );

            $service = new MspImportService();
            $_SESSION['msp_import_result_' . $id] = $service->importFile($id, $sourcePath, (string) ($file['name'] ?? ''), (int) $user['id']);
            $result = $_SESSION['msp_import_result_' . $id];
            ActivityLogService::recordProject(
                $id,
                (int) $user['id'],
                'project.msp_imported',
                'Импортирован график MS Project',
                'Создано ' . (int) ($result['created'] ?? 0) . ', обновлено ' . (int) ($result['updated'] ?? 0) . '.'
            );
            flash('success', 'График MS Project импортирован. Повторная загрузка обновляет найденные задачи и не удаляет отсутствующие строки.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/projects/' . $id);
    }

    public function tasksPage(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $query = array_filter($_GET, static fn ($value): bool => !is_array($value) && (string) $value !== '');
        $query['project_id'] = (int) $project['id'];

        redirect('/tasks/all?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function assistant(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!PermissionService::canCreateTasks($user)) {
            $this->forbidden();
        }

        $isArchived = $this->isArchived($project);
        $this->render('projects/assistant', [
            'title' => 'Помощник: ' . $project['code'],
            'headerActions' => [
                ['label' => 'К проекту', 'href' => '/projects/' . (int) $project['id'], 'class' => 'btn-outline'],
                ['label' => 'Задачи', 'href' => '/projects/' . (int) $project['id'] . '/tasks', 'class' => 'btn-outline'],
            ],
            'project' => $project,
            'isArchived' => $isArchived,
            'canViewProjectFinance' => PermissionService::canViewProjectFinance($user, $project),
        ]);
    }

    public function storeBudget(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }
        if (!PermissionService::canViewProjectFinance($user, $project)) {
            $this->forbidden();
        }

        $parts = $this->budgetPayload();
        if (!$this->validBudget($parts)) {
            flash('error', $this->budgetErrorMessage($parts));
            redirect('/projects/' . $id . '#project-budget');
        }
        $comment = trim((string) ($_POST['budget_comment'] ?? ''));
        $this->db()->prepare('
            UPDATE projects
            SET budget_manual_thousand = ?, budget_cost_thousand = ?, budget_profit_thousand = ?, budget_bonus_thousand = ?, budget_comment = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $parts['budget_total_thousand'],
            $parts['budget_cost_thousand'],
            $parts['budget_profit_thousand'],
            $parts['budget_bonus_thousand'],
            $comment !== '' ? $comment : null,
            $id,
        ]);

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.budget_saved',
            'Бюджет проекта обновлён',
            rtrim(rtrim(number_format($parts['budget_total_thousand'], 2, '.', ' '), '0'), '.') . ' тыс. руб.'
        );
        flash('success', 'Бюджет проекта сохранён.');
        redirect('/projects/' . $id . '#project-budget');
    }

    public function seedTaskTemplate(int $id, string $type): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project || !in_array($type, ['pd87', 'rd21'], true)) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id . '/tasks');
        }
        if (!PermissionService::canEditProjectTabs($user, $project)) {
            $this->forbidden();
        }

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM tasks
            WHERE project_id = ? AND COALESCE(section, "") = ? AND title = ?
        ');
        $insertTask = $this->db()->prepare('
            INSERT INTO tasks (
                title, project_id, parent_id, assignee_id, author_id, reviewer_id, discipline, volume, section,
                status, priority, urgency, date_start, date_end, date_end_original, planned_hours, progress, btp, speckle_stream_url
            )
            VALUES (
                ?, ?, NULL, NULL, ?, NULL, ?, ?, ?,
                "new", "mid", "mid", NULL, NULL, NULL, NULL, 0, "", ""
            )
        ');
        $insertSmart = $this->db()->prepare('
            INSERT INTO task_smart (task_id, what, when_due, why, depends_on)
            VALUES (?, ?, ?, ?, "")
        ');

        $created = 0;
        foreach (ProjectTemplateService::taskTemplates($type) as $template) {
            $exists->execute([$id, (string) $template['section'], (string) $template['title']]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $insertTask->execute([
                (string) $template['title'],
                $id,
                (int) $user['id'],
                (string) $template['discipline'],
                (string) $template['volume'],
                (string) $template['section'],
            ]);
            $taskId = (int) $this->db()->lastInsertId();
            $insertSmart->execute([
                $taskId,
                (string) $template['what'],
                'Назначить срок и исполнителя',
                (string) $template['why'],
            ]);
            $created++;
        }

        flash('success', 'Шаблонные задачи добавлены. Новых задач: ' . $created . '.');
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.template_tasks',
            'Добавлены шаблонные задачи',
            'Тип шаблона: ' . $type . '. Новых задач: ' . $created . '.'
        );
        redirect('/projects/' . $id . '/tasks');
    }

    public function update(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }

        $data = $this->payload();
        $budgetUnchanged = $this->budgetMatchesProject($data, $project);
        $errors = $this->projectFormErrors($data, $budgetUnchanged, false);
        if ($errors) {
            $this->rememberProjectFormState((string) $id, $data, $errors);
            flash('error', 'Проект не сохранён. Исправьте отмеченные поля — введённые данные сохранены.');
            redirect('/projects/' . $id . '?edit=1');
        }

        $stmt = $this->db()->prepare('
            UPDATE projects
            SET code = ?, title = ?, `object` = ?, address = ?, object_type = ?, area_m2 = ?, stages_text = ?,
                pp = ?, stage = ?, start_date = ?, finish_date = ?, status = ?, color = ?, speckle_stream_url = ?, model_folder_url = ?, budget_manual_thousand = ?, budget_cost_thousand = ?, budget_profit_thousand = ?, budget_bonus_thousand = ?, gip_user_id = ?, rp_user_id = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['code'],
            $data['title'],
            $data['object'],
            $data['address'],
            $data['object_type'],
            $data['area_m2'],
            $data['stages_text'],
            $data['pp'],
            $data['stage'],
            $data['start_date'],
            $data['finish_date'],
            $data['status'],
            $data['color'],
            $data['speckle_stream_url'],
            $data['model_folder_url'],
            $data['budget_total_thousand'],
            $data['budget_cost_thousand'],
            $data['budget_profit_thousand'],
            $data['budget_bonus_thousand'],
            $data['gip_user_id'],
            $data['rp_user_id'],
            $id,
        ]);

        (new ProjectManagementTaskService($this->db()))->ensure(
            $id,
            (int) $data['gip_user_id'],
            (int) $user['id'],
            $data['rp_user_id'] !== null ? (int) $data['rp_user_id'] : null
        );
        $this->syncProjectRoleMembers($id, (int) ($data['gip_user_id'] ?? 0), (int) ($data['rp_user_id'] ?? 0));
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.updated',
            'Паспорт проекта обновлён',
            $data['code'] . ' · ' . $data['title']
        );

        flash('success', 'Проект обновлён.');
        redirect('/projects/' . $id);
    }

    public function createFolders(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }
        if (!PermissionService::canEditProjectTabs($user, $project)) {
            $this->forbidden();
        }

        $this->flashFolderStructureResult((string) ($project['file_folder_url'] ?? ''), 'Структура папок обработана.');
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.folders_created',
            'Структура папок обработана',
            (string) ($project['file_folder_url'] ?? '')
        );
        redirect('/projects/' . $id);
    }

    public function openFolder(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $wantsJson = $this->wantsJsonResponse();
        try {
            $openedPath = (new ProjectFolderService())->open(
                (string) ($project['file_folder_url'] ?? ''),
                (string) ($_POST['path'] ?? '')
            );
            if ($wantsJson) {
                json_response(['ok' => true, 'path' => $openedPath, 'message' => 'Путь к папке проверен.']);
            }
            flash('success', 'Путь к папке проверен: ' . $openedPath);
        } catch (\RuntimeException $e) {
            if ($wantsJson) {
                json_response(['ok' => false, 'message' => 'Не удалось открыть папку: ' . $e->getMessage()], 422);
            }
            flash('error', 'Не удалось открыть папку: ' . $e->getMessage());
        }

        redirect('/projects/' . $id);
    }

    private function wantsJsonResponse(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    public function storeContact(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }

        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        if ($fullName === '') {
            flash('error', 'ФИО контакта обязательно.');
            redirect('/projects/' . $id);
        }

        $stmt = $this->db()->prepare('
            INSERT INTO project_contacts (project_id, full_name, contact, organization, position)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $id,
            $fullName,
            trim((string) ($_POST['contact'] ?? '')),
            trim((string) ($_POST['organization'] ?? '')),
            trim((string) ($_POST['position'] ?? '')),
        ]);

        flash('success', 'Контакт добавлен.');
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.contact_added',
            'Контакт добавлен',
            $fullName
        );
        redirect('/projects/' . $id);
    }

    public function deleteContact(int $id, int $contactId): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id);
        }

        $stmt = $this->db()->prepare('DELETE FROM project_contacts WHERE id = ? AND project_id = ?');
        $stmt->execute([$contactId, $id]);

        flash('success', 'Контакт удалён.');
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.contact_deleted',
            'Контакт удалён',
            'ID контакта: ' . $contactId
        );
        redirect('/projects/' . $id);
    }

    public function storeModelLink(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!PermissionService::canManageProjectModels($user, $project)) {
            $this->forbidden();
        }

        $modelUrl = trim((string) ($_POST['model_url'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($modelUrl === '') {
            flash('error', 'Укажите постоянную ссылку или путь к модели.');
            redirect('/projects/' . $id);
        }
        if (preg_match('/[\x00-\x1F]/', $modelUrl)) {
            flash('error', 'Ссылка на модель содержит недопустимые символы.');
            redirect('/projects/' . $id);
        }
        if ($title === '') {
            $title = basename(str_replace('\\', '/', $modelUrl)) ?: 'Модель проекта';
        }

        $kind = $this->modelKind((string) ($_POST['kind'] ?? ''), $modelUrl);
        $modelScope = $this->modelScope((string) ($_POST['model_scope'] ?? 'project'));
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            if ($isPrimary === 1) {
                $pdo->prepare('UPDATE project_model_links SET is_primary = 0 WHERE project_id = ?')->execute([$id]);
            }
            $stmt = $pdo->prepare('
                INSERT INTO project_model_links (project_id, title, model_url, kind, model_scope, discipline, revision, notes, is_primary, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $id,
                $title,
                $modelUrl,
                $kind,
                $modelScope,
                trim((string) ($_POST['discipline'] ?? '')),
                trim((string) ($_POST['revision'] ?? '')),
                trim((string) ($_POST['notes'] ?? '')),
                $isPrimary,
                (int) $user['id'],
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.model_link_added',
            'Добавлена модель проекта',
            $title . ' · ' . $modelUrl
        );
        flash('success', 'Постоянная ссылка на модель добавлена.');
        redirect('/projects/' . $id);
    }

    public function storeModelFolder(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!PermissionService::canManageProjectModels($user, $project)) {
            $this->forbidden();
        }

        $folder = trim((string) ($_POST['model_folder_url'] ?? ''));
        if (preg_match('/[\x00-\x1F]/', $folder)) {
            flash('error', 'Путь к папке моделей содержит недопустимые символы.');
            redirect('/projects/' . $id . '#project-models');
        }

        $this->db()->prepare('UPDATE projects SET model_folder_url = ? WHERE id = ?')->execute([$folder, $id]);
        $this->clearProjectFolderFragmentCache($id);
        $scan = $folder !== '' ? (new ModelFolderService())->scanDetailed($folder) : ['models' => [], 'errors' => []];
        $count = count($scan['models'] ?? []);
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.model_folder_saved',
            $folder === '' ? 'Папка моделей очищена' : 'Папка моделей сохранена',
            $folder
        );

        if ($folder === '') {
            flash('success', 'Папка моделей очищена.');
            redirect('/projects/' . $id . '#project-models');
        }

        if (empty($scan['accessible'])) {
            flash('error', 'Папка моделей сохранена, но сервер не может открыть путь. Проверьте права службы Apache на сетевую папку.');
            redirect('/projects/' . $id . '#project-models');
        }

        if ($count === 0 && !empty($scan['errors'])) {
            flash('error', 'Папка моделей сохранена, но при сканировании возникли ошибки доступа. Проверьте вложенные папки.');
            redirect('/projects/' . $id . '#project-models');
        }

        if ($count === 0) {
            $extensionCounts = (array) ($scan['extension_counts'] ?? []);
            $navisworksCount = (int) ($extensionCounts['nwc'] ?? 0) + (int) ($extensionCounts['nwf'] ?? 0) + (int) ($extensionCounts['nwd'] ?? 0);
            if ($navisworksCount > 0) {
                flash('error', 'Папка моделей сохранена. В ней видны Navisworks-файлы NWC/NWF/NWD, но Атлас открывает IFC, IFCZIP или FRAG.');
                redirect('/projects/' . $id . '#project-models');
            }
            if ((int) ($scan['files_seen'] ?? 0) > 0) {
                $labels = [];
                ksort($extensionCounts, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($extensionCounts as $extension => $seenCount) {
                    $labels[] = (string) $extension . ': ' . (int) $seenCount;
                }
                flash('error', 'Папка моделей сохранена. Файлы видны, но IFC, IFCZIP или FRAG не найдены' . ($labels !== [] ? ' (расширения: ' . implode(', ', $labels) . ')' : '') . '.');
                redirect('/projects/' . $id . '#project-models');
            }
            flash('error', 'Папка моделей сохранена, но файлов в ней не видно.');
            redirect('/projects/' . $id . '#project-models');
        }

        if (!empty($scan['errors'])) {
            flash('success', 'Папка моделей сохранена. Найдено моделей: ' . $count . '. Часть вложенных папок пропущена из-за доступа.');
            redirect('/projects/' . $id . '?prepare_models=1#project-models');
        }

        flash('success', 'Папка моделей сохранена. Найдено моделей: ' . $count . '. Фрагменты подготовятся в фоне.');
        redirect('/projects/' . $id . '?prepare_models=1#project-models');
    }

    public function refreshModelFolder(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!PermissionService::canManageProjectModels($user, $project)) {
            $this->forbidden();
        }

        $removed = $this->clearProjectFolderFragmentCache($id);
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.model_folder_refreshed',
            'Папка моделей обновлена',
            'Сброшено frag-кешей: ' . $removed
        );

        flash('success', 'Папка моделей перечитана. Frag-кеш сброшен: ' . $removed . '. Подготовка запущена в фоне.');
        redirect('/projects/' . $id . '?prepare_models=1#project-models');
    }

    public function deleteModelLink(int $id, int $modelId): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if (!PermissionService::canManageProjectModels($user, $project)) {
            $this->forbidden();
        }

        $stmt = $this->db()->prepare('SELECT title, model_url FROM project_model_links WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$modelId, $id]);
        $model = $stmt->fetch();
        if (!$model) {
            flash('error', 'Ссылка на модель не найдена.');
            redirect('/projects/' . $id);
        }

        $this->db()->prepare('DELETE FROM project_model_links WHERE id = ? AND project_id = ?')->execute([$modelId, $id]);
        ActivityLogService::recordProject(
            $id,
            (int) $user['id'],
            'project.model_link_deleted',
            'Удалена модель проекта',
            (string) ($model['title'] ?? '') . ' · ' . (string) ($model['model_url'] ?? '')
        );
        flash('success', 'Ссылка на модель удалена.');
        redirect('/projects/' . $id);
    }

    // Раздача файла модели по ссылке, чтобы публичная ссылка внутри сети
    // открывала локальный/сетевой IFC прямо в браузере (браузер не умеет
    // открывать пути вида C:\... или \\сервер\...). Без авторизации (сеть
    // доверенная, как договорено), но строго: только зарегистрированные модели
    // проекта и только файлы моделей по расширению — произвольные файлы недоступны.
    public function modelFile(int $id, int $modelId): void
    {
        $stmt = $this->db()->prepare('
            SELECT m.model_url, COALESCE(m.model_scope, \'project\') AS model_scope, p.file_folder_url, p.model_folder_url
            FROM project_model_links m
            INNER JOIN projects p ON p.id = m.project_id
            WHERE m.id = ? AND m.project_id = ?
            LIMIT 1
        ');
        $stmt->execute([$modelId, $id]);
        $model = $stmt->fetch();
        if (!$model) {
            http_response_code(404);
            echo 'Модель не найдена';
            return;
        }

        $path = trim((string) ($model['model_url'] ?? ''));
        if ($path === '') {
            http_response_code(404);
            echo 'У модели не задан файл';
            return;
        }
        // http(s) — просто перенаправляем на исходный URL.
        if (preg_match('#^https?://#i', $path)) {
            header('Location: ' . $path);
            return;
        }

        $path = $this->resolveModelFilePath($path, $model);
        if ($path === null) {
            http_response_code(403);
            echo $this->modelScope((string) ($model['model_scope'] ?? 'project')) === 'public'
                ? 'Файл модели находится вне общей папки Атласа'
                : 'Файл модели находится вне папок проекта';
            return;
        }
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo 'Файл модели недоступен на сервере: ' . e(basename($path));
            return;
        }
        $mime = (new ModelFolderService())->mimeFor($path);
        if ($mime === null) {
            http_response_code(415);
            echo 'Неподдерживаемый тип файла модели';
            return;
        }

        $this->sendFileMetadataHeaders($path, $mime, 'private, max-age=300');
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
        if ($this->isHeadRequest()) {
            return;
        }
        readfile($path);
    }

    // ---- Модели из ПАПКИ проекта (projects.model_folder_url) ----
    // Проект может ссылаться на папку с моделями на сетевой шаре; Атлас берёт
    // модели из неё (рекурсивный скан .frag/.ifc/.ifczip, .frag в приоритете).
    // Ручная привязка отдельных файлов (project_model_links) сохраняется.

    private function modelFolderFor(int $id): string
    {
        try {
            $stmt = $this->db()->prepare('SELECT model_folder_url FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? trim((string) ($row['model_folder_url'] ?? '')) : '';
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'model_folder_url')) {
                return '';
            }
            throw $e;
        }
    }

    // Рекурсивный скан папки. .frag вытесняет .ifc/.ifczip того же имени.
    private function scanModelFolder(string $folder): array
    {
        return (new ModelFolderService())->scan($folder);
    }

    private function modelFolderScan(int $id): array
    {
        $folder = $this->modelFolderFor($id);
        if ($folder === '') {
            return [
                'folder' => '',
                'root' => '',
                'accessible' => false,
                'models' => [],
                'errors' => [],
                'skipped' => 0,
                'limited' => false,
            ];
        }

        return (new ModelFolderService())->scanDetailed($folder);
    }

    // Модели из папки с готовыми абсолютными ссылками на Атлас (для карточки проекта).
    private function folderModels(int $id, ?int $createdBy = null, ?array $scan = null): array
    {
        $models = $scan !== null ? (array) ($scan['models'] ?? []) : $this->scanModelFolder($this->modelFolderFor($id));
        foreach ($models as &$m) {
            $m['atlas_href'] = $this->folderAtlasHref($id, (string) $m['rel'], (string) $m['kind']);
            $m['prepare_href'] = $m['atlas_href'] . '&prepare=1';
            $m['atlas_share_url'] = app_url($m['atlas_href']);
            $m['public_url'] = PublicLinkService::publicUrl(PublicLinkService::ensureFolderModelLink($id, (string) $m['rel'], (string) ($m['name'] ?? $m['rel']), $createdBy));
            $m['fragment_status_url'] = '/projects/' . $id . '/model-folder/fragments/status?path=' . rawurlencode((string) $m['rel']);
            $m['fragment_cache_url'] = '/projects/' . $id . '/model-folder/fragments?path=' . rawurlencode((string) $m['rel']);
            $m['fragment_status'] = $this->folderModelFragmentReady($id, (string) $m['rel'], (string) $m['kind']) ? 'ready' : 'pending';
            if ((string) $m['kind'] === 'frag') {
                $m['fragment_status_label'] = 'готовый FRAG';
            } elseif ($m['fragment_status'] === 'ready') {
                $m['fragment_status_label'] = 'FRAG готов';
            } else {
                $m['fragment_status_label'] = 'готовится';
            }
        }
        unset($m);
        return $models;
    }

    private function folderAtlasHref(int $projectId, string $rel, string $kind): string
    {
        $version = $this->folderModelVersionToken($projectId, $rel);
        $target = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/file?path=' . rawurlencode($rel), 'v', $version);
        $query = [
            'locia_return' => '/projects/' . $projectId,
            'project_id' => (string) $projectId,
            'base' => app_url(''),
        ];
        if ($kind === 'frag') {
            $query['frag'] = $target;
        } else {
            $query['ifc'] = $target;
            $query['frag'] = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/fragments?path=' . rawurlencode($rel), 'v', $version);
        }
        return '/locia-atlas/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function atlasFederationHref(int $projectId, array $modelLinks, array $folderModels): string
    {
        $sources = [];
        foreach ($modelLinks as $modelLink) {
            if (empty($modelLink['can_open_in_atlas'])) {
                continue;
            }
            $source = $this->atlasModelSource($modelLink, $projectId);
            if ($source !== null) {
                $sources[] = $source;
            }
        }
        foreach ($folderModels as $folderModel) {
            $source = $this->folderAtlasModelSource($projectId, $folderModel);
            if ($source !== null) {
                $sources[] = $source;
            }
        }

        if ($sources === []) {
            return '';
        }

        $query = [
            'locia_return' => '/projects/' . $projectId,
            'project_id' => (string) $projectId,
            'base' => app_url(''),
            'models' => json_encode(array_slice($sources, 0, 50), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return '/locia-atlas/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function atlasModelSource(array $modelLink, int $projectId): ?array
    {
        $kind = (string) ($modelLink['kind'] ?? '');
        if (!in_array($kind, ['frag', 'ifc', 'ifczip'], true)) {
            return null;
        }

        $id = (int) ($modelLink['id'] ?? 0);
        $target = $this->atlasModelTarget($modelLink, $projectId);
        $label = trim((string) ($modelLink['title'] ?? '')) ?: basename(trim((string) ($modelLink['model_url'] ?? ''))) ?: 'Модель';
        $source = [
            'id' => 'project-model-' . $id,
            'label' => $label,
            'url' => $kind === 'frag' ? '' : $target,
        ];
        if ($kind === 'frag') {
            $source['fragUrl'] = $target;
        } else {
            $source['fragUrl'] = '/projects/' . $projectId . '/models/' . $id . '/fragments';
            $source['fragCacheUrl'] = $source['fragUrl'];
        }

        return $source;
    }

    private function folderAtlasModelSource(int $projectId, array $folderModel): ?array
    {
        $kind = (string) ($folderModel['kind'] ?? '');
        if (!in_array($kind, ['frag', 'ifc', 'ifczip'], true)) {
            return null;
        }

        $rel = (string) ($folderModel['rel'] ?? '');
        if ($rel === '') {
            return null;
        }

        $version = $this->folderModelVersionToken($projectId, $rel);
        $target = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/file?path=' . rawurlencode($rel), 'v', $version);
        $label = trim((string) ($folderModel['name'] ?? '')) ?: basename($rel) ?: 'Модель';
        $source = [
            'id' => 'folder-model-' . substr(sha1($rel), 0, 12),
            'label' => $label,
            'url' => $kind === 'frag' ? '' : $target,
        ];
        if ($kind === 'frag') {
            $source['fragUrl'] = $target;
        } else {
            $fragment = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/fragments?path=' . rawurlencode($rel), 'v', $version);
            $source['fragUrl'] = $fragment;
            $source['fragCacheUrl'] = $fragment;
        }

        return $source;
    }

    // Раздача файла модели ИЗ ПАПКИ проекта по относительному пути. Без авторизации
    // (доверенная сеть, как modelFile), но строго: файл ОБЯЗАН лежать внутри папки
    // моделей проекта (защита от обхода пути) и иметь модельное расширение.
    public function folderModelFile(int $id): void
    {
        $service = new ModelFolderService();
        $target = $service->resolve($this->modelFolderFor($id), (string) ($_GET['path'] ?? ''));
        if ($target === null) {
            http_response_code(404);
            echo 'Файл модели не найден';
            return;
        }

        $mime = $service->mimeFor($target);
        if ($mime === null) {
            http_response_code(415);
            echo 'Неподдерживаемый тип файла модели';
            return;
        }
        $this->sendFileMetadataHeaders($target, $mime, 'private, max-age=300');
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($target)) . '"');
        if ($this->isHeadRequest()) {
            return;
        }
        readfile($target);
    }

    public function folderModelFragmentsStatus(int $id): void
    {
        [$cachePath, $target] = $this->folderFragmentTarget($id);
        json_response([
            'ready' => $cachePath !== null && is_file($cachePath),
            'source_mtime' => $target !== null ? (int) @filemtime($target) : 0,
            'source_size' => $target !== null ? (int) @filesize($target) : 0,
            'version' => $this->folderModelVersionToken($id, (string) ($_GET['path'] ?? ''), $target),
        ]);
    }

    public function folderModelFragmentsGet(int $id): void
    {
        [$cachePath] = $this->folderFragmentTarget($id);
        if ($cachePath === null || !is_file($cachePath)) {
            http_response_code(404);
            echo 'нет кеша фрагментов';
            return;
        }

        $this->sendFileMetadataHeaders($cachePath, 'application/octet-stream', 'private, max-age=3600');
        if ($this->isHeadRequest()) {
            return;
        }
        readfile($cachePath);
    }

    public function folderModelFragmentsPost(int $id): void
    {
        [$cachePath, $target, $rel] = $this->folderFragmentTarget($id);
        if ($cachePath === null || $target === null || $rel === '') {
            http_response_code(204);
            return;
        }

        $body = file_get_contents('php://input');
        $len = $body === false ? 0 : strlen($body);
        if ($len < 8 || $len > 300 * 1024 * 1024) {
            http_response_code(400);
            echo 'некорректный размер фрагментов';
            return;
        }
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $cachePath . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $body) === false) {
            http_response_code(500);
            echo 'не удалось сохранить кеш фрагментов';
            return;
        }
        @rename($tmp, $cachePath);
        (new ModelFolderService())->cleanOldFragmentCache($this->projectFolderCacheScope($id), $rel, $cachePath);
        http_response_code(204);
    }

    private function modelUrlFor(int $id, int $modelId): string
    {
        $stmt = $this->db()->prepare('SELECT model_url FROM project_model_links WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$modelId, $id]);
        $row = $stmt->fetch();
        return $row ? trim((string) ($row['model_url'] ?? '')) : '';
    }

    private function modelLocalPathFor(int $id, int $modelId): string
    {
        $stmt = $this->db()->prepare('
            SELECT m.model_url, COALESCE(m.model_scope, \'project\') AS model_scope, p.file_folder_url, p.model_folder_url
            FROM project_model_links m
            INNER JOIN projects p ON p.id = m.project_id
            WHERE m.id = ? AND m.project_id = ?
            LIMIT 1
        ');
        $stmt->execute([$modelId, $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return '';
        }

        $path = trim((string) ($row['model_url'] ?? ''));
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return '';
        }

        return $this->resolveModelFilePath($path, $row) ?? '';
    }

    private function resolveModelFilePath(string $path, array $model): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $roots = $this->modelScope((string) ($model['model_scope'] ?? 'project')) === 'public'
            ? [(string) config('app.default_model_folder', BASE_PATH . '/models')]
            : [(string) ($model['model_folder_url'] ?? ''), (string) ($model['file_folder_url'] ?? '')];

        return $this->resolveModelPathWithinRoots($path, $roots);
    }

    private function resolveModelPathWithinRoots(string $path, array $roots): ?string
    {
        if (!$this->isAbsoluteLocalPath($path)) {
            $service = new ModelFolderService();
            foreach ($roots as $root) {
                $resolved = $service->resolve((string) $root, $path);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        try {
            $filesystemPath = (new ProjectFolderService())->filesystemPath($path);
        } catch (\Throwable) {
            return null;
        }

        foreach ($roots as $root) {
            if ((string) $root !== '' && $this->pathIsInsideRoot($filesystemPath, (string) $root)) {
                return $filesystemPath;
            }
        }

        return null;
    }

    private function isAbsoluteLocalPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }
        if (preg_match('#^[a-zA-Z]:[\\\\/]#', $path) || str_starts_with($path, '\\\\') || str_starts_with($path, '/')) {
            return true;
        }

        $scheme = parse_url($path, PHP_URL_SCHEME);
        return is_string($scheme) && strtolower($scheme) === 'file';
    }

    private function isProjectModelPathAllowed(string $path, array $project): bool
    {
        foreach ([(string) ($project['model_folder_url'] ?? ''), (string) ($project['file_folder_url'] ?? '')] as $root) {
            if ($root !== '' && $this->pathIsInsideRoot($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private function pathIsInsideRoot(string $path, string $root): bool
    {
        $folderService = new ProjectFolderService();
        $rootPath = rtrim($folderService->filesystemPath($root), "\\/");
        if ($rootPath === '') {
            return false;
        }

        $realPath = realpath($path) ?: $path;
        $realRoot = realpath($rootPath) ?: $rootPath;
        $normalizedPath = mb_strtolower(rtrim(str_replace('/', '\\', $realPath), '\\'), 'UTF-8');
        $normalizedRoot = mb_strtolower(rtrim(str_replace('/', '\\', $realRoot), '\\'), 'UTF-8');

        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '\\');
    }

    private function folderModelFragmentReady(int $id, string $rel, string $kind): bool
    {
        if ($kind === 'frag') {
            return true;
        }

        [$cachePath] = $this->folderFragmentTarget($id, $rel);
        return $cachePath !== null && is_file($cachePath);
    }

    private function folderFragmentTarget(int $id, ?string $relativePath = null): array
    {
        $rel = $relativePath ?? (string) ($_GET['path'] ?? '');
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $service = new ModelFolderService();
        $target = $service->resolve($this->modelFolderFor($id), $rel);
        if ($target === null || strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'frag') {
            return [null, $target, $rel];
        }

        return [$service->fragmentCachePath($this->projectFolderCacheScope($id), $rel, $target), $target, $rel];
    }

    private function projectFolderCacheScope(int $id): string
    {
        return 'project-' . $id;
    }

    private function clearProjectFolderFragmentCache(int $id): int
    {
        $pattern = BASE_PATH . '/storage/atlas-fragments/' . $this->projectFolderCacheScope($id) . '-*.frag';
        $removed = 0;
        foreach (glob($pattern) ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        $this->touchProjectFolderCacheVersion($id);
        return $removed;
    }

    private function folderModelVersionToken(int $projectId, string $relativePath, ?string $target = null): string
    {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($target === null && $rel !== '') {
            $target = (new ModelFolderService())->resolve($this->modelFolderFor($projectId), $rel);
        }

        $sourceSignature = 'missing';
        if (is_string($target) && $target !== '' && is_file($target)) {
            $sourceSignature = (string) @filemtime($target) . '-' . (string) @filesize($target);
        }

        return substr(sha1($rel . '|' . $sourceSignature . '|' . $this->projectFolderCacheVersion($projectId)), 0, 16);
    }

    private function projectFolderCacheVersion(int $id): string
    {
        $path = $this->projectFolderCacheVersionPath($id);
        if (!is_file($path)) {
            return '0';
        }

        return (string) @filemtime($path) . '-' . trim((string) @file_get_contents($path));
    }

    private function touchProjectFolderCacheVersion(int $id): void
    {
        $path = $this->projectFolderCacheVersionPath($id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, (string) time() . '-' . bin2hex(random_bytes(4)));
    }

    private function projectFolderCacheVersionPath(int $id): string
    {
        return BASE_PATH . '/storage/atlas-fragments/' . $this->projectFolderCacheScope($id) . '.version';
    }

    private function appendQueryParam(string $url, string $name, string $value): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($name) . '=' . rawurlencode($value);
    }

    private function sendFileMetadataHeaders(string $path, string $mime, string $cacheControl): void
    {
        $mtime = (int) @filemtime($path);
        $size = (int) @filesize($path);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) $size);
        header('Cache-Control: ' . $cacheControl);
        if ($mtime > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }
        header('ETag: "locia-' . sha1($path . '|' . $mtime . '|' . $size) . '"');
    }

    private function isHeadRequest(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
    }

    // Путь серверного кеша фрагментов для модели. Только для локальных/сетевых
    // файлов (у внешних http(s)-моделей версию файла не определить). Ключ включает
    // mtime+size файла, поэтому при замене модели кеш сам инвалидируется.
    private function fragCachePath(int $modelId, string $modelUrl): ?string
    {
        if ($modelUrl === '' || preg_match('#^https?://#i', $modelUrl) || !is_file($modelUrl)) {
            return null;
        }
        $sig = (string) @filemtime($modelUrl) . '-' . (string) @filesize($modelUrl);
        return BASE_PATH . '/storage/atlas-fragments/' . $modelId . '-' . substr(sha1($modelUrl . '|' . $sig), 0, 16) . '.frag';
    }

    // GET: отдаём готовые фрагменты, если первый открывший их уже прислал.
    public function modelFragmentsGet(int $id, int $modelId): void
    {
        $path = $this->fragCachePath($modelId, $this->modelLocalPathFor($id, $modelId));
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'нет кеша фрагментов';
            return;
        }
        $this->sendFileMetadataHeaders($path, 'application/octet-stream', 'private, max-age=3600');
        if ($this->isHeadRequest()) {
            return;
        }
        readfile($path);
    }

    // POST: первый открывший модель присылает построенные фрагменты — кладём рядом
    // и дальше раздаём всем (без Node на сервере). Без авторизации (сеть доверенная),
    // но строго: только зарегистрированная модель проекта и разумный размер.
    public function modelFragmentsPost(int $id, int $modelId): void
    {
        $path = $this->fragCachePath($modelId, $this->modelLocalPathFor($id, $modelId));
        if ($path === null) {
            http_response_code(204);
            return;
        }
        $body = file_get_contents('php://input');
        $len = $body === false ? 0 : strlen($body);
        if ($len < 8 || $len > 300 * 1024 * 1024) {
            http_response_code(400);
            echo 'некорректный размер фрагментов';
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $body) === false) {
            http_response_code(500);
            echo 'не удалось сохранить кеш фрагментов';
            return;
        }
        @rename($tmp, $path);
        // Чистим устаревшие версии кеша этой модели.
        foreach (glob($dir . '/' . $modelId . '-*.frag') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }
        http_response_code(204);
    }

    public function archive(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $this->db()->prepare('UPDATE projects SET status = "archived", archived_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        ActivityLogService::recordProject($id, (int) $user['id'], 'project.archived', 'Проект переведён в архив');
        flash('success', 'Проект переведён в архив.');
        redirect('/projects/' . $id);
    }

    public function restore(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $this->db()->prepare('UPDATE projects SET status = "active", archived_at = NULL WHERE id = ?')->execute([$id]);
        ActivityLogService::recordProject($id, (int) $user['id'], 'project.restored', 'Проект восстановлен из архива');
        flash('success', 'Проект восстановлен.');
        redirect('/projects/' . $id);
    }

    public function clone(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $newCode = $this->uniqueProjectCode((string) $project['code']);
            $stmt = $pdo->prepare('
                INSERT INTO projects (code, title, `object`, pp, stage, start_date, finish_date, status, color, speckle_stream_url, file_folder_url, gip_user_id, rp_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, "active", ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $newCode,
                (string) $project['title'] . ' — новый проект',
                (string) ($project['object'] ?? ''),
                (string) ($project['pp'] ?? ''),
                (string) ($project['stage'] ?? 'РД'),
                $project['start_date'] ?: null,
                $project['finish_date'] ?: null,
                (string) ($project['color'] ?: '#cc1f1f'),
                '',
                (string) ($project['file_folder_url'] ?? ''),
                $project['gip_user_id'] ?: null,
                $project['rp_user_id'] ?: null,
            ]);
            $newProjectId = (int) $pdo->lastInsertId();
            $created = $this->cloneTaskStructure($id, $newProjectId, (int) $user['id']);
            ActivityLogService::recordProject(
                $newProjectId,
                (int) $user['id'],
                'project.created',
                'Проект создан из шаблона',
                'Источник: ' . (string) $project['code'] . '. Задач: ' . $created . '.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        flash('success', 'Создан проект из шаблона. Задач: ' . $created . '.');
        redirect('/projects/' . $newProjectId);
    }

    public function ganttHome(): void
    {
        $user = require_auth();
        $project = $this->projectsFor($user)[0] ?? null;
        if (!$project) {
            flash('error', 'Нет активных проектов для диаграммы Ганта.');
            redirect('/projects');
        }

        redirect('/projects/' . (int) $project['id'] . '/gantt');
    }

    public function gantt(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $stmt = $this->db()->prepare('
            SELECT t.id, t.msp_task_uid, t.msp_task_id, t.msp_outline_level, t.title, t.date_start, t.date_end, t.progress,
                   t.status, t.discipline, t.volume, t.section, u.name AS assignee_name, s.depends_on
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assignee_id
            LEFT JOIN task_smart s ON s.task_id = t.id
            WHERE t.project_id = ?
            ORDER BY t.msp_task_id IS NULL, t.msp_task_id, t.date_start IS NULL, t.date_start, t.date_end IS NULL, t.date_end, t.id
        ');
        $stmt->execute([$id]);
        $ganttTasks = array_map(static function (array $task): array {
            $start = $task['date_start'] ?: ($task['date_end'] ?: date('Y-m-d'));
            $end = $task['date_end'] ?: $start;
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }

            return [
                'id' => (string) ($task['msp_task_uid'] ?: $task['id']),
                'db_id' => (int) $task['id'],
                'name' => $task['title'],
                'start' => $start,
                'end' => $end,
                'progress' => (int) $task['progress'],
                'status' => (string) $task['status'],
                'outline_level' => (int) ($task['msp_outline_level'] ?? 0),
                'discipline' => (string) ($task['discipline'] ?? ''),
                'volume' => (string) ($task['volume'] ?? ''),
                'section' => (string) ($task['section'] ?? ''),
                'assignee_name' => (string) ($task['assignee_name'] ?? ''),
                'dependencies' => $task['depends_on'] ?? '',
            ];
        }, $stmt->fetchAll());

        $this->render('projects/gantt', [
            'title' => 'Гант: ' . $project['code'],
            'headerActions' => [],
            'project' => $project,
            'ganttTasks' => $ganttTasks,
            'canViewProjectFinance' => PermissionService::canViewProjectFinance($user, $project),
        ]);
    }

    public function dictionaries(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }

        $this->render('projects/dictionaries', [
            'title' => 'Справочники: ' . $project['code'],
            'project' => $project,
            'items' => DictionaryService::forProjectPage($id),
            'kinds' => DictionaryService::kinds(),
            'sections' => DictionaryService::globalSections(),
            'accounting' => ProjectAccountingService::forProjectPage($id),
            'canEdit' => !$this->isArchived($project) && PermissionService::canCreateProject($user),
            'canViewProjectFinance' => PermissionService::canViewProjectFinance($user, $project),
        ]);
    }

    public function storeDictionary(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect('/projects/' . $id . '/dictionaries');
        }

        $payload = DictionaryService::payload($_POST, $id);
        if ($payload['value'] === '') {
            flash('error', 'Значение справочника обязательно.');
            redirect('/projects/' . $id . '/dictionaries');
        }

        DictionaryService::save($payload);
        flash('success', 'Проектный справочник обновлён.');
        redirect('/projects/' . $id . '/dictionaries');
    }

    public function storeAccountingPp(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '/dictionaries');
        $payload = ProjectAccountingService::ppPayload($_POST);
        if ($payload['code'] === '') {
            flash('error', 'Номер ПП обязателен.');
            redirect('/projects/' . $id . '/dictionaries');
        }

        ProjectAccountingService::savePp((int) $project['id'], $payload);
        flash('success', 'ПП сохранён.');
        redirect('/projects/' . $id . '/dictionaries');
    }

    public function storeAccountingBtp(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '/dictionaries');
        $payload = ProjectAccountingService::btpPayload($_POST);
        if ($payload['pp_code_id'] <= 0 || $payload['code'] === '') {
            flash('error', 'Выберите ПП и укажите БТП.');
            redirect('/projects/' . $id . '/dictionaries');
        }

        try {
            ProjectAccountingService::saveBtp((int) $project['id'], $payload);
            flash('success', 'БТП сохранён.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/projects/' . $id . '/dictionaries');
    }

    public function storeAccountingUts(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '/dictionaries');
        $payload = ProjectAccountingService::utsPayload($_POST);
        if ($payload['pp_code_id'] <= 0) {
            flash('error', 'Выберите ПП для факта УТС.');
            redirect('/projects/' . $id . '/dictionaries');
        }

        try {
            ProjectAccountingService::saveUts((int) $project['id'], $payload, (int) $user['id']);
            flash('success', 'Факт УТС сохранён.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/projects/' . $id . '/dictionaries');
    }

    public function storeMember(int $id): void
    {
        $user = require_auth();
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '#project-team');
        $canManageTeam = PermissionService::canCreateProject($user)
            || (int) ($user['id'] ?? 0) === (int) ($project['gip_user_id'] ?? 0)
            || (int) ($user['id'] ?? 0) === (int) ($project['rp_user_id'] ?? 0);
        if (!$canManageTeam) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для изменения команды проекта.']);
            exit;
        }
        $teamReturnPath = (string) ($_POST['return_to'] ?? '') === 'structure'
            ? '/projects/' . $id . '/structure#project-team-roster'
            : '/projects/' . $id . '?section=team#project-team';
        if (is_array($_POST['member_user_ids'] ?? null) || is_array($_POST['member_role'] ?? null)) {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['member_user_ids'] ?? [])), static fn (int $userId): bool => $userId > 0)));
            $lockedIds = array_values(array_unique(array_filter([
                (int) ($project['gip_user_id'] ?? 0),
                (int) ($project['rp_user_id'] ?? 0),
            ], static fn (int $userId): bool => $userId > 0)));
            $selectedIds = array_values(array_unique([...$selectedIds, ...$lockedIds]));

            try {
                $this->syncProjectMembers((int) $project['id'], $selectedIds, $_POST);
            } catch (\InvalidArgumentException $error) {
                flash('error', $error->getMessage());
                redirect($teamReturnPath);
            } catch (\Throwable $error) {
                error_log('Project team roster update failed: ' . $error->getMessage());
                flash('error', 'Не удалось сохранить команду. Проверьте разделы и роли и повторите попытку.');
                redirect($teamReturnPath);
            }
            flash('success', 'Команда проекта обновлена: ' . count($selectedIds) . ' чел. Разделы и роли сохранены.');
            ActivityLogService::recordProject(
                (int) $project['id'],
                (int) $user['id'],
                'project.team_updated',
                'Команда проекта обновлена',
                'Активных участников: ' . count($selectedIds)
            );
            redirect($teamReturnPath);
        }

        $memberUserId = (int) ($_POST['user_id'] ?? 0);
        if ($memberUserId <= 0) {
            flash('error', 'Выберите сотрудника команды.');
            redirect('/projects/' . $id . '#project-team');
        }

        $data = [
            'project_role' => trim((string) ($_POST['project_role'] ?? '')),
            'allocation_percent' => ($_POST['allocation_percent'] ?? '') !== '' ? max(0, min(100, (float) str_replace(',', '.', (string) $_POST['allocation_percent']))) : null,
            'date_start' => $this->nullableDate($_POST['date_start'] ?? ''),
            'date_end' => $this->nullableDate($_POST['date_end'] ?? ''),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];

        $this->saveMember((int) $project['id'], $memberUserId, $data);
        flash('success', 'Участник команды сохранён.');
        redirect('/projects/' . $id . '#project-team');
    }

    public function storeTeamStructure(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '#project-team');

        try {
            $updated = (new ProjectTeamStructureService($this->db()))->sync(
                (int) $project['id'],
                (array) ($_POST['section_ids'] ?? []),
                (array) ($_POST['section_assignee'] ?? []),
                (array) ($_POST['section_reviewer'] ?? [])
            );
            flash('success', 'Структура команды обновлена: ' . $updated . ' разделов.');
            ActivityLogService::recordProject(
                (int) $project['id'],
                (int) $user['id'],
                'project.team_structure_updated',
                'Обновлена структура команды по разделам',
                'Разделов: ' . $updated
            );
        } catch (\InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (\Throwable $error) {
            error_log('Project team structure update failed: ' . $error->getMessage());
            flash('error', 'Не удалось сохранить структуру команды. Проверьте назначения и повторите попытку.');
        }

        redirect('/projects/' . $id . '#project-team');
    }

    public function assignTeamSection(int $id): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $project = $this->editableProjectOrFail($id, $user, '/projects/' . $id . '?section=team#project-team');

        try {
            $teamService = new ProjectTeamStructureService($this->db());
            if ((string) ($_POST['catalog_only'] ?? '') === '1') {
                $sectionCode = $teamService->createGlobalSection(
                    (int) $project['id'],
                    (string) ($_POST['new_section_code'] ?? ''),
                    (string) ($_POST['new_section_title'] ?? '')
                );
                flash('success', 'Раздел «' . $sectionCode . '» добавлен в общий справочник и доступен в таблице команды.');
                ActivityLogService::recordProject(
                    (int) $project['id'],
                    (int) $user['id'],
                    'project.team_section_catalog_created',
                    'Добавлен типовой раздел',
                    $sectionCode
                );
                redirect('/projects/' . $id . '?section=team#project-team');
            }

            $sectionId = $teamService->assignPerson(
                (int) $project['id'],
                (int) ($_POST['assignee_id'] ?? 0),
                (int) ($_POST['reviewer_id'] ?? 0),
                (string) ($_POST['section_code'] ?? ''),
                (string) ($_POST['new_section_code'] ?? ''),
                (string) ($_POST['new_section_title'] ?? '')
            );
            flash('success', 'Сотрудник назначен на раздел. Раздел и проверяющий сохранены в структуре проекта.');
            ActivityLogService::recordProject(
                (int) $project['id'],
                (int) $user['id'],
                'project.team_section_assigned',
                'Назначен сотрудник на раздел',
                'Раздел #' . $sectionId
            );
        } catch (\InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (\Throwable $error) {
            error_log('Project team section assignment failed: ' . $error->getMessage());
            flash('error', 'Не удалось назначить сотрудника на раздел. Проверьте выбранные значения и повторите попытку.');
        }

        redirect('/projects/' . $id . '?section=team#project-team');
    }

    public function deleteMember(int $id, int $memberId): void
    {
        $user = $this->requireRole(['admin', 'director', 'deputy_director', 'gip']);
        $this->editableProjectOrFail($id, $user, '/projects/' . $id . '#project-team');
        $stmt = $this->db()->prepare('UPDATE project_members SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND project_id = ?');
        $stmt->execute([$memberId, $id]);
        flash('success', 'Участник команды удалён из проекта.');
        redirect('/projects/' . $id . '#project-team');
    }

    private function payload(): array
    {
        $budget = $this->budgetPayload();
        return [
            'code' => trim((string) ($_POST['code'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'object' => trim((string) ($_POST['object'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'object_type' => trim((string) ($_POST['object_type'] ?? '')),
            'area_m2' => $this->nullableDecimal($_POST['area_m2'] ?? ''),
            'stages_text' => trim((string) ($_POST['stages_text'] ?? '')),
            'pp' => trim((string) ($_POST['pp'] ?? '')),
            'stage' => in_array((string) ($_POST['stage'] ?? ''), ['ПД', 'РД', 'ПД-РД', 'АН'], true) ? (string) $_POST['stage'] : 'РД',
            'start_date' => $this->nullableDate($_POST['start_date'] ?? ''),
            'finish_date' => $this->nullableDate($_POST['finish_date'] ?? ''),
            'status' => 'active',
            'color' => trim((string) ($_POST['color'] ?? '#cc1f1f')),
            'speckle_stream_url' => trim((string) ($_POST['speckle_stream_url'] ?? '')),
            'model_folder_url' => trim((string) ($_POST['model_folder_url'] ?? '')),
            'gip_user_id' => ($_POST['gip_user_id'] ?? '') !== '' ? (int) $_POST['gip_user_id'] : null,
            'rp_user_id' => ($_POST['rp_user_id'] ?? '') !== '' ? (int) $_POST['rp_user_id'] : null,
            'structure_stage_codes' => array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['stage_codes'] ?? []))))),
            'structure_stage_templates' => is_array($_POST['stage_templates'] ?? null) ? $_POST['stage_templates'] : [],
            'structure_activity_codes' => array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['activity_codes'] ?? []))))),
        ] + $budget;
    }

    private function budgetPayload(): array
    {
        $raw = [
            'budget_total_thousand' => $_POST['budget_total_thousand'] ?? '',
            'budget_cost_thousand' => $_POST['budget_cost_thousand'] ?? '',
            'budget_profit_thousand' => $_POST['budget_profit_thousand'] ?? '',
            'budget_bonus_thousand' => $_POST['budget_bonus_thousand'] ?? '',
        ];
        $parsed = [];
        foreach ($raw as $key => $value) {
            $parsed[$key] = $this->nullableDecimal($value);
        }
        $parseable = true;
        foreach ($raw as $key => $value) {
            if (trim((string) $value) !== '' && $parsed[$key] === null) {
                $parseable = false;
            }
        }
        $cost = $parsed['budget_cost_thousand'] ?? 0.0;
        $profit = $parsed['budget_profit_thousand'] ?? 0.0;
        $bonus = $parsed['budget_bonus_thousand'] ?? 0.0;
        $partsTotal = (float) $cost + (float) $profit + (float) $bonus;
        $explicitTotal = $parsed['budget_total_thousand'];
        return [
            'budget_cost_thousand' => $cost,
            'budget_profit_thousand' => $profit,
            'budget_bonus_thousand' => $bonus,
            'budget_total_thousand' => $explicitTotal ?? $partsTotal,
            'budget_parts_total_thousand' => $partsTotal,
            'budget_total_explicit' => trim((string) $raw['budget_total_thousand']) !== '',
            'budget_parts_parseable' => $parseable,
        ];
    }

    private function validBudget(array $data): bool
    {
        if (($data['budget_parts_parseable'] ?? false) !== true) {
            return false;
        }
        $total = $data['budget_total_thousand'] ?? null;
        if ($total === null || (float) $total <= 0) {
            return false;
        }
        foreach (['budget_cost_thousand', 'budget_profit_thousand', 'budget_bonus_thousand'] as $key) {
            if (($data[$key] ?? null) === null || (float) $data[$key] < 0) return false;
        }
        return (float) ($data['budget_parts_total_thousand'] ?? 0) <= (float) $total + 0.00001;
    }

    private function budgetMatchesProject(array $data, array $project): bool
    {
        if (($data['budget_parts_parseable'] ?? false) !== true) {
            return false;
        }
        $comparisons = [
            'budget_total_thousand' => 'budget_manual_thousand',
            'budget_cost_thousand' => 'budget_cost_thousand',
            'budget_profit_thousand' => 'budget_profit_thousand',
            'budget_bonus_thousand' => 'budget_bonus_thousand',
        ];
        foreach ($comparisons as $key => $storedKey) {
            $posted = $data[$key] ?? null;
            $stored = $this->nullableDecimal($project[$storedKey] ?? null);
            if (
                $key === 'budget_total_thousand'
                && ($data['budget_total_explicit'] ?? false) !== true
                && abs((float) ($posted ?? 0)) < 0.00001
                && ($stored === null || abs((float) $stored) < 0.00001)
            ) {
                continue;
            }
            if ($posted === null || $stored === null) {
                if ($posted !== null || $stored !== null) {
                    return false;
                }
                continue;
            }
            if (abs((float) $posted - $stored) > 0.00001) {
                return false;
            }
        }

        return true;
    }

    private function projectFormErrors(array $data, bool $budgetUnchanged = false, bool $requireStructure = false): array
    {
        $errors = [];
        if ($data['code'] === '') $errors['code'] = 'Укажите код проекта.';
        if ($data['title'] === '') $errors['title'] = 'Укажите название проекта.';
        if (!$data['gip_user_id']) $errors['gip_user_id'] = 'Выберите ГИПа.';
        if (!$data['rp_user_id']) $errors['rp_user_id'] = 'Выберите РП.';
        if (!$budgetUnchanged && !$this->validBudget($data)) {
            $errors['budget'] = $this->budgetErrorMessage($data);
        }
        if ($requireStructure && empty($data['structure_stage_codes'])) {
            $errors['structure'] = 'Выберите хотя бы одну стадию проекта.';
        }
        return $errors;
    }

    private function budgetErrorMessage(array $data): string
    {
        if (($data['budget_parts_parseable'] ?? false) !== true) {
            return 'В бюджете можно вводить только неотрицательные числа.';
        }
        if ((float) ($data['budget_total_thousand'] ?? 0) <= 0) {
            return 'Укажите общий бюджет или заполните хотя бы одну часть бюджета.';
        }
        if ((float) ($data['budget_parts_total_thousand'] ?? 0) > (float) ($data['budget_total_thousand'] ?? 0) + 0.00001) {
            return 'Сумма частей бюджета не может быть больше общего бюджета.';
        }
        return 'Значения бюджета не могут быть отрицательными.';
    }

    private function rememberProjectFormState(string $key, array $data, array $errors): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['project_form_state_' . $key] = ['data' => array_replace($data, $this->projectFormInputSnapshot()), 'errors' => $errors];
    }

    private function projectFormInputSnapshot(): array
    {
        $keys = [
            'code', 'stage', 'start_date', 'finish_date', 'title', 'object', 'address', 'object_type',
            'area_m2', 'stages_text', 'pp', 'gip_user_id', 'rp_user_id', 'budget_total_thousand',
            'budget_cost_thousand', 'budget_profit_thousand', 'budget_bonus_thousand', 'model_folder_url', 'color',
        ];
        $snapshot = [];
        foreach ($keys as $key) {
            $value = $_POST[$key] ?? '';
            $snapshot[$key] = is_scalar($value) ? (string) $value : '';
        }
        $snapshot['structure_stage_codes'] = array_values(array_map('strval', (array) ($_POST['stage_codes'] ?? [])));
        $snapshot['structure_stage_templates'] = is_array($_POST['stage_templates'] ?? null) ? $_POST['stage_templates'] : [];
        $snapshot['structure_activity_codes'] = array_values(array_map('strval', (array) ($_POST['activity_codes'] ?? [])));
        return $snapshot;
    }

    private function consumeProjectFormState(string $key): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $sessionKey = 'project_form_state_' . $key;
        $state = $_SESSION[$sessionKey] ?? ['data' => [], 'errors' => []];
        unset($_SESSION[$sessionKey]);
        return is_array($state) ? $state + ['data' => [], 'errors' => []] : ['data' => [], 'errors' => []];
    }

    private function projectsFor(array $user, bool $archived = false): array
    {
        $status = $archived ? 'archived' : 'active';
        if (PermissionService::canSeeAllProjects($user)) {
            $stmt = $this->db()->prepare('
                SELECT p.*,
                       COUNT(t.id) AS tasks_total,
                       SUM(t.status = "done") AS tasks_done,
                       SUM(t.status = "blocked") AS tasks_blocked,
                       MIN(CASE WHEN t.status != "done" THEN t.date_end END) AS nearest_deadline
                FROM projects p
                LEFT JOIN tasks t ON t.project_id = p.id
                WHERE p.status = :status
                  AND COALESCE(p.kind, "project") = "project"
                GROUP BY p.id
                ORDER BY p.code
            ');
            $stmt->execute(['status' => $status]);
            return $stmt->fetchAll();
        }

        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
        $stmt = $this->db()->prepare('
            SELECT p.*,
                   COUNT(all_tasks.id) AS tasks_total,
                   SUM(all_tasks.status = "done") AS tasks_done,
                   SUM(all_tasks.status = "blocked") AS tasks_blocked,
                   MIN(CASE WHEN all_tasks.status != "done" THEN all_tasks.date_end END) AS nearest_deadline
            FROM projects p
            LEFT JOIN tasks all_tasks ON all_tasks.project_id = p.id
            WHERE p.status = :status
              AND COALESCE(p.kind, "project") = "project"
              AND ' . $where . '
            GROUP BY p.id
            ORDER BY p.code
        ');
        $params['status'] = $status;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function project(int $id, array $user): ?array
    {
        $select = '
            SELECT p.*,
                   gip.name AS gip_name,
                   rp.name AS rp_name
            FROM projects p
            LEFT JOIN users gip ON gip.id = p.gip_user_id
            LEFT JOIN users rp ON rp.id = p.rp_user_id
        ';
        if (PermissionService::canSeeAllProjects($user)) {
            $stmt = $this->db()->prepare($select . ' WHERE p.id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }

        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
        $stmt = $this->db()->prepare($select . '
            WHERE p.id = :project_id AND ' . $where . '
            LIMIT 1
        ');
        $stmt->execute(['project_id' => $id] + $params);

        return $stmt->fetch() ?: null;
    }

    private function contacts(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT *
            FROM project_contacts
            WHERE project_id = ?
            ORDER BY organization, full_name, id
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private function members(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT pm.*, u.name, u.role, u.department, u.is_active
            FROM project_members pm
            INNER JOIN users u ON u.id = pm.user_id
            WHERE pm.project_id = ?
              AND pm.active = 1
            ORDER BY pm.active DESC, u.name, pm.id
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private function syncProjectMembers(int $projectId, array $selectedUserIds, array $payload): void
    {
        $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $selectedUserIds), static fn (int $userId): bool => $userId > 0)));
        $roles = is_array($payload['member_role'] ?? null) ? $payload['member_role'] : [];
        $allocations = is_array($payload['member_allocation_percent'] ?? null) ? $payload['member_allocation_percent'] : [];
        $starts = is_array($payload['member_date_start'] ?? null) ? $payload['member_date_start'] : [];
        $ends = is_array($payload['member_date_end'] ?? null) ? $payload['member_date_end'] : [];
        $notes = is_array($payload['member_notes'] ?? null) ? $payload['member_notes'] : [];

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE project_members SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE project_id = ?')->execute([$projectId]);
            foreach ($selectedUserIds as $userId) {
                $this->saveMember($projectId, $userId, [
                    'project_role' => trim((string) ($roles[$userId] ?? '')),
                    'allocation_percent' => ($allocations[$userId] ?? '') !== ''
                        ? max(0, min(100, (float) str_replace(',', '.', (string) $allocations[$userId])))
                        : null,
                    'date_start' => $this->nullableDate($starts[$userId] ?? ''),
                    'date_end' => $this->nullableDate($ends[$userId] ?? ''),
                    'notes' => trim((string) ($notes[$userId] ?? '')),
                ]);
            }
            if (is_array($payload['team_roster_user_ids'] ?? null)) {
                (new ProjectTeamStructureService($pdo))->syncRosterAssignments(
                    $projectId,
                    (array) $payload['team_roster_user_ids'],
                    $selectedUserIds,
                    (array) ($payload['member_section_code'] ?? []),
                    (array) ($payload['member_section_role'] ?? []),
                    (array) ($payload['member_previous_section_code'] ?? []),
                    (array) ($payload['member_previous_section_role'] ?? [])
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function saveMember(int $projectId, int $userId, array $data): void
    {
        if ($this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->db()->prepare('
                INSERT INTO project_members (project_id, user_id, project_role, allocation_percent, date_start, date_end, notes, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ON CONFLICT(project_id, user_id) DO UPDATE SET
                    project_role = excluded.project_role,
                    allocation_percent = excluded.allocation_percent,
                    date_start = excluded.date_start,
                    date_end = excluded.date_end,
                    notes = excluded.notes,
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $this->db()->prepare('
                INSERT INTO project_members (project_id, user_id, project_role, allocation_percent, date_start, date_end, notes, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    project_role = VALUES(project_role),
                    allocation_percent = VALUES(allocation_percent),
                    date_start = VALUES(date_start),
                    date_end = VALUES(date_end),
                    notes = VALUES(notes),
                    active = 1
            ');
        }
        $stmt->execute([
            $projectId,
            $userId,
            $data['project_role'] !== '' ? $data['project_role'] : null,
            $data['allocation_percent'],
            $data['date_start'],
            $data['date_end'],
            $data['notes'] !== '' ? $data['notes'] : null,
        ]);
    }

    private function syncProjectRoleMembers(int $projectId, int $gipUserId, int $rpUserId): void
    {
        if ($gipUserId > 0) {
            $this->ensureProjectRoleMember($projectId, $gipUserId, 'ГИП');
        }
        if ($rpUserId > 0 && $rpUserId !== $gipUserId) {
            $this->ensureProjectRoleMember($projectId, $rpUserId, 'РП');
        }
    }

    private function ensureProjectRoleMember(int $projectId, int $userId, string $projectRole): void
    {
        $stmt = $this->db()->prepare('
            SELECT id, active
            FROM project_members
            WHERE project_id = ? AND user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$projectId, $userId]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ((int) ($existing['active'] ?? 0) !== 1) {
                $restore = $this->db()->prepare('UPDATE project_members SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $restore->execute([(int) $existing['id']]);
            }
            return;
        }

        $stmt = $this->db()->prepare('
            INSERT INTO project_members (project_id, user_id, project_role, allocation_percent, date_start, date_end, notes, active)
            VALUES (?, ?, ?, NULL, NULL, NULL, NULL, 1)
        ');
        $stmt->execute([$projectId, $userId, $projectRole]);
    }

    private function editableProjectOrFail(int $id, array $user, string $redirectTo): array
    {
        $project = $this->project($id, $user);
        if (!$project) {
            $this->notFound();
        }
        if ($this->isArchived($project)) {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect($redirectTo);
        }

        return $project;
    }

    private function activeUsers(array $includeIds = []): array
    {
        $includeIds = array_values(array_unique(array_filter(array_map('intval', $includeIds), static fn (int $id): bool => $id > 0)));
        if (!$includeIds) {
            return $this->db()->query('SELECT id, name, role, department, is_active FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
        }

        $placeholders = implode(',', array_fill(0, count($includeIds), '?'));
        $stmt = $this->db()->prepare('
            SELECT id, name, role, department, is_active
            FROM users
            WHERE is_active = 1 OR id IN (' . $placeholders . ')
            ORDER BY is_active DESC, name
        ');
        $stmt->execute($includeIds);

        return $stmt->fetchAll();
    }

    private function isArchived(array $project): bool
    {
        return (string) ($project['status'] ?? 'active') === 'archived';
    }

    private function canArchiveProject(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, ['admin', 'director', 'deputy_director']);
    }

    private function canImportMsp(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::GIP);
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $text = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim((string) $value));
        if ($text === '') {
            return null;
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        $text = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
    }

    private function uniqueProjectCode(string $sourceCode): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($sourceCode)) ?: 'PROJECT';
        $base = trim($base, '-_');
        $base = substr($base, 0, 13);

        for ($i = 1; $i < 100; $i++) {
            $suffix = $i === 1 ? '-COPY' : '-COPY' . $i;
            $code = substr($base, 0, 20 - strlen($suffix)) . $suffix;
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM projects WHERE code = ?');
            $stmt->execute([$code]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $code;
            }
        }

        return substr($base, 0, 10) . '-' . substr((string) time(), -9);
    }

    private function cloneTaskStructure(int $sourceProjectId, int $targetProjectId, int $authorId): int
    {
        $stmt = $this->db()->prepare('
            SELECT id, parent_id, title, task_type, discipline, volume, section
            FROM tasks
            WHERE project_id = ?
            ORDER BY parent_id IS NOT NULL, parent_id, id
        ');
        $stmt->execute([$sourceProjectId]);

        $idMap = [];
        $created = 0;
        $insert = $this->db()->prepare('
            INSERT INTO tasks (
                title, task_type, project_id, parent_id, assignee_id, author_id, reviewer_id, discipline, volume, section,
                status, priority, urgency, date_start, date_end, date_end_original, planned_hours, progress, btp, speckle_stream_url
            )
            VALUES (
                ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?,
                "new", "mid", "mid", NULL, NULL, NULL, NULL, 0, "", ""
            )
        ');

        foreach ($stmt->fetchAll() as $task) {
            $oldParentId = (int) ($task['parent_id'] ?? 0);
            $newParentId = $oldParentId > 0 ? ($idMap[$oldParentId] ?? null) : null;
            $insert->execute([
                (string) $task['title'],
                (string) ($task['task_type'] ?? 'work'),
                $targetProjectId,
                $newParentId,
                $authorId,
                $task['discipline'] ?: null,
                (string) ($task['volume'] ?? ''),
                (string) ($task['section'] ?? ''),
            ]);
            $idMap[(int) $task['id']] = (int) $this->db()->lastInsertId();
            $created++;
        }

        return $created;
    }

    private function tasks(int $projectId, array $user): array
    {
        [$where, $params] = PermissionService::taskScopeWhere($user);
        $stmt = $this->db()->prepare('
            SELECT t.*,
                   CASE
                       WHEN t.status = \'overdue\' THEN COALESCE((
                           SELECT overdue_log.old_val
                           FROM task_logs overdue_log
                           WHERE overdue_log.task_id = t.id
                             AND overdue_log.field = \'status\'
                             AND overdue_log.new_val = \'overdue\'
                             AND overdue_log.old_val IN (\'new\', \'in_progress\', \'blocked\')
                           ORDER BY overdue_log.id DESC
                           LIMIT 1
                       ), \'in_progress\')
                       ELSE t.status
                   END AS board_status,
                   u.name AS assignee_name, r.name AS reviewer_name, parent.title AS parent_title, COALESCE(tc.child_count, 0) AS child_count
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assignee_id
            LEFT JOIN users r ON r.id = t.reviewer_id
            LEFT JOIN tasks parent ON parent.id = t.parent_id
            LEFT JOIN (
                SELECT parent_id AS task_id, COUNT(*) AS child_count
                FROM tasks
                WHERE parent_id IS NOT NULL
                GROUP BY parent_id
            ) tc ON t.id = tc.task_id
            WHERE t.project_id = :project_id AND ' . $where . '
            ORDER BY t.msp_task_id IS NULL, t.msp_task_id, t.parent_id IS NOT NULL, t.date_end IS NULL, t.date_end, t.id
        ');
        $stmt->execute(['project_id' => $projectId] + $params);
        return TagService::attachToTasks($stmt->fetchAll());
    }

    private function summary(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT COUNT(*) AS total,
                   SUM(status = "done") AS done,
                   SUM(status = "in_progress") AS in_progress,
                   SUM(status = "blocked") AS blocked,
                   SUM(status = "overdue") AS overdue,
                   COALESCE(ROUND(AVG(progress)), 0) AS avg_progress,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 1), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 1), 0) AS actual_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0) - COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 1), 0) AS remaining_hours,
                   COALESCE((SELECT ROUND(SUM(c.planned_cost), 2) FROM project_cost_plan c WHERE c.project_id = ?), 0) AS planned_cost_total,
                   COALESCE((SELECT ROUND(SUM(c.labor_hours), 2) FROM project_cost_plan c WHERE c.project_id = ?), 0) AS planned_labor_hours_total,
                   COALESCE(ROUND(
                       CASE
                           WHEN SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0) ELSE 0 END) > 0
                           THEN SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0) ELSE 0 END) * 100.0
                                / SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0) ELSE 0 END)
                           ELSE 0
                       END
                   ), 0) AS hours_fact_percent
            FROM tasks t
            WHERE t.project_id = ?
        ');
        $stmt->execute([$projectId, $projectId, $projectId]);
        return $stmt->fetch() ?: [];
    }

    private function financeSummary(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT
                COALESCE(ROUND(SUM(te.minutes) / 60.0, 2), 0) AS actual_hours,
                COALESCE(ROUND(SUM((te.minutes / 60.0) * COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, 0)) / 1000.0, 2), 0) AS actual_cost_thousand,
                COUNT(DISTINCT te.user_id) AS people_count
            FROM time_entries te
            LEFT JOIN users u ON u.id = te.user_id
            LEFT JOIN staffing_periods sp ON sp.status = \'locked\' AND substr(sp.month_start, 1, 7) = substr(te.work_date, 1, 7)
            LEFT JOIN staffing_personal_rates spr ON spr.period_id = sp.id AND spr.user_id = te.user_id
            LEFT JOIN staffing_group_rates sgr ON sgr.period_id = sp.id AND sgr.department_code = u.department
            LEFT JOIN employee_rates er ON er.user_id = te.user_id
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            WHERE te.project_id = ?
        ');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch() ?: [];
        $budget = $this->nullableDecimal($this->projectBudgetValue($projectId));
        $actual = (float) ($row['actual_cost_thousand'] ?? 0);
        $row['budget_manual_thousand'] = $budget;
        $row['budget_remaining_thousand'] = $budget !== null ? round($budget - $actual, 2) : null;

        return $row;
    }

    private function projectBudgetValue(int $projectId): ?string
    {
        try {
            $stmt = $this->db()->prepare('SELECT budget_manual_thousand FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'budget_manual_thousand') !== false) {
                return null;
            }

            throw $e;
        }

        return $value === false || $value === null ? null : (string) $value;
    }

    private function projectModelLinks(int $projectId, ?int $createdBy = null): array
    {
        $stmt = $this->db()->prepare('
            SELECT pml.*, u.name AS created_by_name
            FROM project_model_links pml
            LEFT JOIN users u ON u.id = pml.created_by
            WHERE pml.project_id = ?
            ORDER BY pml.is_primary DESC, pml.created_at DESC, pml.id DESC
        ');
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['can_open_in_atlas'] = $this->canOpenInAtlas((string) ($row['model_url'] ?? ''));
            $row['atlas_href'] = $row['can_open_in_atlas'] ? $this->atlasHref($row, $projectId) : '';
            // Ссылка для ПЕРЕСЫЛКИ строится из канонического адреса сервера
            // (APP_URL), а не из браузера отправителя: иначе тот, кто сидит на
            // сервере по http://localhost/, копирует мёртвый для коллег localhost.
            $row['atlas_share_url'] = $row['atlas_href'] !== '' ? app_url($row['atlas_href']) : '';
            $row['public_url'] = $row['atlas_href'] !== ''
                ? PublicLinkService::publicUrl(PublicLinkService::ensureModelLink($projectId, (int) $row['id'], (string) ($row['title'] ?? 'Модель'), $createdBy))
                : '';
        }
        unset($row);

        return $rows;
    }

    private function primaryModelLink(array $links): ?array
    {
        foreach ($links as $link) {
            if (!empty($link['is_primary']) && !empty($link['can_open_in_atlas'])) {
                return $link;
            }
        }
        foreach ($links as $link) {
            if (!empty($link['can_open_in_atlas'])) {
                return $link;
            }
        }

        return null;
    }

    private function atlasHref(array $modelLink, int $projectId): string
    {
        $kind = (string) ($modelLink['kind'] ?? '');
        // Для локального/сетевого пути отдаём файл через серверный маршрут, чтобы
        // ссылку можно было открыть в браузере у любого в сети.
        $target = $this->atlasModelTarget($modelLink, $projectId);

        // base — канонический адрес сервера (APP_URL). Атлас подменяет им host в
        // ссылке «Ссылка», чтобы скопированный URL не зависел от того, открыл ли
        // отправитель вьювер по localhost или по сетевому имени.
        $query = [
            'locia_return' => '/projects/' . $projectId,
            'project_id' => (string) $projectId,
            'base' => app_url(''),
        ];
        if ($kind === 'frag') {
            // Готовые фрагменты (.frag) — тяжёлая модель, заранее сконвертированная.
            // Отдаём их напрямую как fragUrl: web-ifc в браузере не дёргается вообще.
            $query['frag'] = $target;
        } elseif ($kind === 'ifc' || $kind === 'ifczip') {
            $query['ifc'] = $target;
            // Прозрачный серверный кеш фрагментов: первый открывший пришлёт сюда
            // готовые фрагменты, дальше Атлас грузит их мгновенно у всех.
            $query['frag'] = '/projects/' . $projectId . '/models/' . (int) ($modelLink['id'] ?? 0) . '/fragments';
        } else {
            $query['source'] = $target;
        }

        return '/locia-atlas/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function atlasModelTarget(array $modelLink, int $projectId): string
    {
        $url = trim((string) ($modelLink['model_url'] ?? ''));
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return '/projects/' . $projectId . '/models/' . (int) ($modelLink['id'] ?? 0) . '/file';
    }

    private function canOpenInAtlas(string $modelUrl): bool
    {
        $modelUrl = trim($modelUrl);
        if ($modelUrl === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $modelUrl)) {
            $ext = strtolower(pathinfo((string) (parse_url($modelUrl, PHP_URL_PATH) ?: $modelUrl), PATHINFO_EXTENSION));

            return in_array($ext, ['ifc', 'ifczip', 'json', 'frag'], true);
        }
        if ($this->isAbsoluteLocalPath($modelUrl)) {
            $ext = strtolower(pathinfo($modelUrl, PATHINFO_EXTENSION));

            return in_array($ext, ['ifc', 'ifczip', 'json', 'frag'], true);
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $modelUrl)) {
            return (bool) preg_match('#^https?://#i', $modelUrl);
        }
        // Локальные/сетевые/относительные пути отдаём через серверный маршрут —
        // открываемы, если это файл модели по расширению.
        $ext = strtolower(pathinfo($modelUrl, PATHINFO_EXTENSION));

        return in_array($ext, ['ifc', 'ifczip', 'json', 'frag'], true);
    }

    private function modelScope(string $value): string
    {
        return strtolower(trim($value)) === 'public' ? 'public' : 'project';
    }

    private function modelKind(string $postedKind, string $modelUrl): string
    {
        $postedKind = strtolower(trim($postedKind));
        if (in_array($postedKind, ['json', 'ifc', 'ifczip', 'frag'], true)) {
            return $postedKind;
        }

        $extension = strtolower(pathinfo(parse_url($modelUrl, PHP_URL_PATH) ?: $modelUrl, PATHINFO_EXTENSION));
        return match ($extension) {
            'ifc' => 'ifc',
            'ifczip' => 'ifczip',
            'frag', 'fragments' => 'frag',
            default => 'json',
        };
    }

    private function shouldCreateFolderStructure(): bool
    {
        return isset($_POST['create_folder_structure']);
    }

    private function flashFolderStructureResult(string $root, string $prefix): void
    {
        try {
            $result = (new ProjectFolderService())->create($root);
            flash('success', $prefix . ' Папки: создано ' . (int) $result['created'] . ', уже было ' . (int) $result['existing'] . '.');
        } catch (\RuntimeException $e) {
            flash('error', $prefix . ' Папки не созданы: ' . $e->getMessage());
        }
    }

    private function notFound(): never
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Проект не найден', 'message' => 'Проект не существует или недоступен.']);
        exit;
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для действия.']);
        exit;
    }
}
