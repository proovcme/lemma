<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\DictionaryService;
use App\Services\DocumentRevisionService;
use App\Services\IncidentLogService;
use App\Services\NotificationOutboxService;
use App\Services\PermissionService;
use App\Services\ProjectAccountingService;
use App\Services\ProjectTaskExchangeService;
use App\Services\PublicLinkService;
use App\Services\RoleService;
use App\Services\TagService;
use App\Services\TaskCostGroupService;
use App\Services\TaskAttachmentService;
use App\Services\TaskActionQueueService;
use App\Services\TaskWorkflowIntegrityService;
use App\Services\TaskWorkflowService;
use App\Services\VacationService;

final class TaskController extends BaseController
{
    private const LABOR_HOURS_PER_DAY = 8.0;

    public function locia(): void
    {
        $this->index('mine', $_GET['view'] ?? 'table', true);
    }

    public function mine(): void
    {
        $this->index('mine', $_GET['view'] ?? 'table');
    }

    public function all(): void
    {
        $this->index('all', $_GET['view'] ?? 'table');
    }

    public function table(): void
    {
        $this->index('all', 'table');
    }

    public function board(): void
    {
        $this->index('all', 'board');
    }

    public function bimFamilyRegistry(): void
    {
        $user = require_auth();
        if (!PermissionService::canOpenProjects($user)) {
            $this->forbidden();
        }

        $this->ensureBimFamilyCustomFields();
        TaskWorkflowService::markOverdue();

        $this->render('tasks/bim_family_registry', [
            'title' => 'Заявки на семейства ТИМ',
            'subtitle' => 'Реестр по образцу BIM-отдела',
            'headerActions' => [
                ['label' => 'Новая заявка', 'href' => '/tasks/new?task_intent=bim_family_request', 'class' => 'btn-red'],
            ],
            'rows' => $this->bimFamilyRows($user),
            'projects' => $this->projectsFor($user),
            'users' => $this->activeUsers(),
            'filters' => $_GET,
        ]);
    }

    public function createForm(): void
    {
        $user = require_auth();
        if (!PermissionService::canCreateTasks($user)) {
            $this->forbidden();
        }

        $selectedProjectId = ($_GET['project_id'] ?? '') !== '' ? (int) $_GET['project_id'] : null;
        if (($_GET['task_intent'] ?? '') === 'bim_family_request' || ($_GET['task_type'] ?? '') === 'bim_family_request') {
            $this->ensureBimFamilyCustomFields();
        }
        $includeAllProjectFields = $selectedProjectId === null;
        $projects = $this->projectsFor($user);
        $visibleProjectIds = array_map(static fn (array $project): int => (int) $project['id'], $projects);
        $relationTasks = $this->relationTasks($user, $selectedProjectId);
        $requestedParentId = ($_GET['parent_id'] ?? '') !== '' ? (int) $_GET['parent_id'] : null;
        $relationTasks = $this->ensureRelationTaskOption($relationTasks, $user, $requestedParentId, $selectedProjectId);

        $this->render('tasks/form', [
            'title' => 'Новая задача',
            'headerActions' => [
                ['label' => 'К задачам', 'href' => '/tasks', 'class' => 'btn-outline'],
                ['label' => 'Добавить задачу', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'task-form', 'class' => 'btn-red'],
            ],
            'task' => null,
            'smart' => null,
            'customValues' => [],
            'participants' => $this->emptyParticipants(),
            'projects' => $projects,
            'users' => $this->activeUsers(),
            'customFields' => $this->customFields($selectedProjectId, $includeAllProjectFields, $visibleProjectIds),
            'taskTags' => [],
            'tagOptions' => TagService::tagsForProject($selectedProjectId),
            'accounting' => ProjectAccountingService::forTaskForm($selectedProjectId, $visibleProjectIds),
            'dictionaries' => DictionaryService::forTaskForm($selectedProjectId),
            'relationTasks' => $relationTasks,
        ]);
    }

    public function store(): void
    {
        $user = require_auth();
        if (!PermissionService::canCreateTasks($user)) {
            $this->forbidden();
        }

        $data = $this->taskPayload();
        $this->validateSmart($data, true);
        if (!PermissionService::canCreateTaskType($user, (string) $data['task_type'])) {
            $this->forbidden();
        }
        if ($data['task_type'] === 'bim_family_request') {
            $this->ensureBimFamilyCustomFields();
        }
        $this->ensureProjectIsActive((int) $data['project_id']);
        $this->ensureTaskProjectScope($user, (int) $data['project_id'], $data['parent_id'] ? (int) $data['parent_id'] : null);
        $customValues = $this->customPayloads((int) $data['project_id'], (string) $data['task_type']);
        $this->validateCustomPayloads($customValues);
        $tagNames = TagService::parseNames($_POST['tags'] ?? '');
        $preparedAttachments = $this->preparedAttachments('/tasks/new');

        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER]) && $data['task_type'] !== 'assignment') {
            $data['assignee_id'] = (int) $user['id'];
        }
        $this->applyAssigneeCostGroup($data);

        // Проверяющий не может быть исполнителем (самосогласование запрещено):
        // если выбран сам исполнитель — подбираем проверяющего автоматически.
        if ((int) $data['reviewer_id'] === (int) $data['assignee_id']) {
            $data['reviewer_id'] = null;
        }

        $reviewerId = $data['task_type'] === TaskWorkflowService::TASK_TYPE_DELEGATION
            ? null
            : $data['reviewer_id'];
        $reviewerId = $reviewerId ?: ($data['task_type'] === TaskWorkflowService::TASK_TYPE_DELEGATION ? null : TaskWorkflowService::defaultReviewerId((int) $data['assignee_id']));
        $reviewerId = $reviewerId ?: null;
        $plannedHours = $data['planned_hours'] !== null ? $data['planned_hours'] : working_hours($data['date_start'], $data['date_end']);

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
        $stmt = $pdo->prepare('
            INSERT INTO tasks (
                title, task_type, project_id, project_section_id, parent_id, assignee_id, author_id, reviewer_id, discipline, volume, section, cost_group_code,
                status, priority, urgency, date_start, date_end, date_end_original, planned_hours, progress, pp_code_id, btp_code_id, btp, speckle_stream_url
            )
            VALUES (
                :title, :task_type, :project_id, :project_section_id, :parent_id, :assignee_id, :author_id, :reviewer_id, :discipline, :volume, :section, :cost_group_code,
                :status, :priority, :urgency, :date_start, :date_end, :date_end_original, :planned_hours, :progress, :pp_code_id, :btp_code_id, :btp, :speckle_stream_url
            )
        ');
        $stmt->execute([
            'title' => $data['title'],
            'task_type' => $data['task_type'],
            'project_id' => $data['project_id'],
            'project_section_id' => $data['project_section_id'],
            'parent_id' => $data['parent_id'],
            'assignee_id' => $data['assignee_id'],
            'author_id' => $user['id'],
            'reviewer_id' => $reviewerId,
            'discipline' => $data['discipline'],
            'volume' => $data['volume'],
            'section' => $data['section'],
            'cost_group_code' => $data['cost_group_code'],
            'status' => $data['status'],
            'priority' => $data['priority'],
            'urgency' => $data['urgency'],
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'date_end_original' => $data['date_end'],
            'planned_hours' => $plannedHours,
            'progress' => $data['progress'],
            'pp_code_id' => $data['pp_code_id'],
            'btp_code_id' => $data['btp_code_id'],
            'btp' => $data['btp'],
            'speckle_stream_url' => $data['speckle_stream_url'],
        ]);

        $taskId = (int) $this->db()->lastInsertId();
        $this->upsertSmart($taskId, $data);
        (new ProjectTaskExchangeService($pdo))->syncTask($taskId);
        $this->saveCustomValues($taskId, $customValues);
        $this->saveParticipants($taskId, $data);
        $this->saveAtlasRef($taskId, (int) $data['project_id'], (int) $user['id'], $this->atlasPayload($_POST));
        TagService::syncTaskTags($taskId, (int) $data['project_id'], $tagNames, (int) $user['id']);
        $storedAttachments = TaskAttachmentService::storePrepared($taskId, (int) $user['id'], $preparedAttachments, $pdo);
        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $incidentId = IncidentLogService::report($e, ['operation' => 'task_create', 'project_id' => $data['project_id']]);
            flash('error', IncidentLogService::userMessage($incidentId, 'создать задачу'));
            redirect('/tasks/new');
        }
        TaskWorkflowService::recomputeParentProgress($data['parent_id']);
        ActivityLogService::recordTask($taskId, (int) $user['id'], 'task.created', 'Задача создана', $data['title']);
        if ($storedAttachments !== []) {
            ActivityLogService::recordTask($taskId, (int) $user['id'], 'task.attachments_added', 'Добавлены вложения', count($storedAttachments) . ' файл(ов)');
        }
        TaskWorkflowService::notify(
            (int) $data['assignee_id'],
            $taskId,
            'task_created',
            'Вам поставлена новая задача #' . $taskId . ': ' . $data['title']
        );
        NotificationOutboxService::queueTaskCreated($taskId, (int) $user['id']);

        flash('success', 'Задача создана.');
        redirect('/tasks/' . $taskId);
    }

    public function show(int $id): void
    {
        $user = require_auth();
        TaskWorkflowService::markOverdue();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }

        $this->markCommentsRead($id, (int) $user['id']);
        $smart = $this->smart($id);
        $pendingDeadlineShift = $this->pendingDeadlineShift($id);
        $participants = $this->participants($id);
        $taskTags = TagService::tagsForTask($id);
        $drawerMode = ($_GET['drawer'] ?? '') === '1';
        $laborEstimate = (string) ($task['task_type'] ?? 'work') === 'labor_estimate'
            ? $this->laborEstimateForTask($id)
            : null;
        $relationTasks = $this->relationTasks($user, (int) $task['project_id'], $id);
        $relationTasks = $this->ensureRelationTaskOption(
            $relationTasks,
            $user,
            $task['parent_id'] ? (int) $task['parent_id'] : null,
            (int) $task['project_id']
        );
        $taskFormProjects = $this->projectsFor($user);

        $this->render('tasks/show', [
            'title' => '#' . $id . ' ' . $task['title'],
            'headerActions' => $this->taskHeaderActions($task, PermissionService::canEditTask($user, $task), ($_GET['edit'] ?? '') === '1', $drawerMode),
            'task' => $task,
            'taskPublicUrl' => PublicLinkService::publicUrl(PublicLinkService::ensureTaskLink($id, '#' . $id . ' ' . (string) $task['title'], (int) $user['id'])),
            'smart' => $smart,
            'participants' => $participants,
            'issuances' => $this->issuances($id),
            'documentRevisions' => DocumentRevisionService::listForTask($this->db(), $id),
            'canManageIssuances' => $this->canManageIssuances($user, $task),
            'canSubmitApproval' => $this->canSubmitApproval($user, $task),
            'canLeadApprove' => $this->canLeadApprove($user, $task),
            'canGipApprove' => $this->canGipApprove($user, $task),
            'canAcceptCloseByAuthor' => $this->canAcceptCloseByAuthor($user, $task),
            'canAcceptCloseByGip' => $this->canAcceptCloseByGip($user, $task),
            'canRespondAssignment' => $this->canRespondToAssignment($user, $task),
            'canLogTime' => $this->canLogTime($user, $task),
            'closeRequiresGip' => $this->requiresGipCloseAcceptance($task),
            'closeAuthorAccepted' => $this->closeStageApproved($task, 'close_author'),
            'closeGipAccepted' => $this->closeStageApproved($task, 'close_gip'),
            'canDecideReviewCycle' => $this->canDecideReviewCycle($user, $task),
            'laborEstimate' => $laborEstimate,
            'canSubmitLaborEstimate' => $laborEstimate !== null && $this->canSubmitLaborEstimate($user, $task, $laborEstimate),
            'canGipApproveLaborEstimate' => $laborEstimate !== null && $this->canGipApproveLaborEstimate($user, $task, $laborEstimate),
            'canDirectorApproveLaborEstimate' => $laborEstimate !== null && $this->canDirectorApproveLaborEstimate($user, $task, $laborEstimate),
            'canSeeLaborEstimateMoney' => $laborEstimate !== null && PermissionService::canSeeLaborMoney($user),
            'canSeeLaborEstimateRate' => $laborEstimate !== null && PermissionService::canManageEmployeeRates($user),
            'canAdminCloseTask' => $this->canForceCloseTask($user, $task) && (string) ($task['status'] ?? '') !== 'done',
            'canAdminDeleteTask' => $this->canAdministerTask($user, $task),
            'canManageDelegation' => $this->canManageDelegation($user, $task),
            'approvalHistory' => $this->approvalHistory($id),
            'lastIssuanceAccepted' => $this->lastIssuanceAccepted($id),
            'blockingData' => $this->blockingData($id, (int) $task['project_id']),
            'linkedIssues' => $this->linkedIssues($id),
            'linkedSections' => $this->linkedSections($id),
            'atlasRefs' => $this->atlasRefs($id),
            'dependencyTask' => $this->dependencyTask((string) ($smart['depends_on'] ?? '')),
            'children' => $this->children($id),
            'comments' => $this->comments($id),
            'logs' => $this->logs($id),
            'attachments' => TaskAttachmentService::forTask($id, $this->db()),
            'canUploadAttachments' => !$this->isArchivedTask($task) && $this->canUploadAttachments($user, $task),
            'shifts' => $this->deadlineShifts($id),
            'pendingDeadlineShift' => $pendingDeadlineShift,
            'canDecideDeadlineShift' => $pendingDeadlineShift !== null && $this->canReview($user, $task),
            'projects' => $taskFormProjects,
            'users' => $this->activeUsers(),
            'customFields' => $this->customFields((int) $task['project_id']),
            'customValues' => $this->customValues($id),
            'taskTags' => $taskTags,
            'tagOptions' => TagService::tagsForProject((int) $task['project_id']),
            'accounting' => ProjectAccountingService::forTaskForm((int) $task['project_id']),
            'dictionaries' => DictionaryService::forTaskForm((int) $task['project_id']),
            'relationTasks' => $relationTasks,
            'reasons' => $this->deadlineReasons(),
            'canEdit' => PermissionService::canEditTask($user, $task),
            'editMode' => ($_GET['edit'] ?? '') === '1',
        ]);
    }

    public function update(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $drawerQuery = ($_POST['drawer'] ?? '') === '1' ? '?drawer=1' : '';
        if (!$task) {
            $this->notFound();
        }
        if (!PermissionService::canEditTask($user, $task)) {
            $this->forbidden();
        }

        $data = $this->taskPayload();
        $data['project_section_id'] = (int) $data['project_id'] === (int) $task['project_id']
            ? ($task['project_section_id'] ?? null)
            : null;
        // The discontinued task-volume field is history: editing a legacy task must not erase it.
        $data['volume'] = (string) ($task['volume'] ?? '');
        $this->validateSmart($data, false);
        if ((string) $data['task_type'] !== (string) ($task['task_type'] ?? 'work') && !PermissionService::canCreateTaskType($user, (string) $data['task_type'])) {
            $this->forbidden();
        }
        if ($data['task_type'] === 'bim_family_request') {
            $this->ensureBimFamilyCustomFields();
        }
        $this->ensureProjectIsActive((int) $data['project_id']);
        $this->ensureTaskProjectScope($user, (int) $data['project_id'], $data['parent_id'] ? (int) $data['parent_id'] : null);
        $customValues = $this->customPayloads((int) $data['project_id'], (string) $data['task_type']);
        $this->validateCustomPayloads($customValues);
        $tagNames = TagService::parseNames($_POST['tags'] ?? '');
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER]) && $data['task_type'] !== 'assignment') {
            $data['assignee_id'] = (int) $user['id'];
        }
        $this->applyAssigneeCostGroup($data);
        // Проверяющий не может быть исполнителем (самосогласование запрещено).
        if ((int) $data['reviewer_id'] === (int) $data['assignee_id']) {
            $data['reviewer_id'] = null;
        }
        if ($data['task_type'] === TaskWorkflowService::TASK_TYPE_DELEGATION) {
            $data['reviewer_id'] = null;
        }
        if ($data['status'] === 'done' && $task['status'] !== 'done') {
            flash('error', 'Закрытие задачи выполняется через подачу на закрытие и приёмку постановщиком.');
            redirect('/tasks/' . $id . $drawerQuery);
        }
        if (in_array((string) $data['status'], ['review', 'pending_close'], true)
            && (string) $data['status'] !== (string) ($task['status'] ?? '')) {
            flash('error', 'Передача на проверку выполняется отдельным действием по маршруту задачи.');
            redirect('/tasks/' . $id . $drawerQuery);
        }

        $currentSmart = $this->smart($id) ?: [];
        $requestedDeadline = $data['date_end'];
        $currentDeadline = $this->dateOrNull($task['date_end'] ?? '');
        $deadlineChangeRequested = $requestedDeadline !== $currentDeadline;
        $deadlinePendingMessage = false;
        $deadlinePendingRequest = null;
        $deadlineApprovedByReviewer = false;
        if ($deadlineChangeRequested) {
            if ($this->canReview($user, $task)) {
                $deadlineApprovedByReviewer = true;
            } else {
                $deadlinePendingRequest = $requestedDeadline;
                $data['date_end'] = $currentDeadline;
                $data['when_due'] = $this->dateOrNull($currentSmart['when_due'] ?? '') ?: ($currentDeadline ?? '');
                $deadlinePendingMessage = true;
            }
        }

        $plannedHours = $data['planned_hours'] !== null ? $data['planned_hours'] : working_hours($data['date_start'], $data['date_end']);

        $tracked = ['status', 'task_type', 'assignee_id', 'date_end', 'priority', 'urgency'];
        foreach ($tracked as $field) {
            TaskWorkflowService::log($id, (int) $user['id'], $field, $task[$field] ?? '', $data[$field] ?? '');
        }

        $stmt = $this->db()->prepare('
            UPDATE tasks
            SET title = :title,
                task_type = :task_type,
                project_id = :project_id,
                project_section_id = :project_section_id,
                parent_id = :parent_id,
                assignee_id = :assignee_id,
                reviewer_id = :reviewer_id,
                discipline = :discipline,
                volume = :volume,
                section = :section,
                cost_group_code = :cost_group_code,
                status = :status,
                priority = :priority,
                urgency = :urgency,
                date_start = :date_start,
                date_end = :date_end,
                planned_hours = :planned_hours,
                progress = :progress,
                pp_code_id = :pp_code_id,
                btp_code_id = :btp_code_id,
                btp = :btp,
                speckle_stream_url = :speckle_stream_url
            WHERE id = :id
        ');
        $stmt->execute([
            'title' => $data['title'],
            'task_type' => $data['task_type'],
            'project_id' => $data['project_id'],
            'project_section_id' => $data['project_section_id'],
            'parent_id' => $data['parent_id'],
            'assignee_id' => $data['assignee_id'],
            'reviewer_id' => $data['reviewer_id'],
            'discipline' => $data['discipline'],
            'volume' => $data['volume'],
            'section' => $data['section'],
            'cost_group_code' => $data['cost_group_code'],
            'status' => $data['status'],
            'priority' => $data['priority'],
            'urgency' => $data['urgency'],
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'planned_hours' => $plannedHours,
            'progress' => $data['progress'],
            'pp_code_id' => $data['pp_code_id'],
            'btp_code_id' => $data['btp_code_id'],
            'btp' => $data['btp'],
            'speckle_stream_url' => $data['speckle_stream_url'],
            'id' => $id,
        ]);

        $this->upsertSmart($id, $data);
        (new ProjectTaskExchangeService($this->db()))->syncTask($id);
        $this->saveCustomValues($id, $customValues);
        $this->saveParticipants($id, $data);
        TagService::syncTaskTags($id, (int) $data['project_id'], $tagNames, (int) $user['id']);
        TaskWorkflowService::recomputeParentProgress($task['parent_id'] ? (int) $task['parent_id'] : null);
        TaskWorkflowService::recomputeParentProgress($data['parent_id']);

        if ($deadlineApprovedByReviewer) {
            $this->recordApprovedDeadlineShiftFromEdit($task, (int) $user['id'], $requestedDeadline);
        } elseif ($deadlinePendingRequest !== null) {
            $this->requestDeadlineShiftFromEdit($task, (int) $user['id'], $deadlinePendingRequest);
        }

        flash('success', $deadlinePendingMessage
            ? 'Задача обновлена. Новый срок отправлен проверяющему на подтверждение.'
            : 'Задача обновлена.'
        );
        redirect('/tasks/' . $id . $drawerQuery);
    }

    public function uploadAttachments(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        if ($this->isArchivedTask($task) || !$this->canUploadAttachments($user, $task)) {
            $this->forbidden();
        }

        $pdo = $this->db();
        try {
            $prepared = TaskAttachmentService::validateIncoming($_FILES['attachments'] ?? null);
            if ($prepared === []) {
                flash('error', 'Выберите хотя бы один файл или фотографию.');
                redirect('/tasks/' . $id);
            }
            $pdo->beginTransaction();
            $stored = TaskAttachmentService::storePrepared($id, (int) $user['id'], $prepared, $pdo);
            $pdo->commit();
            ActivityLogService::recordTask($id, (int) $user['id'], 'task.attachments_added', 'Добавлены вложения', count($stored) . ' файл(ов)', [
                'attachment_ids' => array_column($stored, 'id'),
            ]);
            TaskWorkflowService::log($id, (int) $user['id'], 'attachments', '', count($stored) . ' файл(ов)');
            flash('success', count($stored) === 1 ? 'Файл прикреплён к задаче.' : 'Файлы прикреплены к задаче: ' . count($stored) . '.');
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $incidentId = IncidentLogService::report($e, ['operation' => 'task_attachment_upload', 'task_id' => $id]);
            flash('error', IncidentLogService::userMessage($incidentId, 'прикрепить файлы'));
        }
        redirect('/tasks/' . $id);
    }

    public function downloadAttachment(int $id, int $attachmentId): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        $attachment = TaskAttachmentService::findForTask($id, $attachmentId, $this->db());
        if ($attachment === null || !is_file((string) $attachment['absolute_path'])) {
            http_response_code(404);
            $this->render('layouts/error', [
                'title' => 'Файл не найден',
                'message' => 'Вложение удалено или недоступно в хранилище. Обратитесь к автору задачи.',
            ]);
            return;
        }

        $filename = str_replace(["\r", "\n", '"'], '', (string) $attachment['filename']);
        header('Content-Type: ' . (string) $attachment['mime_type']);
        header('Content-Length: ' . (string) filesize((string) $attachment['absolute_path']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'none'; sandbox");
        header('Content-Disposition: ' . (!empty($attachment['is_image']) ? 'inline' : 'attachment') . '; filename="' . rawurlencode($filename) . '"');
        readfile((string) $attachment['absolute_path']);
        exit;
    }

    public function deleteAttachment(int $id, int $attachmentId): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        $attachment = TaskAttachmentService::findForTask($id, $attachmentId, $this->db());
        if ($attachment === null) {
            flash('error', 'Вложение уже удалено. Обновите страницу.');
            redirect('/tasks/' . $id);
        }
        $canDelete = (int) ($attachment['user_id'] ?? 0) === (int) $user['id']
            || PermissionService::canEditTask($user, $task)
            || $this->canAdministerTask($user, $task);
        if ($this->isArchivedTask($task) || !$canDelete) {
            $this->forbidden();
        }

        TaskAttachmentService::delete($id, $attachmentId, $this->db());
        TaskWorkflowService::log($id, (int) $user['id'], 'attachments', (string) $attachment['filename'], 'удалено');
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.attachment_deleted', 'Вложение удалено', (string) $attachment['filename']);
        flash('success', 'Вложение удалено.');
        redirect('/tasks/' . $id);
    }

    public function status(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !PermissionService::canUpdateTaskExecution($user, $task)) {
            json_response(['ok' => false, 'message' => 'Нет доступа'], 403);
        }

        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $status = (string) ($payload['status'] ?? '');
        if (!in_array($status, ['new', 'in_progress', TaskWorkflowService::STATUS_CORRECTION, 'blocked'], true)) {
            json_response(['ok' => false, 'message' => 'Некорректный статус'], 422);
        }
        if ($status === (string) $task['status']) {
            json_response(['ok' => true, 'status' => $status, 'label' => task_status_label($status)]);
        }

        $this->db()->prepare('UPDATE tasks SET status = ? WHERE id = ?')->execute([$status, $id]);
        TaskWorkflowService::log($id, (int) $user['id'], 'status', $task['status'], $status);
        TaskWorkflowService::recomputeParentProgress($task['parent_id'] ? (int) $task['parent_id'] : null);
        json_response(['ok' => true, 'status' => $status, 'label' => task_status_label($status)]);
    }

    public function assignmentResponse(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $back = $this->taskActionBack($id);
        if (!$task || !$this->canRespondToAssignment($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            flash('error', 'Некорректный ответ по задаче.');
            redirect('/tasks/' . $id);
        }
        if ($decision === 'rejected' && $comment === '') {
            flash('error', 'Для отклонения задачи укажите причину.');
            redirect('/tasks/' . $id);
        }

        if ($decision === 'accepted') {
            $oldStatus = (string) $task['status'];
            $oldDateStart = (string) ($task['date_start'] ?? '');
            $dateStart = $oldDateStart !== '' ? $oldDateStart : date('Y-m-d');
            $this->db()->prepare('
                UPDATE tasks
                SET status = "in_progress",
                    date_start = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$dateStart, $id]);
            TaskWorkflowService::log($id, (int) $user['id'], 'status', $oldStatus, 'in_progress');
            if ($oldDateStart !== $dateStart) {
                TaskWorkflowService::log($id, (int) $user['id'], 'date_start', $oldDateStart, $dateStart);
            }
            ActivityLogService::recordTask($id, (int) $user['id'], 'task.assignment_accepted', 'Задача принята в работу', 'Дата начала: ' . format_date($dateStart));
            if (!empty($task['author_id'])) {
                TaskWorkflowService::notify((int) $task['author_id'], $id, 'task_accepted', 'Исполнитель принял задачу #' . $id . ' в работу.');
            }
            flash('success', 'Задача принята в работу. Дата начала записана автоматически.');
            redirect($back);
        }

        $oldStatus = (string) $task['status'];
        $this->db()->prepare('
            UPDATE tasks
            SET status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([TaskWorkflowService::STATUS_CORRECTION, $id]);
        $body = 'Исполнитель отклонил задачу: ' . $comment;
        $this->db()->prepare('INSERT INTO comments (task_id, user_id, body, mention_ids) VALUES (?, ?, ?, ?)')
            ->execute([$id, (int) $user['id'], $body, json_encode([], JSON_UNESCAPED_UNICODE)]);
        TaskWorkflowService::log($id, (int) $user['id'], 'status', $oldStatus, TaskWorkflowService::STATUS_CORRECTION);
        TaskWorkflowService::log($id, (int) $user['id'], 'assignment_response', 'Ожидает ответа', 'Отклонена: ' . $comment);
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.assignment_rejected', 'Задача отклонена исполнителем', $comment);
        $this->notifyDistinctUsers([
            (int) ($task['author_id'] ?? 0),
            (int) ($task['reviewer_id'] ?? 0),
        ], $id, 'task_rejected', 'Исполнитель отклонил задачу #' . $id . ': ' . $comment);

        flash('success', 'Отклонение записано. Постановщик увидит причину в чате и истории.');
        redirect($back);
    }

    public function takeDelegation(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $back = $this->taskActionBack($id);
        if (!$task || !$this->canManageDelegation($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $oldStatus = (string) ($task['status'] ?? '');
        $oldType = (string) ($task['task_type'] ?? '');
        $oldDateStart = (string) ($task['date_start'] ?? '');
        $oldReviewerId = (int) ($task['reviewer_id'] ?? 0);
        $dateStart = $oldDateStart !== '' ? $oldDateStart : date('Y-m-d');
        $reviewerId = (int) ($task['author_id'] ?? 0);
        if ($reviewerId <= 0 || $reviewerId === (int) $user['id']) {
            $reviewerId = null;
        }
        $this->db()->prepare('
            UPDATE tasks
            SET task_type = "work",
                status = "in_progress",
                assignee_id = ?,
                reviewer_id = ?,
                date_start = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $user['id'], $reviewerId, $dateStart, $id]);

        TaskWorkflowService::log($id, (int) $user['id'], 'task_type', $oldType, 'work');
        TaskWorkflowService::log($id, (int) $user['id'], 'status', $oldStatus, 'in_progress');
        if ($oldReviewerId !== (int) ($reviewerId ?? 0)) {
            TaskWorkflowService::log($id, (int) $user['id'], 'reviewer_id', $oldReviewerId > 0 ? (string) $oldReviewerId : '', $reviewerId !== null ? (string) $reviewerId : '');
        }
        if ($oldDateStart !== $dateStart) {
            TaskWorkflowService::log($id, (int) $user['id'], 'date_start', $oldDateStart, $dateStart);
        }
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.delegation_taken', 'Делегирование взято в работу', 'Руководитель взял задачу на себя.');
        if (!empty($task['author_id']) && (int) $task['author_id'] !== (int) $user['id']) {
            TaskWorkflowService::notify((int) $task['author_id'], $id, 'delegation_taken', 'Руководитель взял делегированную задачу #' . $id . ' на себя.');
        }

        flash('success', 'Делегирование превращено в обычную рабочую задачу и принято в работу.');
        redirect($back);
    }

    public function returnDelegation(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $back = $this->taskActionBack($id);
        if (!$task || !$this->canManageDelegation($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $comment = trim((string) ($_POST['comment'] ?? ''));
        if ($comment === '') {
            flash('error', 'Укажите, что нужно уточнить для распределения.');
            redirect($back);
        }

        $oldStatus = (string) ($task['status'] ?? '');
        $this->db()->prepare('
            UPDATE tasks
            SET status = "blocked",
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$id]);
        $body = 'Руководитель вернул делегирование ГИПу: ' . $comment;
        $this->db()->prepare('INSERT INTO comments (task_id, user_id, body, mention_ids) VALUES (?, ?, ?, ?)')
            ->execute([$id, (int) $user['id'], $body, json_encode([], JSON_UNESCAPED_UNICODE)]);
        TaskWorkflowService::log($id, (int) $user['id'], 'status', $oldStatus, 'blocked');
        TaskWorkflowService::log($id, (int) $user['id'], 'delegation_return', 'На распределении', $comment);
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.delegation_returned', 'Делегирование возвращено ГИПу', $comment);
        if (!empty($task['author_id']) && (int) $task['author_id'] !== (int) $user['id']) {
            TaskWorkflowService::notify((int) $task['author_id'], $id, 'delegation_returned', 'Руководитель вернул делегирование #' . $id . ': ' . $comment);
        }

        flash('success', 'Делегирование возвращено постановщику с комментарием.');
        redirect($back);
    }

    public function comment(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            flash('error', 'Комментарий не может быть пустым.');
            redirect('/tasks/' . $id);
        }

        $mentions = TaskWorkflowService::extractMentions($body);
        $stmt = $this->db()->prepare('INSERT INTO comments (task_id, user_id, body, mention_ids) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $user['id'], $body, json_encode($mentions, JSON_UNESCAPED_UNICODE)]);
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.comment', 'Добавлен комментарий', $body);

        foreach ($mentions as $mentionedUserId) {
            TaskWorkflowService::notify($mentionedUserId, $id, 'mention', 'Вас упомянули в задаче #' . $id);
        }

        redirect('/tasks/' . $id);
    }

    public function requestClose(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canRequestClose($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }
        if (!in_array((string) $task['status'], ['in_progress', 'overdue', TaskWorkflowService::STATUS_CORRECTION], true)) {
            flash('error', 'Сдать результат проверяющему можно только для задачи в работе, просрочке или корректировке.');
            redirect('/tasks/' . $id);
        }
        if ((string) ($task['task_type'] ?? 'work') === TaskWorkflowService::TASK_TYPE_REVIEW) {
            flash('error', 'Техническая проверка больше не используется. Откройте исходную задачу.');
            redirect('/tasks/' . $id);
        }
        if (!TaskWorkflowIntegrityService::canStartClose($task)) {
            flash('error', 'Сначала завершите активную цепочку согласования задачи. Закрытие и согласование не могут идти параллельно.');
            redirect('/tasks/' . $id);
        }
        $reviewerId = (int) ($task['reviewer_id'] ?? 0);
        if ($reviewerId <= 0 && (int) ($task['assignee_id'] ?? 0) > 0) {
            $reviewerId = TaskWorkflowService::defaultReviewerId((int) $task['assignee_id']) ?? 0;
        }
        if ($reviewerId <= 0) {
            flash('error', 'Назначьте проверяющего перед отправкой результата.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            // Атомарный захват перехода: только один параллельный запрос переведёт
            // задачу в "review". Второй (двойной клик / гонка) получит rowCount 0 и
            // не продублирует подачу результата. Работает и в SQLite, и в MySQL.
            $guard = $this->db()->prepare("
                UPDATE tasks
                SET status = 'review', close_requested_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status IN ('in_progress', 'overdue', 'correction')
                  AND COALESCE(approval_stage, 'draft') NOT IN ('review_lead', 'review_gip')
            ");
            $guard->execute([$id]);
            if ($guard->rowCount() === 0) {
                $this->db()->rollBack();
                flash('error', 'Задача уже отправлена на проверку или её статус изменился. Обновите страницу.');
                redirect('/tasks/' . $id);
            }

            if ((int) ($task['reviewer_id'] ?? 0) !== $reviewerId) {
                $this->db()->prepare('UPDATE tasks SET reviewer_id = ? WHERE id = ?')->execute([$reviewerId, $id]);
                TaskWorkflowService::log($id, (int) $user['id'], 'reviewer_id', (string) ($task['reviewer_id'] ?? ''), (string) $reviewerId);
                $task['reviewer_id'] = $reviewerId;
            }

            TaskWorkflowService::log($id, (int) $user['id'], 'status', $task['status'], 'review');
            TaskWorkflowService::log($id, (int) $user['id'], 'review_cycle', '', 'Результат сдан проверяющему');
            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        TaskWorkflowService::notify($reviewerId, $id, 'review_task_created', 'Вам назначена проверка результата задачи #' . $id . '.');
        NotificationOutboxService::queueTaskReviewSubmitted($id, $reviewerId, (int) $user['id']);
        ActivityLogService::recordTask($id, (int) $user['id'], 'task.review_submitted', 'Результат отправлен на проверку', 'Проверка ведётся в исходной задаче.');

        flash('success', 'Результат отправлен проверяющему.');
        redirect('/tasks/' . $id);
    }

    public function reviewCycleDecision(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canDecideReviewCycle($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Некорректное решение по проверке.');
            redirect('/tasks/' . $id);
        }
        if ($decision === 'rejected' && $comment === '') {
            flash('error', 'Для возврата на корректировку обязателен комментарий.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            $currentReview = $this->db()->prepare('
                SELECT status, close_requested_at
                FROM tasks
                WHERE id = ?
                LIMIT 1
            ');
            $currentReview->execute([$id]);
            $currentReviewRow = $currentReview->fetch() ?: [];
            if (!in_array((string) ($currentReviewRow['status'] ?? ''), ['review', 'pending_close'], true)
                || trim((string) ($currentReviewRow['close_requested_at'] ?? '')) === ''
                || $this->reviewCycleDecisionRecorded($task)
            ) {
                $this->db()->rollBack();
                flash('error', 'По этой проверке уже принято решение.');
                redirect('/tasks/' . $id);
            }
            $this->recordApproval($id, 'review_task', (int) $user['id'], $decision, $comment);
            $this->closeLegacyReviewChildren($id, (int) $user['id']);

            if ($decision === 'approved') {
                TaskWorkflowService::log($id, (int) $user['id'], 'review_cycle', 'На проверке', 'Принято проверяющим');
                ActivityLogService::recordTask($id, (int) $user['id'], 'task.review_accepted', 'Проверяющий принял результат', $comment !== '' ? $comment : null);
                $this->finalizeClose($task, (int) $user['id']);
            } else {
                $this->db()->prepare('
                    UPDATE tasks
                    SET status = ?, close_requested_at = NULL, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ')->execute([TaskWorkflowService::STATUS_CORRECTION, $id]);
                TaskWorkflowService::log($id, (int) $user['id'], 'status', (string) $task['status'], TaskWorkflowService::STATUS_CORRECTION);
                TaskWorkflowService::log($id, (int) $user['id'], 'review_cycle', 'Возврат', $comment);
                $this->db()->prepare('INSERT INTO comments (task_id, user_id, body, mention_ids) VALUES (?, ?, ?, ?)')
                    ->execute([$id, (int) $user['id'], 'Возврат на корректировку: ' . $comment, json_encode([], JSON_UNESCAPED_UNICODE)]);
                if ($task['assignee_id']) {
                    TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'review_rejected', 'Задача #' . $id . ' возвращена на корректировку: ' . $comment);
                }
                ActivityLogService::recordTask($id, (int) $user['id'], 'task.review_rejected', 'Проверяющий вернул результат', $comment);
            }

            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        flash('success', $decision === 'approved' ? 'Результат принят, исходная задача закрыта.' : 'Задача возвращена исполнителю на корректировку.');
        redirect('/tasks/' . $id);
    }

    public function submitApproval(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canSubmitApproval($user, $task)) {
            $this->forbidden();
        }

        $stage = (string) ($task['approval_stage'] ?? 'draft');
        if (!in_array($stage, ['draft', 'issued'], true)) {
            flash('error', 'Задача уже находится в цепочке согласования.');
            redirect('/tasks/' . $id);
        }
        if ($this->lastIssuanceAccepted($id)) {
            flash('error', 'Последняя выдача уже принята.');
            redirect('/tasks/' . $id);
        }
        $leadReviewerId = $this->approvalLeadReviewerId($task);
        $initialStage = $leadReviewerId !== null ? 'review_lead' : 'review_gip';
        if ((string) ($task['task_type'] ?? 'work') === 'bim_family_request' && $leadReviewerId === null) {
            flash('error', 'Назначьте проверяющего или наблюдателя для согласования заявки ТИМ.');
            redirect('/tasks/' . $id);
        }
        if ($initialStage === 'review_gip' && $this->approvalGipApproverId($task) === null) {
            flash('error', 'Назначьте проверяющего, наблюдателя или ГИПа проекта перед подачей на согласование.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            if ($leadReviewerId !== null && (int) ($task['reviewer_id'] ?? 0) !== $leadReviewerId) {
                $this->db()->prepare('UPDATE tasks SET reviewer_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$leadReviewerId, $id]);
                TaskWorkflowService::log($id, (int) $user['id'], 'reviewer_id', (string) ($task['reviewer_id'] ?? ''), (string) $leadReviewerId);
                TaskWorkflowService::log($id, (int) $user['id'], 'approval_route', '', 'Проверяющий выбран из наблюдателей');
            }
            if (!$this->setApprovalStage($id, $stage, $initialStage, (int) $user['id'])) {
                $this->db()->rollBack();
                $this->approvalStageChanged($id);
            }
            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }
        if ($initialStage === 'review_lead') {
            TaskWorkflowService::notify((int) $leadReviewerId, $id, 'approval_review_lead', 'Задача #' . $id . ' подана первому согласующему.');
            NotificationOutboxService::queueTaskApprovalRequested($id, (int) $leadReviewerId, (int) $user['id'], 'первый согласующий');
            flash('success', 'Задача подана первому согласующему.');
            redirect('/tasks/' . $id);
        }

        $gipId = $this->approvalGipApproverId($task);
        if ($gipId) {
            TaskWorkflowService::notify($gipId, $id, 'approval_review_gip', 'Задача #' . $id . ' ждёт согласования ГИПа.');
            NotificationOutboxService::queueTaskApprovalRequested($id, $gipId, (int) $user['id'], 'ГИП');
        }

        flash('success', $this->isAssigneeSelfApprovalStage($task) ? 'Задача подана на самосогласование.' : 'Задача подана на согласование ГИПу.');
        redirect('/tasks/' . $id);
    }

    public function leadApproval(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canLeadApprove($user, $task)) {
            $this->forbidden();
        }
        if ((string) ($task['approval_stage'] ?? 'draft') !== 'review_lead') {
            flash('error', 'Задача не ожидает решения промежуточного согласующего.');
            redirect('/tasks/' . $id);
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        // ТИМ-заявки (bim_family_request) не требуют согласования ГИПа: одобрение
        // последнего промежуточного согласующего финализирует их сразу, минуя стадию review_gip.
        $isBimRequest = (string) ($task['task_type'] ?? 'work') === 'bim_family_request';
        if ($decision === 'approved') {
            $nextReviewerId = $this->nextApprovalCentralReviewerId($task, (int) $user['id']);
            $finalStage = $isBimRequest ? 'approved' : 'review_gip';
            if ($nextReviewerId === null && !$isBimRequest && $this->approvalGipApproverId($task) === null) {
                flash('error', 'Для следующего этапа назначьте ГИПа проекта или согласующего с правом самосогласования.');
                redirect('/tasks/' . $id);
            }
            $this->db()->beginTransaction();
            try {
                if ($nextReviewerId !== null) {
                    $moveReviewer = $this->db()->prepare('UPDATE tasks SET reviewer_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND approval_stage = ? AND reviewer_id = ?');
                    $moveReviewer->execute([$nextReviewerId, $id, 'review_lead', (int) $user['id']]);
                    if ($moveReviewer->rowCount() === 0) {
                        $this->db()->rollBack();
                        $this->approvalStageChanged($id);
                    }
                    $this->recordApproval($id, 'review_lead', (int) $user['id'], 'approved', $comment);
                    TaskWorkflowService::log($id, (int) $user['id'], 'reviewer_id', (string) ($task['reviewer_id'] ?? ''), (string) $nextReviewerId);
                } else {
                    $setFinalStage = $this->db()->prepare('UPDATE tasks SET approval_stage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND approval_stage = ? AND reviewer_id = ?');
                    $setFinalStage->execute([$finalStage, $id, 'review_lead', (int) $user['id']]);
                    if ($setFinalStage->rowCount() === 0) {
                        $this->db()->rollBack();
                        $this->approvalStageChanged($id);
                    }
                    $this->recordApproval($id, 'review_lead', (int) $user['id'], 'approved', $comment);
                    TaskWorkflowService::log($id, (int) $user['id'], 'approval_stage', 'review_lead', $finalStage);
                }
                $this->db()->commit();
            } catch (\Throwable $e) {
                if ($this->db()->inTransaction()) {
                    $this->db()->rollBack();
                }
                throw $e;
            }
            if ($nextReviewerId !== null) {
                TaskWorkflowService::notify($nextReviewerId, $id, 'approval_review_lead', 'Задача #' . $id . ' ждёт следующего согласования.');
                NotificationOutboxService::queueTaskApprovalRequested($id, $nextReviewerId, (int) $user['id'], 'следующий согласующий');
                flash('success', 'Согласовано. Задача отправлена следующему согласующему.');
                redirect('/tasks/' . $id);
            }
            if ($isBimRequest) {
                flash('success', 'Заявка ТИМ согласована.');
                redirect('/tasks/' . $id);
            }
            $gipId = $this->approvalGipApproverId($task);
            if ($gipId) {
                TaskWorkflowService::notify($gipId, $id, 'approval_review_gip', 'Задача #' . $id . ' согласована промежуточными согласующими и ждёт ГИПа.');
                NotificationOutboxService::queueTaskApprovalRequested($id, $gipId, (int) $user['id'], 'ГИП');
            }
            flash('success', 'Согласовано. Задача отправлена ГИПу.');
            redirect('/tasks/' . $id);
        }

        if ($decision !== 'rejected' || $comment === '') {
            flash('error', 'Для возврата с замечаниями обязателен комментарий.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            $this->recordApproval($id, 'review_lead', (int) $user['id'], 'rejected', $comment);
            if (!$this->setApprovalStage($id, 'review_lead', 'draft', (int) $user['id'])) {
                $this->db()->rollBack();
                $this->approvalStageChanged($id);
            }
            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }
        if ($task['assignee_id']) {
            TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'approval_rejected', 'Задача #' . $id . ' возвращена с замечаниями согласующего.');
        }

        flash('success', 'Задача возвращена исполнителю.');
        redirect('/tasks/' . $id);
    }

    public function gipApproval(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canGipApprove($user, $task)) {
            $this->forbidden();
        }
        if ((string) ($task['approval_stage'] ?? 'draft') !== 'review_gip') {
            flash('error', 'Задача не ожидает решения ГИПа.');
            redirect('/tasks/' . $id);
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if ($decision === 'approved') {
            $selfApproval = $this->isOwnTask($user, $task) && $this->canSelfApproveOwnIssuance($user, $task);
            $approvalComment = $selfApproval
                ? trim('Самосогласование · ' . RoleService::label($user['role'] ?? '') . ($comment !== '' ? "\n" . $comment : ''))
                : $comment;
            $this->db()->beginTransaction();
            try {
                $this->recordApproval($id, 'review_gip', (int) $user['id'], 'approved', $approvalComment);
                if ($selfApproval) {
                    TaskWorkflowService::log($id, (int) $user['id'], 'approval_self', '', RoleService::label($user['role'] ?? ''));
                }
                if (!$this->setApprovalStage($id, 'review_gip', 'approved', (int) $user['id'])) {
                    $this->db()->rollBack();
                    $this->approvalStageChanged($id);
                }
                $this->db()->commit();
            } catch (\Throwable $e) {
                if ($this->db()->inTransaction()) {
                    $this->db()->rollBack();
                }
                throw $e;
            }
            if ($task['assignee_id']) {
                TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'approval_approved', 'Задача #' . $id . ' согласована ГИПом.');
            }
            flash('success', 'Задача согласована ГИПом.');
            redirect('/tasks/' . $id);
        }

        $returnTo = (string) ($_POST['return_to'] ?? 'review_lead');
        if ($decision !== 'rejected' || $comment === '' || !in_array($returnTo, ['review_lead', 'draft'], true)) {
            flash('error', 'Для возврата выберите адресата и укажите комментарий.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            $this->recordApproval($id, 'review_gip', (int) $user['id'], 'rejected', $comment);
            if (!$this->setApprovalStage($id, 'review_gip', $returnTo, (int) $user['id'])) {
                $this->db()->rollBack();
                $this->approvalStageChanged($id);
            }
            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }
        if ($returnTo === 'review_lead' && $task['reviewer_id']) {
            TaskWorkflowService::notify((int) $task['reviewer_id'], $id, 'approval_rejected_gip', 'ГИП вернул задачу #' . $id . ' промежуточному согласующему.');
        } elseif ($task['assignee_id']) {
            TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'approval_rejected_gip', 'ГИП вернул задачу #' . $id . ' исполнителю.');
        }

        flash('success', $returnTo === 'review_lead' ? 'Задача возвращена промежуточному согласующему.' : 'Задача возвращена исполнителю.');
        redirect('/tasks/' . $id);
    }

    public function submitLaborEstimate(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $laborEstimate = $task ? $this->laborEstimateForTask($id) : null;
        if (!$task || !$laborEstimate || !$this->canSubmitLaborEstimate($user, $task, $laborEstimate)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $amount = $this->laborAmount($_POST['executor_hours'] ?? '', $_POST['executor_days'] ?? '');
        $hours = $amount['hours'];
        $comment = trim((string) ($_POST['executor_comment'] ?? ''));
        if ($hours === null || $hours <= 0) {
            flash('error', 'Укажите оценку трудозатрат в часах или днях.');
            redirect('/tasks/' . $id);
        }
        $allocations = $this->laborAllocationsFromPost();
        if ($allocations !== []) {
            $hours = round(array_sum(array_column($allocations, 'hours')), 2);
            $amount = ['hours' => $hours, 'days' => $this->hoursToDays($hours)];
        }

        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET executor_hours = ?, executor_days = ?, executor_comment = ?, executor_submitted_at = CURRENT_TIMESTAMP,
                status = "submitted", updated_at = CURRENT_TIMESTAMP
            WHERE task_id = ?
        ')->execute([$hours, $amount['days'], $comment, $id]);
        $this->syncLaborAllocations((int) $laborEstimate['id'], $allocations);

        $newStatus = 'review';
        $newProgress = max(50, (int) ($task['progress'] ?? 0));
        $this->db()->prepare('
            UPDATE tasks
            SET status = ?, progress = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$newStatus, $newProgress, $id]);
        if ((string) $task['status'] !== $newStatus) {
            TaskWorkflowService::log($id, (int) $user['id'], 'status', (string) $task['status'], $newStatus);
        }
        if ((int) ($task['progress'] ?? 0) !== $newProgress) {
            TaskWorkflowService::log($id, (int) $user['id'], 'progress', (string) ($task['progress'] ?? 0), (string) $newProgress);
        }
        TaskWorkflowService::log($id, (int) $user['id'], 'labor_estimate_executor', '', $this->formatLaborHours($hours) . ' ч');
        ActivityLogService::recordTask($id, (int) $user['id'], 'project.labor_estimate_submitted', 'Оценка подана ГИПу', $this->formatLaborHours($hours) . ' ч' . ($comment !== '' ? ' · ' . $comment : ''));

        $this->notifyDistinctUsers([
            (int) ($laborEstimate['requested_by'] ?? 0),
            (int) ($task['author_id'] ?? 0),
        ], $id, 'labor_estimate_submitted', 'Исполнитель подал оценку трудозатрат по задаче #' . $id . '.');

        flash('success', 'Оценка трудозатрат подана ГИПу.');
        redirect('/tasks/' . $id);
    }

    public function gipLaborEstimate(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $laborEstimate = $task ? $this->laborEstimateForTask($id) : null;
        if (!$task || !$laborEstimate || !$this->canGipApproveLaborEstimate($user, $task, $laborEstimate)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $amount = $this->laborAmount($_POST['gip_hours'] ?? '', $_POST['gip_days'] ?? '');
        $hours = $amount['hours'];
        $comment = trim((string) ($_POST['gip_comment'] ?? ''));
        if ($hours === null || $hours <= 0) {
            flash('error', 'Укажите корректировку ГИПа в часах или днях.');
            redirect('/tasks/' . $id);
        }

        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET gip_hours = ?, gip_days = ?, gip_comment = ?, gip_approved_by = ?, gip_approved_at = CURRENT_TIMESTAMP,
                returned_by = NULL, returned_at = NULL, return_comment = NULL,
                status = "gip_approved", updated_at = CURRENT_TIMESTAMP
            WHERE task_id = ?
        ')->execute([$hours, $amount['days'], $comment, (int) $user['id'], $id]);

        $newProgress = max(75, (int) ($task['progress'] ?? 0));
        $this->db()->prepare('UPDATE tasks SET status = "review", progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$newProgress, $id]);
        if ((string) $task['status'] !== 'review') {
            TaskWorkflowService::log($id, (int) $user['id'], 'status', (string) $task['status'], 'review');
        }
        if ((int) ($task['progress'] ?? 0) !== $newProgress) {
            TaskWorkflowService::log($id, (int) $user['id'], 'progress', (string) ($task['progress'] ?? 0), (string) $newProgress);
        }
        TaskWorkflowService::log($id, (int) $user['id'], 'labor_estimate_gip', '', $this->formatLaborHours($hours) . ' ч');
        ActivityLogService::recordTask($id, (int) $user['id'], 'project.labor_estimate_gip_approved', 'ГИП проверил оценку', $this->formatLaborHours($hours) . ' ч' . ($comment !== '' ? ' · ' . $comment : ''));

        $this->notifyUsersByRoles([RoleService::DIRECTOR], $id, 'labor_estimate_gip_approved', 'Оценка трудозатрат по задаче #' . $id . ' ждёт утверждения директора.');

        flash('success', 'Оценка проверена ГИПом и отправлена директору.');
        redirect('/tasks/' . $id);
    }

    public function returnLaborEstimateToResponsible(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $laborEstimate = $task ? $this->laborEstimateForTask($id) : null;
        if (!$task || !$laborEstimate || !$this->canGipReturnLaborEstimate($user, $task, $laborEstimate)) {
            $this->forbidden();
        }

        $comment = trim((string) ($_POST['return_comment'] ?? ''));
        if ($comment === '') {
            flash('error', 'Для возврата укажите комментарий.');
            redirect('/tasks/' . $id);
        }

        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET status = "returned_to_responsible", returned_by = ?, returned_at = CURRENT_TIMESTAMP,
                return_comment = ?, updated_at = CURRENT_TIMESTAMP
            WHERE task_id = ?
        ')->execute([(int) $user['id'], $comment, $id]);
        $this->db()->prepare('UPDATE tasks SET status = "correction", updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        TaskWorkflowService::log($id, (int) $user['id'], 'labor_estimate_return', (string) ($laborEstimate['status'] ?? ''), 'returned_to_responsible');
        ActivityLogService::recordTask($id, (int) $user['id'], 'project.labor_estimate_returned', 'ГИП вернул оценку ответственному', $comment);
        $this->notifyDistinctUsers([(int) ($task['assignee_id'] ?? 0)], $id, 'labor_estimate_returned', 'ГИП вернул оценку трудозатрат по задаче #' . $id . '.');

        flash('success', 'Оценка возвращена ответственному.');
        redirect('/tasks/' . $id);
    }

    public function directorLaborEstimate(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $laborEstimate = $task ? $this->laborEstimateForTask($id) : null;
        if (!$task || !$laborEstimate || !$this->canDirectorApproveLaborEstimate($user, $task, $laborEstimate)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $amount = $this->laborAmount($_POST['director_hours'] ?? '', $_POST['director_days'] ?? '');
        $hours = $amount['hours'];
        $comment = trim((string) ($_POST['director_comment'] ?? ''));
        if ($hours === null || $hours <= 0) {
            flash('error', 'Укажите утверждённые часы или дни директора.');
            redirect('/tasks/' . $id);
        }

        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET director_hours = ?, director_days = ?, director_comment = ?, director_approved_by = ?, director_approved_at = CURRENT_TIMESTAMP,
                returned_by = NULL, returned_at = NULL, return_comment = NULL,
                status = "director_approved", updated_at = CURRENT_TIMESTAMP
            WHERE task_id = ?
        ')->execute([$hours, $amount['days'], $comment, (int) $user['id'], $id]);

        $this->db()->prepare('
            UPDATE tasks
            SET status = "done", progress = 100, closed_at = CURRENT_TIMESTAMP,
                closed_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $user['id'], $id]);
        if ((string) $task['status'] !== 'done') {
            TaskWorkflowService::log($id, (int) $user['id'], 'status', (string) $task['status'], 'done');
        }
        if ((int) ($task['progress'] ?? 0) !== 100) {
            TaskWorkflowService::log($id, (int) $user['id'], 'progress', (string) ($task['progress'] ?? 0), '100');
        }
        TaskWorkflowService::log($id, (int) $user['id'], 'labor_estimate_director', '', $this->formatLaborHours($hours) . ' ч');
        ActivityLogService::recordTask($id, (int) $user['id'], 'project.labor_estimate_director_approved', 'Директор утвердил оценку', $this->formatLaborHours($hours) . ' ч' . ($comment !== '' ? ' · ' . $comment : ''));
        TaskWorkflowService::recomputeParentProgress($task['parent_id'] ? (int) $task['parent_id'] : null);

        $this->notifyDistinctUsers([
            (int) ($task['assignee_id'] ?? 0),
            (int) ($laborEstimate['requested_by'] ?? 0),
            (int) ($task['author_id'] ?? 0),
        ], $id, 'labor_estimate_director_approved', 'Директор утвердил оценку трудозатрат по задаче #' . $id . '.');

        flash('success', 'Оценка трудозатрат утверждена директором.');
        redirect('/tasks/' . $id);
    }

    public function returnLaborEstimateToGip(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        $laborEstimate = $task ? $this->laborEstimateForTask($id) : null;
        if (!$task || !$laborEstimate || !$this->canDirectorReturnLaborEstimate($user, $task, $laborEstimate)) {
            $this->forbidden();
        }

        $comment = trim((string) ($_POST['return_comment'] ?? ''));
        if ($comment === '') {
            flash('error', 'Для возврата укажите комментарий.');
            redirect('/tasks/' . $id);
        }

        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET status = "returned_to_gip", returned_by = ?, returned_at = CURRENT_TIMESTAMP,
                return_comment = ?, updated_at = CURRENT_TIMESTAMP
            WHERE task_id = ?
        ')->execute([(int) $user['id'], $comment, $id]);
        $this->db()->prepare('UPDATE tasks SET status = "review", updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        TaskWorkflowService::log($id, (int) $user['id'], 'labor_estimate_return', (string) ($laborEstimate['status'] ?? ''), 'returned_to_gip');
        ActivityLogService::recordTask($id, (int) $user['id'], 'project.labor_estimate_returned', 'Директор вернул оценку ГИПу', $comment);
        $this->notifyDistinctUsers([
            (int) ($laborEstimate['gip_approved_by'] ?? 0),
            (int) ($laborEstimate['requested_by'] ?? 0),
            (int) ($task['author_id'] ?? 0),
        ], $id, 'labor_estimate_returned_director', 'Директор вернул оценку трудозатрат по задаче #' . $id . ' ГИПу.');

        flash('success', 'Оценка возвращена ГИПу.');
        redirect('/tasks/' . $id);
    }

    public function storeIssuance(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canManageIssuances($user, $task)) {
            $this->forbidden();
        }
        if ((string) ($task['approval_stage'] ?? 'draft') !== 'approved') {
            flash('error', 'Выдачу можно зафиксировать только после согласования ГИПом.');
            redirect('/tasks/' . $id);
        }

        $issuedAt = (string) ($_POST['issued_at'] ?? date('Y-m-d'));
        $status = (string) ($_POST['status'] ?? 'issued');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $revisionReason = trim((string) ($_POST['revision_reason'] ?? ''));
        $revisionSummary = trim((string) ($_POST['revision_summary'] ?? ''));

        $issuedAtDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $issuedAt);
        if (!$issuedAtDate || $issuedAtDate->format('Y-m-d') !== $issuedAt) {
            flash('error', 'Укажите дату выдачи.');
            redirect('/tasks/' . $id);
        }

        if (!in_array($status, ['issued', 'remarks', 'accepted'], true)) {
            flash('error', 'Некорректный статус выдачи.');
            redirect('/tasks/' . $id);
        }

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            if (!$this->setApprovalStage($id, 'approved', 'issued', (int) $user['id'])) {
                $pdo->rollBack();
                $this->approvalStageChanged($id);
            }

            $nextNumberStmt = $pdo->prepare('SELECT COALESCE(MAX(issue_number), 0) + 1 FROM task_issuances WHERE task_id = ?');
            $nextNumberStmt->execute([$id]);
            $issueNumber = (int) $nextNumberStmt->fetchColumn();
            $revisionReason = DocumentRevisionService::validateReason($issueNumber, $revisionReason);

            $stmt = $pdo->prepare('
                INSERT INTO task_issuances (task_id, issue_number, issued_at, issued_by, comment, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$id, $issueNumber, $issuedAt, $user['id'], $comment, $status]);
            $issuanceId = (int) $pdo->lastInsertId();

            DocumentRevisionService::createForIssuance($pdo, $task, $issuanceId, $issueNumber, (int) $user['id'], $revisionReason, $revisionSummary);

            $this->recordApproval($id, 'issued', (int) $user['id'], 'issued', $comment);
            TaskWorkflowService::log($id, (int) $user['id'], 'issuance', '', 'Выдача №' . $issueNumber . ' · изм. ' . DocumentRevisionService::revisionNumberForIssue($issueNumber) . ' · ' . task_issuance_status_label($status));
            $this->syncScheduleFromIssuance($task, $issuedAt, $status);
            $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
            redirect('/tasks/' . $id);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                flash('error', 'Выдача уже зафиксирована, обновите страницу.');
                redirect('/tasks/' . $id);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        flash('success', 'Выдача зафиксирована.');
        redirect('/tasks/' . $id);
    }

    public function storeIssue(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $issueText = trim((string) ($_POST['issue'] ?? ''));
        if ($issueText === '') {
            flash('error', 'Опишите вопрос перед созданием.');
            redirect('/tasks/' . $id);
        }

        $assigneeId = ($_POST['assignee_id'] ?? '') !== ''
            ? (int) $_POST['assignee_id']
            : ((int) ($task['reviewer_id'] ?? 0) ?: ((int) ($task['author_id'] ?? 0) ?: null));
        $nextNumStmt = $this->db()->prepare('SELECT COALESCE(MAX(num), 0) + 1 FROM project_issues WHERE project_id = ?');
        $nextNumStmt->execute([(int) $task['project_id']]);
        $num = max(1, (int) $nextNumStmt->fetchColumn());

        $stmt = $this->db()->prepare('
            INSERT INTO project_issues (project_id, blocking_task_id, num, section_code, issue, assignee_id, stage, date_raised, answer, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "", ?, "open")
        ');
        $stmt->execute([
            (int) $task['project_id'],
            $id,
            $num,
            (string) ($task['section'] ?: $task['discipline']),
            $issueText,
            $assigneeId,
            (string) ($task['project_code'] ?? ''),
            date('Y-m-d'),
            'Открыт из задачи #' . $id,
        ]);
        $issueId = (int) $this->db()->lastInsertId();

        TaskWorkflowService::log($id, (int) $user['id'], 'project_issue', '', 'Открыт вопрос #' . $issueId);
        if ($assigneeId) {
            TaskWorkflowService::notify($assigneeId, $id, 'project_issue_opened', 'Открыт вопрос по задаче #' . $id . ': ' . $issueText);
        }

        flash('success', 'Вопрос открыт и привязан к задаче.');
        redirect('/tasks/' . $id);
    }

    public function closeIssue(int $id, int $issueId): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->notFound();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $stmt = $this->db()->prepare('SELECT * FROM project_issues WHERE id = ? AND blocking_task_id = ? LIMIT 1');
        $stmt->execute([$issueId, $id]);
        $issue = $stmt->fetch();
        if (!$issue) {
            $this->notFound();
        }

        $canClose = PermissionService::canEditTask($user, $task)
            || (int) ($issue['assignee_id'] ?? 0) === (int) $user['id'];
        if (!$canClose) {
            $this->forbidden();
        }

        if ((string) $issue['status'] !== 'done') {
            $this->db()->prepare('UPDATE project_issues SET status = "done" WHERE id = ?')->execute([$issueId]);
            TaskWorkflowService::log($id, (int) $user['id'], 'project_issue', (string) $issue['status'], 'done');
            if ($task['assignee_id']) {
                TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'project_issue_closed', 'Вопрос #' . ($issue['num'] ?: $issueId) . ' по задаче #' . $id . ' закрыт.');
            }
        }

        flash('success', 'Вопрос закрыт.');
        redirect('/tasks/' . $id);
    }

    public function acceptClose(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task) {
            $this->forbidden();
        }
        if (!in_array($task['status'], ['review', 'pending_close'], true)) {
            flash('error', 'Закрытие принимается только для задач на проверке.');
            redirect('/tasks/' . $id);
        }
        if ($this->openReviewTask($id) !== null) {
            flash('error', 'Задача ожидает решения проверяющего в блоке проверки результата.');
            redirect('/tasks/' . $id);
        }

        $stage = '';
        if ($this->canAcceptCloseByAuthor($user, $task)) {
            $stage = 'close_author';
        } elseif ($this->canAcceptCloseByGip($user, $task)) {
            $stage = 'close_gip';
        } else {
            $this->forbidden();
        }

        $decision = (string) ($_POST['decision'] ?? 'approved');
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Некорректное решение по закрытию.');
            redirect('/tasks/' . $id);
        }

        if ($decision === 'rejected') {
            if ($comment === '') {
                flash('error', 'Для возврата работы обязателен комментарий.');
                redirect('/tasks/' . $id);
            }

            $this->rejectClose($task, $stage, (int) $user['id'], $comment);
            flash('success', 'Работа возвращена исполнителю.');
            redirect('/tasks/' . $id);
        }

        if ($this->requiresGipCloseAcceptance($task) && !$this->lastIssuanceAccepted($id)) {
            flash('error', 'Закрытие тома доступно только когда последняя выдача имеет статус «Принята».');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            if ($stage === 'close_author') {
                if (!$this->recordCloseApprovalIfPending($task, 'close_author', (int) $user['id'], $comment)) {
                    $this->db()->rollBack();
                    flash('error', 'Этот этап приёмки уже принят.');
                    redirect('/tasks/' . $id);
                }

                if ($this->requiresGipCloseAcceptance($task)) {
                    if ($this->canGipReviewUser($user, $task)) {
                        $this->recordCloseApprovalIfPending($task, 'close_gip', (int) $user['id'], $comment);
                        $this->finalizeClose($task, (int) $user['id']);
                        $this->db()->commit();
                        flash('success', 'Закрытие тома принято постановщиком и ГИПом.');
                        redirect('/tasks/' . $id);
                    }

                    $this->db()->commit();
                    $gipId = $this->projectGipId($task);
                    if ($gipId) {
                        TaskWorkflowService::notify($gipId, $id, 'close_gip_requested', 'Закрытие тома по задаче #' . $id . ' принято постановщиком и ждёт ГИПа.');
                    }
                    flash('success', 'Постановщик принял работу. Закрытие тома ожидает ГИПа.');
                    redirect('/tasks/' . $id);
                }

                $this->finalizeClose($task, (int) $user['id']);
                $this->db()->commit();
                flash('success', 'Закрытие принято постановщиком.');
                redirect('/tasks/' . $id);
            }

            if (!$this->recordCloseApprovalIfPending($task, 'close_gip', (int) $user['id'], $comment)) {
                $this->db()->rollBack();
                flash('error', 'Этот этап приёмки уже принят.');
                redirect('/tasks/' . $id);
            }
            $this->finalizeClose($task, (int) $user['id']);
            $this->db()->commit();
            flash('success', 'Закрытие тома принято ГИПом.');
            redirect('/tasks/' . $id);
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }
    }

    public function adminClose(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canForceCloseTask($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }
        if ((string) ($task['status'] ?? '') === 'done') {
            flash('success', 'Задача уже закрыта.');
            redirect('/tasks/' . $id);
        }

        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 1000);

        $this->db()->beginTransaction();
        try {
            $openReviewTasks = $this->openReviewTasksForParent($id);
            foreach ($openReviewTasks as $reviewTask) {
                $this->db()->prepare('
                    UPDATE tasks
                    SET status = "done",
                        progress = 100,
                        closed_at = CURRENT_TIMESTAMP,
                        closed_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status != "done"
                ')->execute([(int) $user['id'], (int) $reviewTask['id']]);
                TaskWorkflowService::log((int) $reviewTask['id'], (int) $user['id'], 'status', (string) $reviewTask['status'], 'done');
                TaskWorkflowService::log((int) $reviewTask['id'], (int) $user['id'], 'progress', (string) ($reviewTask['progress'] ?? 0), '100');
                TaskWorkflowService::log((int) $reviewTask['id'], (int) $user['id'], 'admin_close', '', 'Закрыто вместе с исходной задачей #' . $id);
            }

            $this->finalizeClose($task, (int) $user['id']);
            TaskWorkflowService::log($id, (int) $user['id'], 'admin_close', '', $comment !== '' ? $comment : RoleService::label($user['role'] ?? ''));
            ActivityLogService::recordTask($id, (int) $user['id'], 'task.admin_closed', 'Задача закрыта управленческим действием', $comment !== '' ? $comment : null);
            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }

        flash('success', 'Задача закрыта управленческим действием.');
        redirect('/tasks/' . $id);
    }

    public function delete(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canAdministerTask($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }

        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 1000);
        $taskTitle = (string) ($task['title'] ?? '');
        $projectId = (int) ($task['project_id'] ?? 0);
        $projectCode = (string) ($task['project_code'] ?? '');
        $taskIds = $this->taskDescendantIds($id);
        $taskIds[] = $id;
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));

        $this->db()->beginTransaction();
        try {
            $this->detachTaskReferences($taskIds);
            $this->deleteTaskOwnedRows($taskIds);
            $this->deleteTasksByIds($taskIds);
            ActivityLogService::recordLocia(
                (int) $user['id'],
                'task.deleted',
                'Задача удалена директором/администратором',
                '#' . $id . ' · ' . $projectCode . ' · ' . $taskTitle . ($comment !== '' ? ' · ' . $comment : ''),
                [
                    'task_id' => $id,
                    'task_ids' => $taskIds,
                    'project_id' => $projectId,
                    'project_code' => $projectCode,
                ]
            );
            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }

        flash('success', 'Задача удалена.');
        redirect('/tasks');
    }

    public function shiftDeadline(int $id): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canReview($user, $task)) {
            $this->forbidden();
        }
        if ((string) ($task['status'] ?? '') === 'done' || trim((string) ($task['closed_at'] ?? '')) !== '') {
            flash('error', 'Срок закрытой задачи менять нельзя. Создайте новую задачу или верните текущую в работу отдельным управляемым действием.');
            redirect('/tasks/' . $id);
        }

        $dateNew = (string) ($_POST['date_new'] ?? '');
        $reasonCode = (string) ($_POST['reason_code'] ?? '');
        $reasonText = trim((string) ($_POST['reason_text'] ?? ''));
        if ($dateNew <= date('Y-m-d') || mb_strlen($reasonText) < 20 || !$this->reasonExists($reasonCode)) {
            flash('error', 'Нужна новая дата больше сегодняшней, причина из справочника и комментарий минимум 20 символов.');
            redirect('/tasks/' . $id);
        }

        $this->db()->prepare('
            INSERT INTO task_deadline_shifts (task_id, shifted_by, date_old, date_new, reason_code, reason_text, status, reviewed_by, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, "approved", ?, CURRENT_TIMESTAMP)
        ')->execute([$id, $user['id'], $task['date_end'], $dateNew, $reasonCode, $reasonText, $user['id']]);

        $this->db()->prepare('UPDATE tasks SET status = "in_progress", date_end = ? WHERE id = ?')->execute([$dateNew, $id]);
        $this->db()->prepare('UPDATE task_smart SET when_due = ? WHERE task_id = ?')->execute([$dateNew, $id]);
        TaskWorkflowService::log($id, (int) $user['id'], 'date_end', $task['date_end'], $dateNew);
        TaskWorkflowService::log($id, (int) $user['id'], 'status', $task['status'], 'in_progress');

        if ($task['assignee_id']) {
            TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'deadline_shifted', 'Срок задачи #' . $id . ' сдвинут.');
        }

        flash('success', 'Срок сдвинут, задача возвращена в работу.');
        redirect('/tasks/' . $id);
    }

    public function approveDeadlineShift(int $id, int $shiftId): void
    {
        $this->decideDeadlineShift($id, $shiftId, 'approved');
    }

    public function rejectDeadlineShift(int $id, int $shiftId): void
    {
        $this->decideDeadlineShift($id, $shiftId, 'rejected');
    }

    private function decideDeadlineShift(int $id, int $shiftId, string $decision): void
    {
        $user = require_auth();
        $task = $this->task($id, $user);
        if (!$task || !$this->canReview($user, $task)) {
            $this->forbidden();
        }
        if ($this->isArchivedTask($task)) {
            flash('error', 'Задача архивного проекта доступна только для просмотра.');
            redirect('/tasks/' . $id);
        }
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Некорректное решение по переносу срока.');
            redirect('/tasks/' . $id);
        }

        $comment = trim((string) ($_POST['comment'] ?? ''));
        if ($decision === 'rejected' && $comment === '') {
            flash('error', 'Для возврата переноса срока обязателен комментарий.');
            redirect('/tasks/' . $id);
        }

        $stmt = $this->db()->prepare('SELECT * FROM task_deadline_shifts WHERE id = ? AND task_id = ? LIMIT 1');
        $stmt->execute([$shiftId, $id]);
        $shift = $stmt->fetch();
        if (!$shift) {
            $this->notFound();
        }
        if ((string) ($shift['status'] ?? 'approved') !== 'pending') {
            flash('error', 'По этой заявке на перенос срока уже принято решение.');
            redirect('/tasks/' . $id);
        }
        $blockReason = TaskWorkflowIntegrityService::deadlineShiftBlockReason($task, $shift);
        if ($blockReason === 'task_terminal') {
            flash('error', 'Задача уже закрыта; заявка на перенос срока больше не действует.');
            redirect('/tasks/' . $id);
        }
        if ($blockReason === 'stale_deadline') {
            flash('error', 'Срок задачи уже изменён после создания заявки. Отклоните старую заявку и создайте новую от текущей даты.');
            redirect('/tasks/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            $guard = $this->db()->prepare('
                UPDATE task_deadline_shifts
                SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_comment = ?
                WHERE id = ? AND task_id = ? AND status = "pending"
            ');
            $guard->execute([$decision, (int) $user['id'], $comment, $shiftId, $id]);
            if ($guard->rowCount() === 0) {
                $this->db()->rollBack();
                flash('error', 'По этой заявке на перенос срока уже принято решение.');
                redirect('/tasks/' . $id);
            }

            if ($decision === 'approved') {
                $this->applyApprovedDeadlineShift($task, (int) $user['id'], (string) $shift['date_new']);
                TaskWorkflowService::log($id, (int) $user['id'], 'deadline_shift', 'На проверке', 'Подтверждён перенос на ' . (string) $shift['date_new']);
                ActivityLogService::recordTask($id, (int) $user['id'], 'task.deadline_shift_approved', 'Проверяющий подтвердил перенос срока', (string) $shift['date_new']);
                if ($task['assignee_id']) {
                    TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'deadline_shift_approved', 'Перенос срока задачи #' . $id . ' подтверждён.');
                }
            } else {
                TaskWorkflowService::log($id, (int) $user['id'], 'deadline_shift', 'На проверке', 'Возвращён: ' . $comment);
                $this->db()->prepare('INSERT INTO comments (task_id, user_id, body, mention_ids) VALUES (?, ?, ?, ?)')
                    ->execute([$id, (int) $user['id'], 'Перенос срока не подтверждён: ' . $comment, json_encode([], JSON_UNESCAPED_UNICODE)]);
                ActivityLogService::recordTask($id, (int) $user['id'], 'task.deadline_shift_rejected', 'Проверяющий вернул перенос срока', $comment);
                if ($task['assignee_id']) {
                    TaskWorkflowService::notify((int) $task['assignee_id'], $id, 'deadline_shift_rejected', 'Перенос срока задачи #' . $id . ' не подтверждён: ' . $comment);
                }
            }

            $this->db()->commit();
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            throw $e;
        }

        flash('success', $decision === 'approved' ? 'Перенос срока подтверждён.' : 'Перенос срока возвращён исполнителю.');
        redirect('/tasks/' . $id);
    }

    private function index(string $scope, string $viewMode, bool $isLocia = false): void
    {
        $user = require_auth();
        TaskWorkflowService::markOverdue();
        $viewMode = $viewMode === 'board' ? 'board' : 'table';
        [$tasks, $reviewTasks] = $this->taskList($user, $scope);
        $summary = $isLocia ? $this->myTaskSummary($user) : null;

        $this->render('tasks/index', [
            'title' => $isLocia ? app_task_hub_title() : ($scope === 'mine' ? 'Мои задачи' : 'Все задачи'),
            'subtitle' => $summary
                ? ($user['name'] . ' · ' . $summary['active'] . ' задач в работе · ' . $summary['overdue'] . ' просрочено')
                : null,
            'dailyPicture' => $isLocia ? $summary : null,
            'tasks' => $tasks,
            'reviewTasks' => $reviewTasks,
            'assignedIssues' => $scope === 'mine' ? $this->assignedIssues($user) : [],
            'viewMode' => $viewMode,
            'scope' => $scope,
            'basePath' => $isLocia ? app_task_hub_path() : null,
            'projects' => $this->projectsFor($user),
            'users' => $this->activeUsers(),
            'filters' => $_GET,
            'sections' => DictionaryService::globalSections(),
            'tagOptions' => TagService::visibleTagsForUser($user, $scope),
        ]);
    }

    private function myTaskSummary(array $user): array
    {
        $stmt = $this->db()->prepare('
            SELECT
                COALESCE(SUM(CASE WHEN t.status != "done" THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE
                    WHEN t.status = "overdue"
                      OR (t.date_end IS NOT NULL AND t.date_end < :today_overdue AND t.status != "done")
                    THEN 1 ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(CASE
                    WHEN t.status != "done" AND t.date_end = :today_due
                    THEN 1 ELSE 0 END), 0) AS due_today,
                COALESCE(SUM(CASE
                    WHEN t.status != "done" AND t.date_end BETWEEN :today_week_start AND :week_end
                    THEN 1 ELSE 0 END), 0) AS due_week,
                COALESCE(SUM(CASE
                    WHEN t.status IN ("review", "pending_close")
                       OR t.approval_stage IN ("review_lead", "review_gip")
                    THEN 1 ELSE 0 END), 0) AS review_count,
                COALESCE(SUM(CASE
                    WHEN t.status = "correction"
                    THEN 1 ELSE 0 END), 0) AS correction_count,
                COALESCE(SUM(CASE
                    WHEN t.status = "blocked"
                    THEN 1 ELSE 0 END), 0) AS blocked_count,
                COALESCE(SUM(CASE
                    WHEN t.task_type = "assignment"
                      AND t.assignee_id = :assignment_assignee_id
                      AND t.status != "done"
                    THEN 1 ELSE 0 END), 0) AS assignments_in,
                COALESCE(SUM(CASE
                    WHEN t.task_type = "assignment"
                      AND t.author_id = :assignment_author_id
                      AND (t.assignee_id IS NULL OR t.assignee_id != :assignment_not_assignee_id)
                      AND t.status != "done"
                    THEN 1 ELSE 0 END), 0) AS assignments_out
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE (
                t.assignee_id = :scope_assignee_id
                OR t.author_id = :scope_author_id
                OR EXISTS (
                    SELECT 1
                    FROM task_participants tp_summary
                    WHERE tp_summary.task_id = t.id
                      AND tp_summary.user_id = :scope_participant_id
                )
            )
              AND p.status = \'active\'
        ');
        $today = date('Y-m-d');
        $userId = (int) $user['id'];
        $stmt->execute([
            'today_overdue' => $today,
            'today_due' => $today,
            'today_week_start' => $today,
            'week_end' => date('Y-m-d', strtotime('+7 days')),
            'assignment_assignee_id' => $userId,
            'assignment_author_id' => $userId,
            'assignment_not_assignee_id' => $userId,
            'scope_assignee_id' => $userId,
            'scope_author_id' => $userId,
            'scope_participant_id' => $userId,
        ]);
        $summary = $stmt->fetch() ?: [];

        return [
            'active' => (int) ($summary['active_count'] ?? 0),
            'overdue' => (int) ($summary['overdue'] ?? 0),
            'today' => (int) ($summary['due_today'] ?? 0),
            'week' => (int) ($summary['due_week'] ?? 0),
            'review' => (new TaskActionQueueService($this->db()))->count($user),
            'correction' => (int) ($summary['correction_count'] ?? 0),
            'blocked' => (int) ($summary['blocked_count'] ?? 0),
            'assignments_in' => (int) ($summary['assignments_in'] ?? 0),
            'assignments_out' => (int) ($summary['assignments_out'] ?? 0),
        ];
    }

    private function taskList(array $user, string $scope): array
    {
        $params = ['current_user_id' => (int) $user['id']];
        $where = [];

        if ($scope === 'mine') {
            $where[] = '(
                t.assignee_id = :mine_id
                OR t.author_id = :mine_id
                OR EXISTS (
                    SELECT 1
                    FROM task_participants tp_mine
                    WHERE tp_mine.task_id = t.id
                      AND tp_mine.user_id = :mine_participant_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM employee_vacations mine_vacation
                    WHERE mine_vacation.user_id IN (t.assignee_id, t.author_id, t.reviewer_id)
                      AND mine_vacation.substitute_user_id = :mine_substitute_id
                      AND mine_vacation.cancelled_at IS NULL
                      AND CURRENT_DATE BETWEEN mine_vacation.date_from AND mine_vacation.date_to
                )
            )';
            $params['mine_id'] = (int) $user['id'];
            $params['mine_participant_id'] = (int) $user['id'];
            $params['mine_substitute_id'] = (int) $user['id'];
        } else {
            [$scopeSql, $scopeParams] = PermissionService::taskScopeWhere($user);
            $where[] = $scopeSql;
            $params += $scopeParams;
        }
        $where[] = "p.status = 'active'";
        $where[] = 'COALESCE(t.task_type, "") != "review"';

        foreach (['status', 'task_type', 'discipline', 'project_id', 'priority', 'urgency', 'assignee_id'] as $filter) {
            if (!empty($_GET[$filter])) {
                $where[] = "t.{$filter} = :{$filter}";
                $params[$filter] = $_GET[$filter];
            }
        }
        if (!empty($_GET['deadline'])) {
            if ($_GET['deadline'] === 'overdue') {
                $where[] = 't.status != "done" AND t.date_end < :today';
                $params['today'] = date('Y-m-d');
            } elseif ($_GET['deadline'] === 'today') {
                $where[] = 't.status != "done" AND t.date_end = :today';
                $params['today'] = date('Y-m-d');
            } elseif ($_GET['deadline'] === 'week') {
                $where[] = 't.status != "done" AND t.date_end BETWEEN :today AND :week_end';
                $params['today'] = date('Y-m-d');
                $params['week_end'] = date('Y-m-d', strtotime('+7 days'));
            }
        }
        $dateFrom = $this->dateFilterValue($_GET['date_from'] ?? '');
        $dateTo = $this->dateFilterValue($_GET['date_to'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        if ($dateFrom !== '') {
            $where[] = 't.date_end >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 't.date_end <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if (!empty($_GET['from_me'])) {
            $where[] = 't.author_id = :from_me_author_id AND (t.assignee_id IS NULL OR t.assignee_id != :from_me_assignee_id)';
            $params['from_me_author_id'] = (int) $user['id'];
            $params['from_me_assignee_id'] = (int) $user['id'];
        }
        if (!empty($_GET['needs_review'])) {
            $where[] = '(
                t.status IN ("review", "pending_close")
                OR t.approval_stage IN ("review_lead", "review_gip")
            ) AND t.status <> "done" AND t.closed_at IS NULL
              AND (t.status NOT IN ("review", "pending_close") OR t.close_requested_at IS NOT NULL)';
        }
        if (!empty($_GET['tag'])) {
            $tagSlug = TagService::slug((string) $_GET['tag']);
            if ($tagSlug !== '') {
                $where[] = 'EXISTS (
                    SELECT 1
                    FROM task_tags tt_filter
                    INNER JOIN tags tg_filter ON tg_filter.id = tt_filter.tag_id
                    WHERE tt_filter.task_id = t.id AND tg_filter.slug = :tag_slug
                )';
                $params['tag_slug'] = $tagSlug;
            }
        }

        $sql = $this->taskSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.date_end IS NULL, t.date_end ASC, t.priority DESC, t.id DESC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $tasks = TagService::attachToTasks($this->attachBlockingDataCounts($stmt->fetchAll()));
        if (!empty($_GET['blocked_by_id'])) {
            $tasks = array_values(array_filter($tasks, static fn (array $task): bool => (int) ($task['blocking_data_waiting_count'] ?? 0) > 0));
        }

        $reviewIds = (new TaskActionQueueService($this->db()))->taskIds($user);
        $reviewParams = ['current_user_id' => (int) $user['id']];
        $reviewPlaceholders = [];
        foreach ($reviewIds as $index => $reviewId) {
            $key = 'review_task_id_' . $index;
            $reviewPlaceholders[] = ':' . $key;
            $reviewParams[$key] = $reviewId;
        }
        $reviewStmt = $this->db()->prepare($this->taskSelect() . ' WHERE t.id IN (' . ($reviewPlaceholders ? implode(',', $reviewPlaceholders) : 'NULL') . ') ORDER BY t.close_requested_at, t.date_end, t.id');
        $reviewStmt->execute($reviewParams);

        return [$tasks, TagService::attachToTasks($this->attachBlockingDataCounts($reviewStmt->fetchAll()))];
    }

    private function bimFamilyRows(array $user): array
    {
        [$scopeSql, $scopeParams] = PermissionService::taskScopeWhere($user);
        $where = [
            't.task_type = "bim_family_request"',
            "p.status = 'active'",
            $scopeSql,
        ];
        $params = $scopeParams;

        foreach (['project_id', 'status'] as $filter) {
            if (!empty($_GET[$filter])) {
                $where[] = "t.{$filter} = :{$filter}";
                $params[$filter] = $_GET[$filter];
            }
        }
        if (!empty($_GET['assignee_id'])) {
            $where[] = '(
                t.assignee_id = :assignee_id
                OR EXISTS (
                    SELECT 1
                    FROM task_participants tp_bim_assignee
                    WHERE tp_bim_assignee.task_id = t.id
                      AND tp_bim_assignee.role = "assignee"
                      AND tp_bim_assignee.user_id = :assignee_participant_id
                )
            )';
            $params['assignee_id'] = (int) $_GET['assignee_id'];
            $params['assignee_participant_id'] = (int) $_GET['assignee_id'];
        }

        $dateFrom = $this->dateFilterValue($_GET['date_from'] ?? '');
        $dateTo = $this->dateFilterValue($_GET['date_to'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        if ($dateFrom !== '') {
            $where[] = 't.date_end >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 't.date_end <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $stmt = $this->db()->prepare('
            SELECT t.id,
                   t.title,
                   t.status,
                   t.date_end,
                   t.discipline,
                   t.section,
                   p.id AS project_id,
                   p.code AS project_code,
                   p.title AS project_title,
                   author.name AS author_name,
                   assignee.name AS assignee_name,
                   ts.what,
                   ts.why,
                   MAX(CASE WHEN cf.name = "bim_model" THEN cv.value ELSE NULL END) AS bim_model,
                   MAX(CASE WHEN cf.name = "bim_image" THEN cv.value ELSE NULL END) AS bim_image,
                   MAX(CASE WHEN cf.name = "bim_response" THEN cv.value ELSE NULL END) AS bim_response,
                   MAX(CASE WHEN cf.name = "bim_electrical_connectors" THEN cv.value ELSE NULL END) AS bim_electrical_connectors
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users author ON author.id = t.author_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN task_smart ts ON ts.task_id = t.id
            LEFT JOIN custom_values cv ON cv.task_id = t.id
            LEFT JOIN custom_fields cf ON cf.id = cv.field_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY t.id, t.title, t.status, t.date_end, t.discipline, t.section,
                     p.id, p.code, p.title, author.name, assignee.name, ts.what, ts.why
            ORDER BY t.date_end IS NULL, t.date_end ASC, p.code ASC, t.id DESC
            LIMIT 300
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function assignedIssues(array $user): array
    {
        $stmt = $this->db()->prepare('
            SELECT i.*, p.code AS project_code, p.title AS project_title
            FROM project_issues i
            INNER JOIN projects p ON p.id = i.project_id
            WHERE i.assignee_id = ? AND i.status != \'done\' AND p.status = \'active\'
            ORDER BY i.date_raised IS NULL, i.date_raised ASC, i.id ASC
            LIMIT 80
        ');
        $stmt->execute([(int) $user['id']]);

        return $stmt->fetchAll();
    }

    private function taskSelect(): string
    {
        return '
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
                   p.code AS project_code,
                   p.title AS project_title,
                   p.kind AS project_kind,
                   p.gip_user_id AS project_gip_user_id,
                   p.status AS project_status,
                   p.archived_at AS project_archived_at,
                   gip_user.name AS project_gip_name,
                   gip_user.role AS project_gip_role,
                   p.file_folder_url AS project_file_folder_url,
                   parent.title AS parent_title,
                   assignee.name AS assignee_name,
                   assignee.email AS assignee_email,
                   assignee.role AS assignee_role,
                   assignee.department AS assignee_department,
                   author.name AS author_name,
                   author.email AS author_email,
                   author.department AS author_department,
                   reviewer.name AS reviewer_name,
                   reviewer.email AS reviewer_email,
                   reviewer.role AS reviewer_role,
                   reviewer.department AS reviewer_department,
                   pp.code AS pp_code,
                   pp.title AS pp_title,
                   btp.code AS btp_code,
                   btp.title AS btp_title,
                   closed_user.name AS closed_by_name,
                   EXISTS (
                       SELECT 1 FROM task_participants tp_current_assignee
                       WHERE tp_current_assignee.task_id = t.id
                         AND tp_current_assignee.user_id = :current_user_id
                         AND tp_current_assignee.role = "assignee"
                   ) AS current_user_is_assignee_participant,
                   EXISTS (
                       SELECT 1 FROM task_participants tp_current_coauthor
                       WHERE tp_current_coauthor.task_id = t.id
                         AND tp_current_coauthor.user_id = :current_user_id
                         AND tp_current_coauthor.role = "coauthor"
                   ) AS current_user_is_coauthor,
                   EXISTS (
                       SELECT 1 FROM task_participants tp_current_observer
                       WHERE tp_current_observer.task_id = t.id
                         AND tp_current_observer.user_id = :current_user_id
                         AND tp_current_observer.role = "observer"
                   ) AS current_user_is_observer,
                   COALESCE(uc.unread_count, 0) AS unread_comments,
                   COALESCE(tc.child_count, 0) AS child_count
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN tasks parent ON parent.id = t.parent_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users author ON author.id = t.author_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            LEFT JOIN users closed_user ON closed_user.id = t.closed_by
            LEFT JOIN users gip_user ON gip_user.id = p.gip_user_id
            LEFT JOIN (
                SELECT parent_id AS task_id, COUNT(*) AS child_count
                FROM tasks
                WHERE parent_id IS NOT NULL
                GROUP BY parent_id
            ) tc ON t.id = tc.task_id
            LEFT JOIN (
                SELECT c.task_id, COUNT(*) AS unread_count
                FROM comments c
                LEFT JOIN comment_reads cr
                  ON cr.task_id = c.task_id
                 AND cr.user_id = :current_user_id
                WHERE cr.last_read_at IS NULL OR c.created_at > cr.last_read_at
                GROUP BY c.task_id
            ) uc ON t.id = uc.task_id
        ';
    }

    private function task(int $id, array $user): ?array
    {
        [$where, $params] = PermissionService::taskScopeWhere($user);
        $sql = $this->taskSelect() . ' WHERE t.id = :task_id AND ' . $where . ' LIMIT 1';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(['current_user_id' => (int) $user['id'], 'task_id' => $id] + $params);

        return $stmt->fetch() ?: null;
    }

    private function taskPayload(): array
    {
        $dateStart = ($_POST['date_start'] ?? '') ?: null;
        $dateEndInput = trim((string) ($_POST['date_end'] ?? ''));
        $whenDueInput = trim((string) ($_POST['when_due'] ?? ''));
        $dateEnd = $dateEndInput !== '' ? $dateEndInput : ($whenDueInput !== '' ? $whenDueInput : null);
        $whenDue = $whenDueInput !== '' ? $whenDueInput : $dateEndInput;
        $priority = (string) ($_POST['priority'] ?? '');
        $urgency = (string) ($_POST['urgency'] ?? '');
        $dependencyTaskId = ($_POST['dependency_task_id'] ?? '') !== '' ? (int) $_POST['dependency_task_id'] : null;
        $structuralParentId = $dependencyTaskId === null && ($_POST['parent_id'] ?? '') !== ''
            ? (int) $_POST['parent_id']
            : null;
        $taskIntent = in_array((string) ($_POST['task_intent'] ?? ''), ['work', 'assign_out', 'assign_request', 'issuance', 'labor_estimate', 'delegate_department', 'bim_family_request'], true)
            ? (string) $_POST['task_intent']
            : '';
        $taskType = in_array((string) ($_POST['task_type'] ?? ''), ['work', 'assignment', 'issuance', 'labor_estimate', TaskWorkflowService::TASK_TYPE_DELEGATION, 'bim_family_request'], true) ? (string) $_POST['task_type'] : '';
        if ($taskIntent !== '') {
            $taskType = match ($taskIntent) {
                'assign_out', 'assign_request' => 'assignment',
                'issuance' => 'issuance',
                'labor_estimate' => 'labor_estimate',
                'delegate_department' => TaskWorkflowService::TASK_TYPE_DELEGATION,
                'bim_family_request' => 'bim_family_request',
                default => 'work',
            };
        }
        $projectId = (int) ($_POST['project_id'] ?? 0);
        try {
            $accounting = ProjectAccountingService::resolveTaskSelection(
                $projectId,
                ($_POST['pp_code_id'] ?? '') !== '' ? (int) $_POST['pp_code_id'] : null,
                ($_POST['btp_code_id'] ?? '') !== '' ? (int) $_POST['btp_code_id'] : null,
                trim((string) ($_POST['btp'] ?? ''))
            );
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        $reviewerId = ($_POST['reviewer_id'] ?? '') !== '' ? (int) $_POST['reviewer_id'] : null;
        if ($taskType === TaskWorkflowService::TASK_TYPE_DELEGATION) {
            $reviewerId = null;
        }

        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'task_type' => $taskType,
            'task_intent' => $taskIntent,
            'project_id' => $projectId,
            // Historical project-structure links remain intact; new task input is a single business section.
            'project_section_id' => null,
            'parent_id' => $structuralParentId,
            'assignee_id' => ($_POST['assignee_id'] ?? '') !== '' ? (int) $_POST['assignee_id'] : null,
            'reviewer_id' => $reviewerId,
            'discipline' => trim((string) ($_POST['section'] ?? '')) ?: null,
            'volume' => '',
            'section' => trim((string) ($_POST['section'] ?? '')),
            'status' => $_POST['status'] ?? 'new',
            'priority' => in_array($priority, ['low', 'mid', 'high'], true) ? $priority : '',
            'urgency' => in_array($urgency, ['low', 'mid', 'high'], true) ? $urgency : '',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'planned_hours' => ($_POST['planned_hours'] ?? '') !== '' ? (float) $_POST['planned_hours'] : null,
            'progress' => max(0, min(100, (int) ($_POST['progress'] ?? 0))),
            'pp_code_id' => $accounting['pp_code_id'],
            'btp_code_id' => $accounting['btp_code_id'],
            'btp' => $accounting['btp'],
            'speckle_stream_url' => trim((string) ($_POST['speckle_stream_url'] ?? '')),
            'what' => trim((string) ($_POST['what'] ?? '')),
            'when_due' => $whenDue,
            'why' => trim((string) ($_POST['why'] ?? '')),
            'depends_on' => $dependencyTaskId ? (string) $dependencyTaskId : '',
            'participants' => [
                'assignee' => [],
                'coauthor' => $this->normalizeParticipantIds($_POST['participant_coauthor_ids'] ?? []),
                'observer' => $this->normalizeParticipantIds($_POST['participant_observer_ids'] ?? []),
            ],
        ];
    }

    /** @return list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string}> */
    private function preparedAttachments(string $back): array
    {
        try {
            return TaskAttachmentService::validateIncoming($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
    }

    private function canUploadAttachments(array $user, array $task): bool
    {
        if (PermissionService::canEditTask($user, $task) || PermissionService::canUpdateTaskExecution($user, $task)) {
            return true;
        }
        $userId = (int) ($user['id'] ?? 0);
        if (in_array($userId, [(int) ($task['author_id'] ?? 0), (int) ($task['assignee_id'] ?? 0), (int) ($task['reviewer_id'] ?? 0)], true)) {
            return true;
        }
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM task_participants WHERE task_id = ? AND user_id = ?');
        $stmt->execute([(int) $task['id'], $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function validateSmart(array $data, bool $isCreate = false): void
    {
        if ($data['task_type'] === '') {
            flash('error', 'Выберите тип задачи. Это обязательный раздел постановки.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if ($data['title'] === '' || !$data['project_id'] || !$data['assignee_id'] || $data['what'] === '' || $data['when_due'] === '') {
            flash('error', 'Заполните обязательные поля: название, проект, исполнитель, что сделать и срок.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if (!in_array((string) $data['priority'], ['low', 'mid', 'high'], true) || !in_array((string) $data['urgency'], ['low', 'mid', 'high'], true)) {
            flash('error', 'Выберите важность и срочность задачи.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if ($data['planned_hours'] === null || (float) $data['planned_hours'] <= 0) {
            flash('error', 'Укажите план в часах. Это обязательное поле постановки.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if (trim((string) $data['section']) === '') {
            flash('error', 'Выберите раздел задачи. Это обязательное поле постановки.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if ($data['task_type'] === 'assignment' && !$data['assignee_id']) {
            flash('error', 'Для задания выберите получателя или ответственного, у кого запрашивается задание.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if ($data['task_type'] === 'labor_estimate' && !$data['assignee_id']) {
            flash('error', 'Для оценки трудозатрат выберите исполнителя.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
        if ($data['task_type'] === TaskWorkflowService::TASK_TYPE_DELEGATION && !$data['assignee_id']) {
            flash('error', 'Для делегирования выберите руководителя, который распределит работу.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }
    }

    private function applyAssigneeCostGroup(array &$data): void
    {
        $code = TaskCostGroupService::codeForUser((int) ($data['assignee_id'] ?? 0), $this->db());
        if ($code === '') {
            flash('error', 'У исполнителя не указана стоимостная группа. Назначьте её в управлении командой или штатном расписании.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
        }

        $data['cost_group_code'] = $code;
        $data['section'] = $code;
    }

    private function upsertSmart(int $taskId, array $data): void
    {
        $exists = $this->db()->prepare('SELECT COUNT(*) FROM task_smart WHERE task_id = ?');
        $exists->execute([$taskId]);
        if ((int) $exists->fetchColumn() > 0) {
            $stmt = $this->db()->prepare('UPDATE task_smart SET what = ?, when_due = ?, why = ?, depends_on = ? WHERE task_id = ?');
            $stmt->execute([$data['what'], $data['when_due'], $data['why'], $data['depends_on'], $taskId]);
            return;
        }

        $stmt = $this->db()->prepare('INSERT INTO task_smart (task_id, what, when_due, why, depends_on) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$taskId, $data['what'], $data['when_due'], $data['why'], $data['depends_on']]);
    }

    private function atlasPayload(array $source): array
    {
        $url = trim((string) ($source['atlas_url'] ?? ''));
        $elementId = trim((string) ($source['atlas_element_id'] ?? ''));
        $elementName = trim((string) ($source['atlas_element_name'] ?? ''));
        $modelId = trim((string) ($source['atlas_model_id'] ?? ''));
        $modelLabel = trim((string) ($source['atlas_model_label'] ?? ''));
        $context = trim((string) ($source['atlas_context'] ?? ''));
        $viewpoint = trim((string) ($source['atlas_viewpoint'] ?? ''));
        $overlay = trim((string) ($source['atlas_overlay'] ?? ''));

        if ($url === '' && $elementId === '' && $modelId === '') {
            return [];
        }

        $contextJson = null;
        $contextDecoded = null;
        if ($context !== '') {
            $decoded = json_decode($context, true);
            if (is_array($decoded)) {
                $contextDecoded = $decoded;
                $contextJson = $this->jsonPayload($decoded, 4000);
            }
        }
        $viewpointJson = $this->jsonPayload($viewpoint !== '' ? $viewpoint : ($contextDecoded['viewpoint'] ?? null), 4000);
        $overlayJson = $this->jsonPayload($overlay !== '' ? $overlay : ($contextDecoded['overlay'] ?? null), 2000);

        return [
            'atlas_url' => mb_substr($url, 0, 1600),
            'element_id' => mb_substr($elementId, 0, 255),
            'element_name' => mb_substr($elementName, 0, 255),
            'model_id' => mb_substr($modelId, 0, 255),
            'model_label' => mb_substr($modelLabel, 0, 255),
            'context_json' => $contextJson,
            'viewpoint_json' => $viewpointJson,
            'overlay_json' => $overlayJson,
        ];
    }

    private function jsonPayload(mixed $raw, int $maxLength = 4000): ?string
    {
        if (is_array($raw)) {
            $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false && mb_strlen($json) <= $maxLength ? $json : null;
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false && mb_strlen($json) <= $maxLength ? $json : null;
    }

    private function saveAtlasRef(int $taskId, int $projectId, int $userId, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $stmt = $this->db()->prepare('
            INSERT INTO task_atlas_refs (task_id, project_id, atlas_url, model_id, model_label, element_id, element_name, context_json, viewpoint_json, overlay_json, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $taskId,
            $projectId,
            $payload['atlas_url'],
            $payload['model_id'],
            $payload['model_label'],
            $payload['element_id'],
            $payload['element_name'],
            $payload['context_json'],
            $payload['viewpoint_json'],
            $payload['overlay_json'],
            $userId,
        ]);
    }

    private function customPayloads(int $projectId, string $taskType = 'work'): array
    {
        $payloads = [];
        foreach ($this->customFields($projectId) as $field) {
            if ($taskType !== 'bim_family_request' && str_starts_with((string) ($field['name'] ?? ''), 'bim_')) {
                continue;
            }

            $key = 'custom_' . $field['id'];
            if (!array_key_exists($key, $_POST) && $field['type'] !== 'bool') {
                continue;
            }

            $payloads[] = [
                'field' => $field,
                'value' => $this->normalizeCustomValue($field, $_POST[$key] ?? null),
            ];
        }

        return $payloads;
    }

    private function validateCustomPayloads(array $payloads): void
    {
        foreach ($payloads as $payload) {
            $field = $payload['field'];
            if ((int) $field['required'] === 1 && $this->customValueIsEmpty($field, $payload['value'])) {
                flash('error', 'Заполните обязательное поле: ' . $field['label']);
                redirect($_SERVER['HTTP_REFERER'] ?? '/tasks/new');
            }
        }
    }

    private function saveCustomValues(int $taskId, array $payloads): void
    {
        foreach ($payloads as $payload) {
            $field = $payload['field'];
            $value = $payload['value'];
            $exists = $this->db()->prepare('SELECT COUNT(*) FROM custom_values WHERE task_id = ? AND field_id = ?');
            $exists->execute([$taskId, $field['id']]);
            if ((int) $exists->fetchColumn() > 0) {
                $stmt = $this->db()->prepare('UPDATE custom_values SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE task_id = ? AND field_id = ?');
                $stmt->execute([$value, $taskId, $field['id']]);
            } else {
                $stmt = $this->db()->prepare('INSERT INTO custom_values (task_id, field_id, value) VALUES (?, ?, ?)');
                $stmt->execute([$taskId, $field['id'], $value]);
            }
        }
    }

    private function normalizeParticipantIds(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
    }

    private function emptyParticipants(): array
    {
        return [
            'assignee' => [],
            'coauthor' => [],
            'observer' => [],
        ];
    }

    private function saveParticipants(int $taskId, array $data): void
    {
        $roles = $data['participants'] ?? $this->emptyParticipants();
        $primaryAssigneeId = (int) ($data['assignee_id'] ?? 0);
        $seen = [];
        $rows = [];

        foreach (['assignee', 'coauthor', 'observer'] as $role) {
            foreach ($this->normalizeParticipantIds($roles[$role] ?? []) as $userId) {
                if ($userId === $primaryAssigneeId) {
                    continue;
                }
                if (isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;
                $rows[] = [$role, $userId];
            }
        }

        $this->db()->prepare('DELETE FROM task_participants WHERE task_id = ?')->execute([$taskId]);
        if ($rows === []) {
            return;
        }

        $stmt = $this->db()->prepare('INSERT INTO task_participants (task_id, user_id, role) VALUES (?, ?, ?)');
        foreach ($rows as [$role, $userId]) {
            $stmt->execute([$taskId, $userId, $role]);
        }
    }

    private function participants(int $taskId): array
    {
        $groups = $this->emptyParticipants();
        $stmt = $this->db()->prepare('
            SELECT tp.role, u.id, u.name, u.email, u.department, u.role AS user_role
            FROM task_participants tp
            INNER JOIN users u ON u.id = tp.user_id
            WHERE tp.task_id = ?
            ORDER BY
                CASE tp.role WHEN "assignee" THEN 1 WHEN "coauthor" THEN 2 ELSE 3 END,
                u.name
        ');
        $stmt->execute([$taskId]);
        foreach ($stmt->fetchAll() as $row) {
            $role = (string) $row['role'];
            if (array_key_exists($role, $groups)) {
                $groups[$role][] = $row;
            }
        }

        return $groups;
    }

    private function ensureBimFamilyCustomFields(): void
    {
        $fields = [
            ['bim_model', 'Модель', 'text', null, 0, 31],
            ['bim_image', 'Изображение', 'link', null, 0, 32],
            ['bim_response', 'Ответ BIM отдела', 'text', null, 0, 33],
            ['bim_electrical_connectors', 'Электрические коннекторы', 'select', '["Не требуется","Требуется","Настроить","Проверить"]', 0, 34],
        ];
        $exists = $this->db()->prepare('SELECT COUNT(*) FROM custom_fields WHERE project_id IS NULL AND name = ?');
        $insert = $this->db()->prepare('
            INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
            VALUES (?, ?, ?, NULL, ?, ?, ?)
        ');
        foreach ($fields as [$name, $label, $type, $options, $required, $sortOrder]) {
            $exists->execute([$name]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert->execute([$name, $label, $type, $options, $required, $sortOrder]);
            }
        }
    }

    private function normalizeCustomValue(array $field, mixed $raw): string
    {
        $type = (string) $field['type'];
        if ($type === 'bool') {
            return $raw ? '1' : '0';
        }

        if ($type === 'link') {
            if (!is_array($raw)) {
                return '';
            }
            $url = trim((string) ($raw['url'] ?? ''));
            $label = trim((string) ($raw['label'] ?? ''));
            return $url === '' ? '' : json_encode([['label' => $label, 'url' => $url]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($type === 'links') {
            if (!is_array($raw)) {
                return '';
            }
            $labels = array_values((array) ($raw['label'] ?? []));
            $urls = array_values((array) ($raw['url'] ?? []));
            $entries = [];
            foreach ($urls as $index => $url) {
                $url = trim((string) $url);
                if ($url === '') {
                    continue;
                }
                $entries[] = [
                    'label' => trim((string) ($labels[$index] ?? '')),
                    'url' => $url,
                ];
            }

            return $entries ? json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        }

        return is_array($raw) ? implode(',', array_map('trim', $raw)) : trim((string) $raw);
    }

    private function customValueIsEmpty(array $field, string $value): bool
    {
        if (in_array($field['type'], ['link', 'links'], true)) {
            return custom_link_entries($value) === [];
        }

        if ($field['type'] === 'bool') {
            return $value !== '1';
        }

        return trim($value) === '';
    }

    private function smart(int $taskId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM task_smart WHERE task_id = ?');
        $stmt->execute([$taskId]);
        return $stmt->fetch() ?: null;
    }

    private function issuances(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT i.*, u.name AS issued_by_name
            FROM task_issuances i
            LEFT JOIN users u ON u.id = i.issued_by
            WHERE i.task_id = ?
            ORDER BY i.issue_number ASC, i.issued_at ASC, i.id ASC
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }

    private function blockingData(int $taskId, int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT d.*
            FROM project_data_registry d
            WHERE d.project_id = ?
              AND COALESCE(d.blocking_task_ids, "") != ""
            ORDER BY d.status = "received", d.date_received_plan IS NULL, d.date_received_plan, d.id
        ');
        $stmt->execute([$projectId]);

        return array_values(array_filter($stmt->fetchAll(), static function (array $row) use ($taskId): bool {
            return in_array($taskId, task_id_list($row['blocking_task_ids'] ?? ''), true);
        }));
    }

    private function linkedIssues(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT i.*, u.name AS assignee_name
            FROM project_issues i
            LEFT JOIN users u ON u.id = i.assignee_id
            WHERE i.blocking_task_id = ?
            ORDER BY i.status = "done", i.date_raised IS NULL, i.date_raised, i.id
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }

    private function linkedSections(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT s.*, p.code AS project_code, u.name AS assignee_name
            FROM project_sections s
            INNER JOIN projects p ON p.id = s.project_id
            LEFT JOIN users u ON u.id = s.assignee_id
            WHERE s.task_id = ?
               OR s.id = (
                    SELECT project_section_id
                    FROM tasks
                    WHERE id = ?
                    LIMIT 1
               )
            ORDER BY s.volume, s.code, s.id
        ');
        $stmt->execute([$taskId, $taskId]);

        return $stmt->fetchAll();
    }

    private function atlasRefs(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT ar.*, u.name AS created_by_name
            FROM task_atlas_refs ar
            LEFT JOIN users u ON u.id = ar.created_by
            WHERE ar.task_id = ?
            ORDER BY ar.id ASC
        ');
        $stmt->execute([$taskId]);

        return array_map(function (array $row): array {
            $row['viewpoint_url'] = $this->atlasViewpointUrl(
                (string) ($row['atlas_url'] ?? ''),
                (string) ($row['viewpoint_json'] ?? ''),
                (string) ($row['overlay_json'] ?? ''),
                (string) ($row['element_id'] ?? '')
            );
            return $row;
        }, $stmt->fetchAll());
    }

    private function atlasViewpointUrl(string $url, string $viewpointJson, string $overlayJson, string $elementId): string
    {
        $url = trim($url);
        $viewpointJson = trim($viewpointJson);
        if ($url === '' || $viewpointJson === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        $query['viewpoint'] = $viewpointJson;
        if ($overlayJson !== '') {
            $query['atlas_overlay'] = $overlayJson;
        }
        if ($elementId !== '' && empty($query['highlight'])) {
            $query['highlight'] = $elementId;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    private function laborEstimateForTask(int $taskId): ?array
    {
        $stmt = $this->db()->prepare('
            SELECT le.*,
                   p.code AS project_code,
                   p.title AS project_title,
                   s.volume AS section_volume,
                   s.code AS section_code,
                   s.title AS section_title,
                   s.sbc_item_id,
                   s.sbc_quantity,
                   s.sbc_stage_percent,
                   s.sbc_deflator_coeff,
                   s.sbc_adjustment_coeff,
                   s.sbc_comment,
                   si.collection_code AS sbc_collection_code,
                   si.table_code AS sbc_table_code,
                   si.item_code AS sbc_item_code,
                   si.work_name AS sbc_work_name,
                   si.base_price AS sbc_base_price,
                   executor.name AS executor_name,
                   requester.name AS requested_by_name,
                   gip_user.name AS gip_approved_by_name,
                   director_user.name AS director_approved_by_name,
                   COALESCE(rate.hourly_rate, 0) AS hourly_rate
            FROM project_labor_estimates le
            INNER JOIN projects p ON p.id = le.project_id
            INNER JOIN project_sections s ON s.id = le.section_id
            INNER JOIN users executor ON executor.id = le.executor_id
            LEFT JOIN users requester ON requester.id = le.requested_by
            LEFT JOIN users gip_user ON gip_user.id = le.gip_approved_by
            LEFT JOIN users director_user ON director_user.id = le.director_approved_by
            LEFT JOIN employee_rates rate ON rate.user_id = le.executor_id
            LEFT JOIN sbc_items si ON si.id = s.sbc_item_id
            WHERE le.task_id = ?
            LIMIT 1
        ');
        $stmt->execute([$taskId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['effective_hours'] = $this->effectiveLaborHours($row);
        $row['money_thousand'] = round(((float) $row['effective_hours'] * (float) ($row['hourly_rate'] ?? 0)) / 1000, 2);
        $row['sbc_reference_cost'] = $this->laborSbcReferenceCost($row);
        $row['delta_thousand'] = round((float) $row['money_thousand'] - (float) $row['sbc_reference_cost'], 2);
        $row['allocations'] = $this->laborAllocations((int) $row['id']);

        return $row;
    }

    private function lastIssuanceAccepted(int $taskId): bool
    {
        $stmt = $this->db()->prepare('
            SELECT status
            FROM task_issuances
            WHERE task_id = ?
            ORDER BY issue_number DESC, issued_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute([$taskId]);

        return (string) ($stmt->fetchColumn() ?: '') === 'accepted';
    }

    private function approvalHistory(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT a.*, u.name AS approved_by_name, u.role AS approved_by_role
            FROM task_approvals a
            INNER JOIN users u ON u.id = a.approved_by
            WHERE a.task_id = ?
            ORDER BY a.created_at ASC, a.id ASC
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }

    private function recordApproval(int $taskId, string $stage, int $userId, string $decision, string $comment = ''): void
    {
        $stmt = $this->db()->prepare('
            INSERT INTO task_approvals (task_id, stage, approved_by, decision, comment)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$taskId, $stage, $userId, $decision, $comment]);
    }

    private function setApprovalStage(int $taskId, string $oldStage, string $newStage, int $userId): bool
    {
        $stmt = $this->db()->prepare('UPDATE tasks SET approval_stage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND approval_stage = ?');
        $stmt->execute([$newStage, $taskId, $oldStage]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        TaskWorkflowService::log($taskId, $userId, 'approval_stage', $oldStage, $newStage);

        return true;
    }

    private function approvalStageChanged(int $taskId): void
    {
        flash('error', 'Статус согласования уже изменился, обновите страницу.');
        redirect('/tasks/' . $taskId);
    }

    private function projectGipId(array $task): ?int
    {
        $gipId = (int) ($task['project_gip_user_id'] ?? 0);
        if ($gipId > 0) {
            return $gipId;
        }

        $stmt = $this->db()->prepare('SELECT id FROM users WHERE role = "gip" AND is_active = 1 ORDER BY id LIMIT 1');
        $stmt->execute();
        $fallback = $stmt->fetchColumn();

        return $fallback ? (int) $fallback : null;
    }

    private function approvalLeadReviewerId(array $task): ?int
    {
        $route = $this->approvalCentralReviewers($task);
        if ($route !== []) {
            return (int) $route[0]['id'];
        }

        return null;
    }

    private function nextApprovalCentralReviewerId(array $task, int $approvedBy): ?int
    {
        $route = $this->approvalCentralReviewers($task);
        if ($route === []) {
            return null;
        }

        $approved = [$approvedBy => true];
        $cycleStartedAt = $this->approvalLeadCycleStartedAt((int) ($task['id'] ?? 0));
        $cycleFilter = $cycleStartedAt !== '' ? ' AND created_at >= ?' : '';
        $stmt = $this->db()->prepare('
            SELECT approved_by
            FROM task_approvals
            WHERE task_id = ?
              AND stage = "review_lead"
              AND decision = "approved"
              ' . $cycleFilter . '
        ');
        $params = [(int) ($task['id'] ?? 0)];
        if ($cycleStartedAt !== '') {
            $params[] = $cycleStartedAt;
        }
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $approved[(int) ($row['approved_by'] ?? 0)] = true;
        }

        foreach ($route as $reviewer) {
            $reviewerId = (int) ($reviewer['id'] ?? 0);
            if ($reviewerId > 0 && !isset($approved[$reviewerId])) {
                return $reviewerId;
            }
        }

        return null;
    }

    private function approvalLeadCycleStartedAt(int $taskId): string
    {
        if ($taskId <= 0) {
            return '';
        }

        $stmt = $this->db()->prepare('
            SELECT created_at
            FROM task_logs
            WHERE task_id = ?
              AND field = "approval_stage"
              AND new_val = "review_lead"
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute([$taskId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function approvalCentralReviewers(array $task): array
    {
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        $reviewerId = (int) ($task['reviewer_id'] ?? 0);
        $gipId = (int) ($task['project_gip_user_id'] ?? 0);
        $candidates = [];

        if ($reviewerId > 0
            && $reviewerId !== $assigneeId
            && ($gipId <= 0 || $reviewerId !== $gipId)
            && !$this->isFinalApprovalRole((string) ($task['reviewer_role'] ?? ''))
            && $this->centralApprovalRoleRank((string) ($task['reviewer_role'] ?? '')) !== null
            && PermissionService::canAcceptWork(['id' => $reviewerId, 'role' => (string) ($task['reviewer_role'] ?? '')])
        ) {
            $candidates[$reviewerId] = [
                'id' => $reviewerId,
                'role' => (string) ($task['reviewer_role'] ?? ''),
                'name' => (string) ($task['reviewer_name'] ?? ''),
            ];
        }

        foreach ($this->approvalObserverFallbackRows((int) ($task['id'] ?? 0), array_filter([$assigneeId, $gipId])) as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId > 0 && !isset($candidates[$userId])) {
                $candidates[$userId] = $row;
            }
        }

        $route = array_values($candidates);
        usort($route, function (array $a, array $b): int {
            $rankA = $this->centralApprovalRoleRank((string) ($a['role'] ?? '')) ?? 999;
            $rankB = $this->centralApprovalRoleRank((string) ($b['role'] ?? '')) ?? 999;
            return $rankA <=> $rankB ?: (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });

        return $route;
    }

    private function isFinalApprovalRole(?string $role): bool
    {
        return RoleService::isAny($role, [RoleService::GIP, RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR]);
    }

    private function centralApprovalRoleRank(?string $role): ?int
    {
        $role = RoleService::normalize($role);
        return [
            RoleService::CHIEF_SPECIALIST => 10,
            RoleService::GROUP_LEAD => 20,
            RoleService::DEPUTY_DEPARTMENT_HEAD => 30,
            RoleService::DEPARTMENT_HEAD => 40,
        ][$role] ?? null;
    }

    /**
     * @param int[] $excludedUserIds
     */
    private function approvalObserverFallbackId(int $taskId, array $excludedUserIds): ?int
    {
        $rows = $this->approvalObserverFallbackRows($taskId, $excludedUserIds);
        return $rows !== [] ? (int) $rows[0]['id'] : null;
    }

    /**
     * @param int[] $excludedUserIds
     */
    private function approvalObserverFallbackRows(int $taskId, array $excludedUserIds): array
    {
        if ($taskId <= 0) {
            return [];
        }

        $stmt = $this->db()->prepare('
            SELECT u.id, u.name, u.role
            FROM task_participants tp
            INNER JOIN users u ON u.id = tp.user_id
            WHERE tp.task_id = ?
              AND tp.role = "observer"
              AND u.is_active = 1
            ORDER BY u.department, u.name, u.id
        ');
        $stmt->execute([$taskId]);
        $excluded = array_flip(array_filter(array_map('intval', $excludedUserIds)));
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0 || isset($excluded[$userId])) {
                continue;
            }
            if ($this->isFinalApprovalRole((string) ($row['role'] ?? ''))) {
                continue;
            }
            if ($this->centralApprovalRoleRank((string) ($row['role'] ?? '')) === null) {
                continue;
            }
            if (PermissionService::canAcceptWork(['id' => $userId, 'role' => (string) ($row['role'] ?? '')])) {
                $rows[] = $row;
            }
        }

        usort($rows, function (array $a, array $b): int {
            $rankA = $this->centralApprovalRoleRank((string) ($a['role'] ?? '')) ?? 999;
            $rankB = $this->centralApprovalRoleRank((string) ($b['role'] ?? '')) ?? 999;
            return $rankA <=> $rankB ?: (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });

        return $rows;
    }

    private function approvalGipApproverId(array $task): ?int
    {
        if ($this->isAssigneeSelfApprovalStage($task)) {
            return (int) $task['assignee_id'];
        }

        return $this->projectGipId($task);
    }

    private function isAssigneeSelfApprovalStage(array $task): bool
    {
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        if ($assigneeId <= 0 || (string) ($task['task_type'] ?? 'work') !== 'issuance') {
            return false;
        }

        $projectGipId = (int) ($task['project_gip_user_id'] ?? 0);
        return ($projectGipId <= 0 || $projectGipId === $assigneeId)
            && PermissionService::canSelfApproveIssuanceRole((string) ($task['assignee_role'] ?? ''));
    }

    private function syncScheduleFromIssuance(array $task, string $issuedAt, string $status): void
    {
        $projectId = (int) $task['project_id'];
        $taskId = (int) $task['id'];
        $volume = trim((string) ($task['volume'] ?: $task['section'] ?: $task['discipline'] ?: ('Задача #' . $taskId)));
        $section = trim((string) ($task['section'] ?: $task['discipline']));
        $statusLabel = task_issuance_status_label($status);

        $stmt = $this->db()->prepare('
            SELECT id
            FROM project_schedule
            WHERE project_id = ? AND task_id = ?
            ORDER BY id
            LIMIT 1
        ');
        $stmt->execute([$projectId, $taskId]);
        $scheduleId = $stmt->fetchColumn();

        if (!$scheduleId) {
            $stmt = $this->db()->prepare('
                SELECT id
                FROM project_schedule
                WHERE project_id = ?
                  AND task_id IS NULL
                  AND COALESCE(volume, "") = ?
                  AND COALESCE(section, "") = ?
                ORDER BY id
                LIMIT 1
            ');
            $stmt->execute([$projectId, $volume, $section]);
            $scheduleId = $stmt->fetchColumn();
        }

        if (!$scheduleId && $section !== '') {
            $stmt = $this->db()->prepare('
                SELECT id
                FROM project_schedule
                WHERE project_id = ?
                  AND task_id IS NULL
                  AND COALESCE(section, "") = ?
                ORDER BY id
                LIMIT 1
            ');
            $stmt->execute([$projectId, $section]);
            $scheduleId = $stmt->fetchColumn();
        }

        if ($scheduleId) {
            $this->db()->prepare('
                UPDATE project_schedule
                SET task_id = ?, date_issued = ?, issue_status = ?, rd_readiness_label = ?
                WHERE id = ?
            ')->execute([$taskId, $issuedAt, $statusLabel, $statusLabel, (int) $scheduleId]);
            return;
        }

        $stmt = $this->db()->prepare('
            INSERT INTO project_schedule (project_id, task_id, volume, section, rd_date_plan, date_issued, issue_status, rd_readiness_label, assignee_id, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $taskId,
            $volume,
            $section,
            $task['date_end'] ?: null,
            $issuedAt,
            $statusLabel,
            $statusLabel,
            $task['assignee_id'] ?: null,
            'Авто из выдачи задачи #' . $taskId . ': ' . $task['title'],
        ]);
    }

    private function attachBlockingDataCounts(array $tasks): array
    {
        if (!$tasks) {
            return [];
        }

        $taskIndex = [];
        $projectIds = [];
        foreach ($tasks as $index => $task) {
            $taskId = (int) $task['id'];
            $taskIndex[$taskId] = $index;
            $projectIds[(int) $task['project_id']] = true;
            $tasks[$index]['blocking_data_waiting_count'] = 0;
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->db()->prepare("
            SELECT project_id, blocking_task_ids, status
            FROM project_data_registry
            WHERE project_id IN ({$placeholders})
              AND COALESCE(blocking_task_ids, '') != ''
        ");
        $stmt->execute(array_keys($projectIds));

        foreach ($stmt->fetchAll() as $row) {
            if ((string) ($row['status'] ?? '') !== 'waiting') {
                continue;
            }
            foreach (task_id_list($row['blocking_task_ids'] ?? '') as $taskId) {
                if (isset($taskIndex[$taskId])) {
                    $tasks[$taskIndex[$taskId]]['blocking_data_waiting_count']++;
                }
            }
        }

        return $tasks;
    }

    private function relationTasks(array $user, ?int $projectId = null, ?int $excludeTaskId = null): array
    {
        [$scope, $params] = PermissionService::taskScopeWhere($user);
        $where = [$scope];
        if ($projectId) {
            $where[] = 't.project_id = :relation_project_id';
            $params['relation_project_id'] = $projectId;
        }
        if ($excludeTaskId) {
            $where[] = 't.id != :exclude_task_id';
            $params['exclude_task_id'] = $excludeTaskId;
        }
        $where[] = 'COALESCE(t.task_type, "") != "review"';

        $stmt = $this->db()->prepare('
            SELECT t.id, t.title, t.status, t.project_id, p.code AS project_code
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.code, t.id DESC
            LIMIT 300
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * The relation picker is intentionally capped, but a preselected parent must
     * always stay in the form; otherwise "+ Подзадача" silently posts no parent.
     */
    private function ensureRelationTaskOption(array $tasks, array $user, ?int $taskId, ?int $projectId = null): array
    {
        if ($taskId === null || $taskId <= 0) {
            return $tasks;
        }

        foreach ($tasks as $task) {
            if ((int) ($task['id'] ?? 0) === $taskId) {
                return $tasks;
            }
        }

        $task = $this->task($taskId, $user);
        if (!$task || (string) ($task['task_type'] ?? '') === TaskWorkflowService::TASK_TYPE_REVIEW) {
            return $tasks;
        }
        if ($projectId !== null && (int) ($task['project_id'] ?? 0) !== $projectId) {
            return $tasks;
        }

        array_unshift($tasks, [
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'status' => (string) ($task['status'] ?? ''),
            'project_id' => (int) $task['project_id'],
            'project_code' => (string) ($task['project_code'] ?? ''),
        ]);

        return $tasks;
    }

    private function dependencyTask(string $dependsOn): ?array
    {
        if ($dependsOn === '' || !ctype_digit($dependsOn)) {
            return null;
        }

        $stmt = $this->db()->prepare('
            SELECT t.id, t.title, t.status, p.code AS project_code
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
            LIMIT 1
        ');
        $stmt->execute([(int) $dependsOn]);

        return $stmt->fetch() ?: null;
    }

    private function taskHeaderActions(array $task, bool $canEdit, bool $editMode, bool $drawerMode = false): array
    {
        $taskPath = '/tasks/' . (int) $task['id'];
        $drawerSuffix = $drawerMode ? '&drawer=1' : '';
        $drawerViewSuffix = $drawerMode ? '?drawer=1' : '';
        $isPreprojectTask = (string) ($task['project_kind'] ?? 'project') === 'preproject';
        $currentUser = current_user() ?: [];
        $projectAction = null;
        if ($isPreprojectTask && PermissionService::canOpenReports($currentUser)) {
            $projectAction = ['label' => 'К оценке', 'href' => '/cost-estimates/' . (int) $task['project_id'], 'class' => 'btn-outline'];
        } elseif (!$isPreprojectTask) {
            $projectAction = ['label' => 'К проекту', 'href' => '/projects/' . (int) $task['project_id'], 'class' => 'btn-outline'];
        }
        $actions = $drawerMode ? [] : [
            ['label' => 'К задачам', 'href' => '/tasks', 'class' => 'btn-outline'],
        ];
        if (!$drawerMode && $projectAction !== null) {
            $actions[] = $projectAction;
        }
        if (!$drawerMode && !$isPreprojectTask) {
            $actions[] = ['label' => 'Гант проекта', 'href' => '/projects/' . (int) $task['project_id'] . '/gantt', 'class' => 'btn-outline'];
        }

        if ($editMode && $canEdit) {
            $actions[] = ['label' => 'Просмотр', 'href' => $taskPath . $drawerViewSuffix, 'class' => 'btn-outline'];
            $actions[] = ['label' => 'Сохранить', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'task-form', 'class' => 'btn-red'];
        } elseif ($canEdit) {
            $actions[] = ['label' => 'Редактировать', 'href' => $taskPath . '?edit=1' . $drawerSuffix, 'class' => 'btn-red'];
        }

        return $actions;
    }

    private function children(int $taskId): array
    {
        $stmt = $this->db()->prepare($this->taskSelect() . ' WHERE t.parent_id = :parent_id AND COALESCE(t.task_type, "") != "review" ORDER BY t.date_end IS NULL, t.date_end');
        $stmt->execute(['current_user_id' => (int) current_user()['id'], 'parent_id' => $taskId]);
        return $stmt->fetchAll();
    }

    private function openReviewTask(int $taskId): ?array
    {
        $stmt = $this->db()->prepare($this->taskSelect() . '
            WHERE t.parent_id = :parent_id
              AND t.task_type = :task_type
              AND t.status != "done"
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 1
        ');
        $stmt->execute([
            'current_user_id' => (int) current_user()['id'],
            'parent_id' => $taskId,
            'task_type' => TaskWorkflowService::TASK_TYPE_REVIEW,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function openReviewTasksForParent(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT id, status, progress
            FROM tasks
            WHERE parent_id = ?
              AND task_type = ?
              AND status != "done"
            ORDER BY id ASC
        ');
        $stmt->execute([$taskId, TaskWorkflowService::TASK_TYPE_REVIEW]);

        return $stmt->fetchAll();
    }

    private function closeLegacyReviewChildren(int $taskId, int $userId): void
    {
        foreach ($this->openReviewTasksForParent($taskId) as $reviewTask) {
            $this->db()->prepare('
                UPDATE tasks
                SET status = "done",
                    progress = 100,
                    closed_at = CURRENT_TIMESTAMP,
                    closed_by = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status != "done"
            ')->execute([$userId, (int) $reviewTask['id']]);
            TaskWorkflowService::log((int) $reviewTask['id'], $userId, 'status', (string) $reviewTask['status'], 'done');
            TaskWorkflowService::log((int) $reviewTask['id'], $userId, 'progress', (string) ($reviewTask['progress'] ?? 0), '100');
            TaskWorkflowService::log((int) $reviewTask['id'], $userId, 'review_cycle', '', 'Закрыто решением по исходной задаче #' . $taskId);
        }
    }

    private function comments(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT c.*, u.name AS user_name
            FROM comments c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    private function logs(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT l.*, u.name AS user_name
            FROM task_logs l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.task_id = ?
            ORDER BY l.created_at DESC
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    private function deadlineShifts(int $taskId): array
    {
        $stmt = $this->db()->prepare('
            SELECT s.*, u.name AS shifted_by_name, reviewer.name AS reviewed_by_name, r.label AS reason_label
            FROM task_deadline_shifts s
            LEFT JOIN users u ON u.id = s.shifted_by
            LEFT JOIN users reviewer ON reviewer.id = s.reviewed_by
            LEFT JOIN deadline_shift_reasons r ON r.code = s.reason_code
            WHERE s.task_id = ?
            ORDER BY s.created_at DESC
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    private function pendingDeadlineShift(int $taskId): ?array
    {
        $stmt = $this->db()->prepare('
            SELECT s.*, u.name AS shifted_by_name, r.label AS reason_label
            FROM task_deadline_shifts s
            LEFT JOIN users u ON u.id = s.shifted_by
            LEFT JOIN deadline_shift_reasons r ON r.code = s.reason_code
            WHERE s.task_id = ? AND s.status = "pending"
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT 1
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetch() ?: null;
    }

    private function requestDeadlineShiftFromEdit(array $task, int $userId, ?string $dateNew): void
    {
        $taskId = (int) $task['id'];
        if (!$dateNew || $dateNew === $this->dateOrNull($task['date_end'] ?? '')) {
            return;
        }

        $reasonCode = $this->reasonExists('other') ? 'other' : (string) ($this->deadlineReasons()[0]['code'] ?? 'other');
        $reasonText = trim((string) ($_POST['deadline_reason_text'] ?? ''));
        if ($reasonText === '') {
            $reasonText = 'Запрос переноса срока через редактирование задачи.';
        }

        $this->db()->prepare('
            UPDATE task_deadline_shifts
            SET status = "rejected", reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_comment = "Заменено новой заявкой на перенос срока."
            WHERE task_id = ? AND status = "pending"
        ')->execute([$userId, $taskId]);

        $this->db()->prepare('
            INSERT INTO task_deadline_shifts (task_id, shifted_by, date_old, date_new, reason_code, reason_text, status)
            VALUES (?, ?, ?, ?, ?, ?, "pending")
        ')->execute([$taskId, $userId, $this->dateOrNull($task['date_end'] ?? ''), $dateNew, $reasonCode, $reasonText]);

        TaskWorkflowService::log($taskId, $userId, 'deadline_shift', '', 'Запрошен перенос срока на ' . $dateNew);
        ActivityLogService::recordTask($taskId, $userId, 'task.deadline_shift_requested', 'Запрошен перенос срока', $dateNew . ' · ' . $reasonText);

        $reviewerId = (int) ($task['reviewer_id'] ?? 0);
        if ($reviewerId > 0 && $reviewerId !== $userId) {
            TaskWorkflowService::notify($reviewerId, $taskId, 'deadline_shift_requested', 'Задача #' . $taskId . ' ожидает подтверждения нового срока.');
        }
    }

    private function recordApprovedDeadlineShiftFromEdit(array $task, int $userId, ?string $dateNew): void
    {
        $taskId = (int) $task['id'];
        if (!$dateNew || $dateNew === $this->dateOrNull($task['date_end'] ?? '')) {
            return;
        }

        $this->db()->prepare('
            UPDATE task_deadline_shifts
            SET status = "rejected", reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP,
                review_comment = "Заменено прямым изменением срока проверяющим."
            WHERE task_id = ? AND status = "pending"
        ')->execute([$userId, $taskId]);

        $this->db()->prepare('
            INSERT INTO task_deadline_shifts (task_id, shifted_by, date_old, date_new, reason_code, reason_text, status, reviewed_by, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, "approved", ?, CURRENT_TIMESTAMP)
        ')->execute([
            $taskId,
            $userId,
            $this->dateOrNull($task['date_end'] ?? ''),
            $dateNew,
            $this->reasonExists('other') ? 'other' : (string) ($this->deadlineReasons()[0]['code'] ?? 'other'),
            'Срок изменён проверяющим при редактировании задачи.',
            $userId,
        ]);
    }

    private function applyApprovedDeadlineShift(array $task, int $userId, string $dateNew): void
    {
        $taskId = (int) $task['id'];
        $oldDeadline = $this->dateOrNull($task['date_end'] ?? '');

        $this->db()->prepare('
            UPDATE tasks
            SET status = "in_progress", date_end = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$dateNew, $taskId]);

        $updatedSmart = $this->db()->prepare('UPDATE task_smart SET when_due = ? WHERE task_id = ?');
        $updatedSmart->execute([$dateNew, $taskId]);
        if ($updatedSmart->rowCount() === 0) {
            $this->db()->prepare('INSERT INTO task_smart (task_id, what, when_due, why, depends_on) VALUES (?, ?, ?, ?, ?)')
                ->execute([$taskId, (string) ($task['title'] ?? 'Задача'), $dateNew, '', '']);
        }

        TaskWorkflowService::log($taskId, $userId, 'date_end', $oldDeadline ?? '', $dateNew);
        TaskWorkflowService::log($taskId, $userId, 'status', (string) ($task['status'] ?? ''), 'in_progress');
    }

    private function markCommentsRead(int $taskId, int $userId): void
    {
        $exists = $this->db()->prepare('SELECT COUNT(*) FROM comment_reads WHERE task_id = ? AND user_id = ?');
        $exists->execute([$taskId, $userId]);
        if ((int) $exists->fetchColumn() > 0) {
            $this->db()->prepare('UPDATE comment_reads SET last_read_at = CURRENT_TIMESTAMP WHERE task_id = ? AND user_id = ?')->execute([$taskId, $userId]);
            return;
        }

        $this->db()->prepare('INSERT INTO comment_reads (task_id, user_id, last_read_at) VALUES (?, ?, CURRENT_TIMESTAMP)')->execute([$taskId, $userId]);
    }

    private function projectsFor(array $user): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query("SELECT * FROM projects WHERE status = 'active' ORDER BY code")->fetchAll();
        }

        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.*
            FROM projects p
            WHERE p.status = \'active\'
              AND ' . $where . '
            ORDER BY p.code
        ');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function activeUsers(): array
    {
        $users = $this->db()->query('SELECT id, tab_number, name, role, department FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

        $users = (new VacationService($this->db()))->attachAvailability($users);

        return TaskCostGroupService::attachCodes($users, $this->db());
    }

    private function dateFilterValue(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function dateOrNull(mixed $value): ?string
    {
        $date = $this->dateFilterValue($value);

        return $date !== '' ? $date : null;
    }

    private function customFields(?int $projectId, bool $includeAllProjectFields = false, array $visibleProjectIds = []): array
    {
        if ($includeAllProjectFields) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $visibleProjectIds))));
            if ($ids === []) {
                return $this->customFields(null);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db()->prepare("
                SELECT cf.*, p.code AS project_code
                FROM custom_fields cf
                LEFT JOIN projects p ON p.id = cf.project_id
                WHERE cf.project_id IS NULL OR (cf.project_id IN ({$placeholders}) AND p.status = 'active')
                ORDER BY cf.project_id IS NOT NULL, p.code, cf.sort_order, cf.label
            ");
            $stmt->execute($ids);
            return $stmt->fetchAll();
        }

        if (!$projectId) {
            return $this->db()->query('
                SELECT cf.*, p.code AS project_code
                FROM custom_fields cf
                LEFT JOIN projects p ON p.id = cf.project_id
                WHERE cf.project_id IS NULL
                ORDER BY cf.sort_order, cf.label
            ')->fetchAll();
        }

        $stmt = $this->db()->prepare('
            SELECT cf.*, p.code AS project_code
            FROM custom_fields cf
            LEFT JOIN projects p ON p.id = cf.project_id
            WHERE cf.project_id IS NULL OR cf.project_id = ?
            ORDER BY cf.project_id IS NOT NULL, cf.sort_order, cf.label
        ');
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    private function customValues(int $taskId): array
    {
        $stmt = $this->db()->prepare('SELECT field_id, value FROM custom_values WHERE task_id = ?');
        $stmt->execute([$taskId]);
        return array_column($stmt->fetchAll(), 'value', 'field_id');
    }

    private function deadlineReasons(): array
    {
        return $this->db()->query('SELECT * FROM deadline_shift_reasons WHERE active = 1 ORDER BY id')->fetchAll();
    }

    private function reasonExists(string $code): bool
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM deadline_shift_reasons WHERE code = ? AND active = 1');
        $stmt->execute([$code]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function closeAuthorId(array $task): ?int
    {
        $authorId = (int) ($task['author_id'] ?? 0);
        if ($authorId > 0) {
            return $authorId;
        }

        $reviewerId = (int) ($task['reviewer_id'] ?? 0);
        return $reviewerId > 0 ? $reviewerId : null;
    }

    private function requiresGipCloseAcceptance(array $task): bool
    {
        return (string) ($task['task_type'] ?? 'work') === 'issuance';
    }

    private function canAcceptCloseByAuthor(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || !in_array((string) ($task['status'] ?? ''), ['review', 'pending_close'], true)) {
            return false;
        }
        if ($this->closeStageApproved($task, 'close_author')) {
            return false;
        }

        $acceptorId = $this->closeAuthorId($task);
        return $acceptorId !== null
            && ($acceptorId === (int) $user['id']
                || VacationService::isActiveSubstituteFor((int) $user['id'], $acceptorId, $this->db()))
            && PermissionService::canAcceptWork($user);
    }

    private function canAcceptCloseByGip(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)
            || !$this->requiresGipCloseAcceptance($task)
            || !in_array((string) ($task['status'] ?? ''), ['review', 'pending_close'], true)
            || $this->closeStageApproved($task, 'close_gip')
        ) {
            return false;
        }

        return $this->canGipReviewUser($user, $task);
    }

    private function canRespondToAssignment(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)) {
            return false;
        }
        if (!in_array((string) ($task['status'] ?? ''), ['new', TaskWorkflowService::STATUS_CORRECTION], true)) {
            return false;
        }
        if ((string) ($task['task_type'] ?? 'work') === TaskWorkflowService::TASK_TYPE_REVIEW) {
            return false;
        }

        return $this->canActForAssignee($user, $task);
    }

    private function taskActionBack(int $taskId): string
    {
        $back = trim((string) ($_POST['back'] ?? ''));
        if ($back === '/my-day' || str_starts_with($back, '/my-day?')) {
            return $back;
        }

        return '/tasks/' . $taskId;
    }

    private function canLogTime(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)) {
            return false;
        }
        if ((string) ($task['task_type'] ?? 'work') === TaskWorkflowService::TASK_TYPE_DELEGATION) {
            return false;
        }

        return $this->canActForAssignee($user, $task)
            || (int) ($task['reviewer_id'] ?? 0) === (int) $user['id']
            || (int) ($task['current_user_is_coauthor'] ?? 0) === 1;
    }

    private function canManageDelegation(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? 'work') !== TaskWorkflowService::TASK_TYPE_DELEGATION) {
            return false;
        }
        if (in_array((string) ($task['status'] ?? ''), ['done', 'pending_close', 'review'], true)) {
            return false;
        }
        if (PermissionService::canAdministerTasks($user)) {
            return true;
        }

        return (int) ($task['assignee_id'] ?? 0) === (int) ($user['id'] ?? 0)
            && RoleService::atLeast($user['role'] ?? null, RoleService::DEPARTMENT_HEAD);
    }

    private function canGipReviewUser(array $user, array $task): bool
    {
        $gipId = $this->projectGipId($task);
        return $gipId !== null && $gipId === (int) ($user['id'] ?? 0);
    }

    private function closeStageApproved(array $task, string $stage): bool
    {
        return $this->latestCloseDecision($task, $stage) === 'approved';
    }

    private function reviewCycleDecisionRecorded(array $task): bool
    {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0) {
            return false;
        }

        $requestedAt = ($task['close_requested_at'] ?? '') !== '' ? (string) $task['close_requested_at'] : null;
        $stmt = $this->db()->prepare('
            SELECT 1
            FROM task_approvals
            WHERE task_id = ?
              AND stage = "review_task"
              AND decision IN ("approved", "rejected")
              AND (? IS NULL OR created_at >= ?)
            LIMIT 1
        ');
        $stmt->execute([$taskId, $requestedAt, $requestedAt]);

        return (bool) $stmt->fetchColumn();
    }

    private function recordCloseApprovalIfPending(array $task, string $stage, int $userId, string $comment): bool
    {
        if ($this->closeStageApproved($task, $stage)) {
            return false;
        }

        $this->recordApproval((int) $task['id'], $stage, $userId, 'approved', $comment);
        return true;
    }

    private function latestCloseDecision(array $task, string $stage): ?string
    {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0 || !in_array($stage, ['close_author', 'close_gip'], true)) {
            return null;
        }

        $stmt = $this->db()->prepare('
            SELECT decision
            FROM task_approvals
            WHERE task_id = ?
              AND stage = ?
              AND (? IS NULL OR created_at >= ?)
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        $requestedAt = ($task['close_requested_at'] ?? '') !== '' ? (string) $task['close_requested_at'] : null;
        $stmt->execute([$taskId, $stage, $requestedAt, $requestedAt]);
        $decision = $stmt->fetchColumn();

        return $decision ? (string) $decision : null;
    }

    private function rejectClose(array $task, string $stage, int $userId, string $comment): void
    {
        $taskId = (int) $task['id'];
        $this->recordApproval($taskId, $stage, $userId, 'rejected', $comment);
        $this->db()->prepare('
            UPDATE tasks
            SET status = "in_progress", close_requested_at = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$taskId]);
        TaskWorkflowService::log($taskId, $userId, 'status', $task['status'], 'in_progress');
        TaskWorkflowService::log($taskId, $userId, 'close_review', task_approval_stage_label($stage), $comment);
        ActivityLogService::recordTask($taskId, $userId, 'task.close_rejected', 'Закрытие возвращено', $comment);

        if ($task['assignee_id']) {
            TaskWorkflowService::notify((int) $task['assignee_id'], $taskId, 'close_rejected', 'Закрытие задачи #' . $taskId . ' не принято: ' . $comment);
        }
    }

    private function finalizeClose(array $task, int $userId): void
    {
        $taskId = (int) $task['id'];
        // Идемпотентность: атомарно закрываем только ещё не закрытую задачу.
        // Защищает от двойного финального закрытия при параллельных запросах
        // (acceptClose + reviewCycleDecision). Портируемо на SQLite и MySQL.
        if (!TaskWorkflowIntegrityService::finalizeTask($this->db(), $taskId, $userId)) {
            // Задача уже закрыта другим запросом — повторные логи/уведомления не нужны.
            return;
        }
        TaskWorkflowService::log($taskId, $userId, 'status', $task['status'], 'done');
        TaskWorkflowService::log($taskId, $userId, 'progress', $task['progress'] ?? 0, 100);
        if (in_array((string) ($task['approval_stage'] ?? ''), ['review_lead', 'review_gip'], true)) {
            TaskWorkflowService::log($taskId, $userId, 'approval_stage', (string) $task['approval_stage'], 'approved');
        }
        TaskWorkflowService::recomputeParentProgress($task['parent_id'] ? (int) $task['parent_id'] : null);
        ActivityLogService::recordTask($taskId, $userId, 'task.close_accepted', 'Работа принята и закрыта');

        if ($task['assignee_id']) {
            TaskWorkflowService::notify((int) $task['assignee_id'], $taskId, 'closed', 'Задача #' . $taskId . ' закрыта.');
        }
        if ($task['author_id'] && (int) $task['author_id'] !== $userId) {
            TaskWorkflowService::notify((int) $task['author_id'], $taskId, 'closed', 'Задача #' . $taskId . ' закрыта.');
        }
    }

    private function canSubmitLaborEstimate(array $user, array $task, array $laborEstimate): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? '') !== 'labor_estimate') {
            return false;
        }

        $executorId = (int) ($laborEstimate['executor_id'] ?? 0);
        return $this->canActForAssignee($user, $task)
            && ($executorId === (int) $user['id']
                || VacationService::isActiveSubstituteFor((int) $user['id'], $executorId, $this->db()))
            && in_array((string) ($laborEstimate['status'] ?? ''), ['assigned', 'submitted', 'returned_to_responsible'], true);
    }

    private function canGipApproveLaborEstimate(array $user, array $task, array $laborEstimate): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? '') !== 'labor_estimate') {
            return false;
        }

        return PermissionService::canGipApproveLaborEstimates($user)
            && in_array((string) ($laborEstimate['status'] ?? ''), ['submitted', 'returned_to_gip', 'gip_approved'], true);
    }

    private function canGipReturnLaborEstimate(array $user, array $task, array $laborEstimate): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? '') !== 'labor_estimate') {
            return false;
        }

        return PermissionService::canGipApproveLaborEstimates($user)
            && in_array((string) ($laborEstimate['status'] ?? ''), ['submitted', 'gip_approved'], true);
    }

    private function canDirectorApproveLaborEstimate(array $user, array $task, array $laborEstimate): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? '') !== 'labor_estimate') {
            return false;
        }

        return PermissionService::canDirectorApproveLaborEstimates($user)
            && in_array((string) ($laborEstimate['status'] ?? ''), ['gip_approved', 'director_approved'], true);
    }

    private function canDirectorReturnLaborEstimate(array $user, array $task, array $laborEstimate): bool
    {
        if ($this->isArchivedTask($task) || (string) ($task['task_type'] ?? '') !== 'labor_estimate') {
            return false;
        }

        return PermissionService::canDirectorApproveLaborEstimates($user)
            && (string) ($laborEstimate['status'] ?? '') === 'gip_approved';
    }

    private function laborAmount(mixed $hoursValue, mixed $daysValue): array
    {
        $hours = $this->laborHours($hoursValue);
        $days = $this->laborHours($daysValue);
        if ($hours !== null) {
            return ['hours' => $hours, 'days' => $this->hoursToDays($hours)];
        }
        if ($days !== null) {
            return ['hours' => round($days * self::LABOR_HOURS_PER_DAY, 2), 'days' => $days];
        }

        return ['hours' => null, 'days' => null];
    }

    private function hoursToDays(float $hours): float
    {
        return round($hours / self::LABOR_HOURS_PER_DAY, 2);
    }

    private function laborAllocationsFromPost(): array
    {
        $userIds = is_array($_POST['allocation_user_id'] ?? null) ? $_POST['allocation_user_id'] : [];
        $hours = is_array($_POST['allocation_hours'] ?? null) ? $_POST['allocation_hours'] : [];
        $days = is_array($_POST['allocation_days'] ?? null) ? $_POST['allocation_days'] : [];
        $comments = is_array($_POST['allocation_comment'] ?? null) ? $_POST['allocation_comment'] : [];
        $rows = [];
        foreach ($userIds as $index => $userId) {
            $userId = (int) $userId;
            $amount = $this->laborAmount($hours[$index] ?? '', $days[$index] ?? '');
            if ($userId <= 0 || ($amount['hours'] ?? 0) <= 0) {
                continue;
            }
            $rows[] = [
                'user_id' => $userId,
                'hours' => (float) $amount['hours'],
                'days' => (float) $amount['days'],
                'comment' => mb_substr(trim((string) ($comments[$index] ?? '')), 0, 1000),
            ];
        }

        return $rows;
    }

    private function syncLaborAllocations(int $laborEstimateId, array $allocations): void
    {
        $this->db()->prepare('DELETE FROM project_labor_estimate_allocations WHERE labor_estimate_id = ?')->execute([$laborEstimateId]);
        if ($allocations === []) {
            return;
        }
        $stmt = $this->db()->prepare('
            INSERT INTO project_labor_estimate_allocations (labor_estimate_id, user_id, hours, days, comment)
            VALUES (?, ?, ?, ?, ?)
        ');
        foreach ($allocations as $allocation) {
            $stmt->execute([
                $laborEstimateId,
                (int) $allocation['user_id'],
                (float) $allocation['hours'],
                (float) $allocation['days'],
                (string) $allocation['comment'],
            ]);
        }
    }

    private function laborAllocations(int $laborEstimateId): array
    {
        $stmt = $this->db()->prepare('
            SELECT a.*, u.name AS user_name, u.department AS user_department
            FROM project_labor_estimate_allocations a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.labor_estimate_id = ?
            ORDER BY u.department, u.name, a.id
        ');
        $stmt->execute([$laborEstimateId]);

        return $stmt->fetchAll();
    }

    private function laborHours(mixed $value): ?float
    {
        $text = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim((string) $value));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        return round((float) $text, 2);
    }

    private function formatLaborHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    private function effectiveLaborHours(array $laborEstimate): float
    {
        return match ((string) ($laborEstimate['status'] ?? 'assigned')) {
            'director_approved' => (float) ($laborEstimate['director_hours'] ?? 0),
            'gip_approved', 'returned_to_gip' => (float) ($laborEstimate['gip_hours'] ?? 0),
            'submitted', 'returned_to_responsible' => (float) ($laborEstimate['executor_hours'] ?? 0),
            default => 0.0,
        };
    }

    private function laborSbcReferenceCost(array $laborEstimate): float
    {
        $base = (float) ($laborEstimate['sbc_base_price'] ?? 0);
        if ($base <= 0) {
            return 0.0;
        }

        return round(
            $base
            * (float) ($laborEstimate['sbc_quantity'] ?? 1)
            * (float) ($laborEstimate['sbc_stage_percent'] ?? 100) / 100
            * (float) ($laborEstimate['sbc_deflator_coeff'] ?? 1)
            * (float) ($laborEstimate['sbc_adjustment_coeff'] ?? 1),
            2
        );
    }

    private function notifyUsersByRoles(array $roles, int $taskId, string $type, string $message): void
    {
        $stmt = $this->db()->query('SELECT id, role FROM users WHERE is_active = 1');
        $ids = [];
        foreach ($stmt->fetchAll() as $user) {
            if (RoleService::isAny($user['role'] ?? null, $roles)) {
                $ids[] = (int) $user['id'];
            }
        }

        $this->notifyDistinctUsers($ids, $taskId, $type, $message);
    }

    private function notifyDistinctUsers(array $userIds, int $taskId, string $type, string $message): void
    {
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            TaskWorkflowService::notify($userId, $taskId, $type, $message);
        }
    }

    private function canReview(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)) {
            return false;
        }

        return (int) ($task['reviewer_id'] ?? 0) === (int) $user['id']
            || VacationService::isActiveSubstituteFor((int) $user['id'], (int) ($task['reviewer_id'] ?? 0), $this->db())
            || RoleService::isAny($user['role'] ?? null, [RoleService::GIP, RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR]);
    }

    private function canDecideReviewCycle(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)
            || (string) ($task['task_type'] ?? '') === TaskWorkflowService::TASK_TYPE_REVIEW
            || !in_array((string) ($task['status'] ?? ''), ['review', 'pending_close'], true)
            || trim((string) ($task['close_requested_at'] ?? '')) === ''
        ) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        return (int) ($task['reviewer_id'] ?? 0) === $userId
            || (int) ($task['author_id'] ?? 0) === $userId
            || VacationService::isActiveSubstituteFor($userId, (int) ($task['reviewer_id'] ?? 0), $this->db())
            || VacationService::isActiveSubstituteFor($userId, (int) ($task['author_id'] ?? 0), $this->db())
            || $this->canGipReviewUser($user, $task)
            || RoleService::isAny($user['role'] ?? null, [RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR, RoleService::ADMIN]);
    }

    private function canRequestClose(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || !TaskWorkflowIntegrityService::canStartClose($task)) {
            return false;
        }
        if ((string) ($task['task_type'] ?? 'work') === TaskWorkflowService::TASK_TYPE_DELEGATION) {
            return false;
        }

        return $this->canActForAssignee($user, $task)
            || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1;
    }

    private function canAdministerTask(array $user, array $task): bool
    {
        return !$this->isArchivedTask($task) && PermissionService::canAdministerTasks($user);
    }

    private function canForceCloseTask(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)) {
            return false;
        }
        if (PermissionService::canAdministerTasks($user)) {
            return true;
        }

        return RoleService::isAny($user['role'] ?? null, [RoleService::GIP])
            && (int) ($task['project_gip_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
    }

    private function taskDescendantIds(int $taskId): array
    {
        $result = [];
        $frontier = [$taskId];
        $stmt = $this->db()->prepare('
            SELECT t.id, t.parent_id, t.task_type, COALESCE(ts.depends_on, "") AS depends_on
            FROM tasks t
            LEFT JOIN task_smart ts ON ts.task_id = t.id
            WHERE t.parent_id = ?
            ORDER BY t.id ASC
        ');

        while ($frontier !== []) {
            $current = array_shift($frontier);
            $stmt->execute([(int) $current]);
            foreach ($stmt->fetchAll() as $row) {
                if (TaskWorkflowIntegrityService::isAmbiguousLegacyRelation($row)) {
                    continue;
                }
                $childId = (int) $row['id'];
                if ($childId <= 0 || in_array($childId, $result, true)) {
                    continue;
                }
                $result[] = $childId;
                $frontier[] = $childId;
            }
        }

        return $result;
    }

    private function detachTaskReferences(array $taskIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        TaskAttachmentService::deleteTaskFiles($ids, $this->db());
        foreach ([
            'UPDATE project_sections SET task_id = NULL WHERE task_id IN (' . $placeholders . ')',
            'UPDATE project_schedule SET task_id = NULL WHERE task_id IN (' . $placeholders . ')',
            'UPDATE project_issues SET blocking_task_id = NULL WHERE blocking_task_id IN (' . $placeholders . ')',
            'UPDATE project_task_exchange SET task_id = NULL WHERE task_id IN (' . $placeholders . ')',
            'UPDATE time_entries SET task_id = NULL WHERE task_id IN (' . $placeholders . ')',
            'UPDATE activity_logs SET task_id = NULL WHERE task_id IN (' . $placeholders . ')',
        ] as $sql) {
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($ids);
        }

        $registry = $this->db()->query('
            SELECT id, blocking_task_ids
            FROM project_data_registry
            WHERE COALESCE(blocking_task_ids, "") != ""
        ');
        $idsLookup = array_fill_keys($ids, true);
        $updateRegistry = $this->db()->prepare('UPDATE project_data_registry SET blocking_task_ids = ? WHERE id = ?');
        foreach ($registry->fetchAll() as $row) {
            $currentIds = task_id_list($row['blocking_task_ids'] ?? '');
            $filtered = array_values(array_filter($currentIds, static fn (int $currentId): bool => !isset($idsLookup[$currentId])));
            if ($filtered === $currentIds) {
                continue;
            }
            $updateRegistry->execute([implode(',', $filtered), (int) $row['id']]);
        }
    }

    private function deleteTaskOwnedRows(array $taskIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $laborIds = [];
        $stmt = $this->db()->prepare('SELECT id FROM project_labor_estimates WHERE task_id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $laborIds[] = (int) $row['id'];
        }
        if ($laborIds !== []) {
            $laborPlaceholders = implode(',', array_fill(0, count($laborIds), '?'));
            $this->db()->prepare('DELETE FROM project_labor_estimate_allocations WHERE labor_estimate_id IN (' . $laborPlaceholders . ')')->execute($laborIds);
        }

        foreach ([
            'document_revisions',
            'task_issuances',
            'task_approvals',
            'task_smart',
            'task_participants',
            'custom_values',
            'task_tags',
            'comments',
            'comment_reads',
            'task_logs',
            'attachments',
            'task_deadline_shifts',
            'deadline_reminders',
            'task_atlas_refs',
            'notifications',
            'project_labor_estimates',
        ] as $table) {
            $stmt = $this->db()->prepare('DELETE FROM ' . $table . ' WHERE task_id IN (' . $placeholders . ')');
            $stmt->execute($ids);
        }
    }

    private function deleteTasksByIds(array $taskIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return;
        }
        rsort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare('DELETE FROM tasks WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
    }

    private function canSubmitApproval(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || !TaskWorkflowIntegrityService::canStartApproval($task)) {
            return false;
        }
        if (!in_array((string) ($task['approval_stage'] ?? 'draft'), ['draft', 'issued'], true)) {
            return false;
        }
        if ($this->lastIssuanceAccepted((int) $task['id'])) {
            return false;
        }

        $isOwner = $this->canActForAssignee($user, $task)
            || (int) ($task['author_id'] ?? 0) === (int) $user['id']
            || VacationService::isActiveSubstituteFor((int) $user['id'], (int) ($task['author_id'] ?? 0), $this->db())
            || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1;

        return $isOwner && RoleService::atLeast($user['role'] ?? null, RoleService::ENGINEER);
    }

    // Самосогласование запрещено: нельзя принимать работу, которую ты сам исполнял.
    // Проверяем исполнителя (а не автора — постановщик вправе проверять работу подчинённого).
    private function isOwnTask(array $user, array $task): bool
    {
        $uid = (int) ($user['id'] ?? 0);
        return $uid > 0 && (int) ($task['assignee_id'] ?? 0) === $uid;
    }

    private function canActForAssignee(array $user, array $task): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $assigneeId = (int) ($task['assignee_id'] ?? 0);

        return $userId > 0 && (
            $assigneeId === $userId
            || VacationService::isActiveSubstituteFor($userId, $assigneeId, $this->db())
        );
    }

    private function canSelfApproveOwnIssuance(array $user, array $task): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $projectGipId = (int) ($task['project_gip_user_id'] ?? 0);

        return (string) ($task['task_type'] ?? 'work') === 'issuance'
            && $this->isOwnTask($user, $task)
            && ($projectGipId <= 0 || $projectGipId === $userId)
            && PermissionService::canSelfApproveIssuance($user);
    }

    // Кто согласовал указанный этап (для разделения обязанностей промежуточный согласующий ≠ ГИП).
    private function stageApproverId(int $taskId, string $stage): ?int
    {
        $stmt = $this->db()->prepare("SELECT approved_by FROM task_approvals WHERE task_id = ? AND stage = ? AND decision = 'approved' ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute([$taskId, $stage]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : null;
    }

    private function canLeadApprove(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || !TaskWorkflowIntegrityService::canContinueApproval($task)) {
            return false;
        }
        if ($this->isOwnTask($user, $task)) {
            return false;
        }

        return (int) ($task['reviewer_id'] ?? 0) === (int) $user['id']
            && !$this->isFinalApprovalRole($user['role'] ?? null)
            && $this->centralApprovalRoleRank($user['role'] ?? null) !== null
            && PermissionService::canAcceptWork($user);
    }

    private function canGipApprove(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task) || !TaskWorkflowIntegrityService::canContinueApproval($task)) {
            return false;
        }
        $isSelfApproval = $this->canSelfApproveOwnIssuance($user, $task);
        if ($this->isOwnTask($user, $task) && !$isSelfApproval) {
            return false;
        }
        // Разделение обязанностей: промежуточный согласующий не согласует как ГИП.
        if (!$isSelfApproval && $this->stageApproverId((int) ($task['id'] ?? 0), 'review_lead') === (int) ($user['id'] ?? 0)) {
            return false;
        }

        if ($isSelfApproval) {
            return true;
        }

        $approverId = $this->approvalGipApproverId($task);
        return $approverId !== null && $approverId === (int) ($user['id'] ?? 0);
    }

    private function canManageIssuances(array $user, array $task): bool
    {
        if ($this->isArchivedTask($task)) {
            return false;
        }

        return PermissionService::canEditTask($user, $task) || $this->canReview($user, $task);
    }

    private function isArchivedTask(array $task): bool
    {
        return (string) ($task['project_status'] ?? 'active') === 'archived';
    }

    private function ensureProjectIsActive(int $projectId): void
    {
        $stmt = $this->db()->prepare('SELECT status FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        if ((string) ($stmt->fetchColumn() ?: '') !== 'active') {
            flash('error', 'Архивный проект доступен только для просмотра.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks');
        }
    }

    private function ensureTaskProjectScope(array $user, int $projectId, ?int $parentTaskId = null): void
    {
        $visibleProjectIds = array_map(static fn (array $project): int => (int) $project['id'], $this->projectsFor($user));
        if (!in_array($projectId, $visibleProjectIds, true)) {
            flash('error', 'Проект недоступен для постановки задачи.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks');
        }

        if ($parentTaskId === null) {
            return;
        }

        $parentTask = $this->task($parentTaskId, $user);
        if (!$parentTask || (int) ($parentTask['project_id'] ?? 0) !== $projectId) {
            flash('error', 'Связанная родительская задача недоступна или относится к другому проекту.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/tasks');
        }
    }

    private function notFound(): never
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Задача не найдена', 'message' => 'Задача не существует или недоступна.']);
        exit;
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для действия.']);
        exit;
    }
}
