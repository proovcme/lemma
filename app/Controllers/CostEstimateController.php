<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CostEstimateSuggestionService;
use App\Services\CostEstimateModelService;
use App\Services\CostEstimatePlanningService;
use App\Services\ActivityLogService;
use App\Services\PermissionService;
use App\Services\ProjectManagementTaskService;
use App\Services\RoleService;
use App\Services\SbcCatalogService;
use App\Services\TaskWorkflowService;

final class CostEstimateController extends BaseController
{
    private const LABOR_HOURS_PER_DAY = 8.0;

    public function index(): void
    {
        $user = require_auth();
        if (!PermissionService::canAccessLaborEstimates($user)) {
            $this->forbidden();
        }

        $this->render('cost_estimates/index', [
            'title' => 'Оценка',
            'preprojects' => $this->preprojects($user),
            'objectTypes' => CostEstimateSuggestionService::OBJECT_TYPES,
            'canEdit' => PermissionService::canManagePreprojects($user),
            'canManageRates' => PermissionService::canManageEmployeeRates($user),
        ]);
    }

    public function store(): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }

        try {
            $payload = $this->preprojectPayload();
            $this->ensureUniqueCode($payload['code']);
            $stmt = $this->db()->prepare('
                INSERT INTO projects (
                    kind, code, title, `object`, address, object_type, area_m2, stages_text,
                    stage, status, color, file_folder_url, gip_user_id, rp_user_id
                )
                VALUES ("preproject", ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, "", NULL, NULL)
            ');
            $stmt->execute([
                $payload['code'],
                $payload['title'],
                $payload['object'],
                $payload['address'],
                $payload['object_type'],
                $payload['area_m2'],
                $payload['stages_text'],
                $payload['stage'],
                $payload['color'],
            ]);
            $projectId = (int) $this->db()->lastInsertId();
            foreach ($this->sectionsFromText($payload['sections_text']) as $section) {
                $this->insertSection($projectId, $section);
            }
            ActivityLogService::recordProject($projectId, (int) $user['id'], 'project.preproject_created', 'Предпроект создан', $payload['code'] . ' · ' . $payload['title']);

            flash('success', 'Предпроект создан.');
            redirect('/cost-estimates/' . $projectId);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/cost-estimates');
        }
    }

    public function show(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canAccessLaborEstimates($user)) {
            $this->forbidden();
        }

        $preproject = $this->preproject($id, $user);
        if (!$preproject) {
            $this->notFound();
        }

        $sections = $this->sections($id);
        $filters = $_GET;
        $planningService = new CostEstimatePlanningService();
        $laborRows = $planningService->enrichRows($this->laborRows($id, $user, $filters));
        $laborAllocations = $this->laborAllocations(array_map(static fn (array $row): int => (int) $row['id'], $laborRows));
        $totalSections = $sections;
        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD]) && !PermissionService::canManagePreprojects($user)) {
            $visibleSectionIds = array_flip(array_map(static fn (array $row): int => (int) $row['section_id'], $laborRows));
            $totalSections = array_values(array_filter($sections, static fn (array $section): bool => isset($visibleSectionIds[(int) $section['id']])));
        }
        $totals = $planningService->summary($preproject, $totalSections, $laborRows);
        $managerTaskStats = $planningService->taskStatistics($this->db(), $user);
        $sectionPlanningRows = $planningService->sectionPlanningRows($sections, $laborRows, $managerTaskStats);
        $sbcCatalog = new SbcCatalogService();

        $this->render('cost_estimates/show', [
            'title' => $preproject['code'] . ' · Оценка',
            'preproject' => $preproject,
            'sections' => $sections,
            'laborRows' => $laborRows,
            'laborAllocations' => $laborAllocations,
            'laborResponsibleTotals' => $this->laborResponsibleTotals($laborRows),
            'laborAssigneeTotals' => $this->laborAssigneeTotals($laborAllocations),
            'filters' => $filters,
            'totals' => $totals,
            'planningSummary' => $totals,
            'managerTaskStats' => $managerTaskStats,
            'sectionPlanningRows' => $sectionPlanningRows,
            'objectTypes' => CostEstimateSuggestionService::OBJECT_TYPES,
            'sbcItems' => $sbcCatalog->options($this->db()),
            'sbcIndices' => $sbcCatalog->indices($this->db()),
            'departments' => $this->departments(),
            'users' => $this->activeUsers(),
            'canEdit' => PermissionService::canManagePreprojects($user),
            'canSeeMoney' => PermissionService::canSeeLaborMoney($user),
            'canSeeRates' => PermissionService::canManageEmployeeRates($user),
            'canManageRates' => PermissionService::canManageEmployeeRates($user),
            'canGipApprove' => PermissionService::canGipApproveLaborEstimates($user),
            'canDirectorApprove' => PermissionService::canDirectorApproveLaborEstimates($user),
        ]);
    }

    public function update(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->preproject($id, $user)) {
            $this->notFound();
        }

        try {
            $payload = $this->preprojectPayload();
            $this->ensureUniqueCode($payload['code'], $id);
            $this->db()->prepare('
                UPDATE projects
                SET code = ?, title = ?, `object` = ?, address = ?, object_type = ?, area_m2 = ?,
                    stages_text = ?, stage = ?, color = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND COALESCE(kind, "project") = "preproject"
            ')->execute([
                $payload['code'],
                $payload['title'],
                $payload['object'],
                $payload['address'],
                $payload['object_type'],
                $payload['area_m2'],
                $payload['stages_text'],
                $payload['stage'],
                $payload['color'],
                $id,
            ]);
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.preproject_updated', 'Паспорт предпроекта обновлён', $payload['code'] . ' · ' . $payload['title']);
            flash('success', 'Паспорт предпроекта обновлён.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/cost-estimates/' . $id);
    }

    public function storeSection(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->preproject($id, $user)) {
            $this->notFound();
        }

        try {
            $payload = $this->sectionPayload();
            $this->insertSection($id, $payload);
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.preproject_updated', 'Раздел предпроекта добавлен', trim((string) (($payload['code'] ?? '') . ' ' . ($payload['title'] ?? ''))));
            flash('success', 'Раздел добавлен.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/cost-estimates/' . $id);
    }

    public function updateSection(int $id, int $sectionId): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->sectionBelongsToPreproject($id, $sectionId)) {
            $this->notFound();
        }

        try {
            $payload = $this->sectionPayload();
            $this->db()->prepare('
                UPDATE project_sections
                SET volume = ?, code = ?, title = ?, status = ?, date_start = ?, date_end = ?,
                    assignee_id = ?, sbc_item_id = ?, sbc_quantity = ?, sbc_stage_percent = ?,
                    sbc_deflator_coeff = ?, sbc_adjustment_coeff = ?, sbc_comment = ?, comments = ?
                WHERE id = ? AND project_id = ?
            ')->execute([
                $payload['volume'],
                $payload['code'],
                $payload['title'],
                $payload['status'],
                $payload['date_start'],
                $payload['date_end'],
                $payload['assignee_id'],
                $payload['sbc_item_id'],
                $payload['sbc_quantity'],
                $payload['sbc_stage_percent'],
                $payload['sbc_deflator_coeff'],
                $payload['sbc_adjustment_coeff'],
                $payload['sbc_comment'],
                $payload['comments'],
                $sectionId,
                $id,
            ]);
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.preproject_updated', 'Раздел предпроекта обновлён', trim((string) (($payload['code'] ?? '') . ' ' . ($payload['title'] ?? ''))));
            flash('success', 'Раздел обновлён.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/cost-estimates/' . $id);
    }

    public function deleteSection(int $id, int $sectionId): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->sectionBelongsToPreproject($id, $sectionId)) {
            $this->notFound();
        }

        $hasLabor = $this->db()->prepare('SELECT COUNT(*) FROM project_labor_estimates WHERE section_id = ?');
        $hasLabor->execute([$sectionId]);
        if ((int) $hasLabor->fetchColumn() > 0) {
            flash('error', 'Нельзя удалить раздел: по нему уже есть задачи оценки.');
            redirect('/cost-estimates/' . $id);
        }

        $this->db()->prepare('DELETE FROM project_sections WHERE id = ? AND project_id = ?')->execute([$sectionId, $id]);
        ActivityLogService::recordProject($id, (int) $user['id'], 'project.preproject_updated', 'Раздел предпроекта удалён', 'ID раздела: ' . $sectionId);
        flash('success', 'Раздел удалён.');
        redirect('/cost-estimates/' . $id);
    }

    public function bulkSectionSbc(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->preproject($id, $user)) {
            $this->notFound();
        }

        $sectionIds = $this->postedSectionIds($id);
        if ($sectionIds === []) {
            flash('error', 'Выберите разделы для назначения СБЦ.');
            redirect('/cost-estimates/' . $id);
        }

        $sbcItemId = ($_POST['sbc_item_id'] ?? '') !== '' ? (int) $_POST['sbc_item_id'] : null;
        if ($sbcItemId === null) {
            flash('error', 'Выберите пункт СБЦ для выбранных разделов.');
            redirect('/cost-estimates/' . $id);
        }

        $sbcCatalog = new SbcCatalogService();
        if (!$sbcCatalog->find($this->db(), $sbcItemId)) {
            flash('error', 'Пункт СБЦ не найден.');
            redirect('/cost-estimates/' . $id);
        }
        $indexCoeff = $this->decimal($_POST['sbc_deflator_coeff'] ?? 1, 1);
        if (($_POST['sbc_index_id'] ?? '') !== '') {
            $index = $sbcCatalog->indexById($this->db(), (int) $_POST['sbc_index_id']);
            if ($index) {
                $indexCoeff = (float) ($index['index_value'] ?? 1);
            }
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $this->db()->prepare('
            UPDATE project_sections
            SET sbc_item_id = ?, sbc_quantity = ?, sbc_stage_percent = ?, sbc_deflator_coeff = ?,
                sbc_adjustment_coeff = ?, sbc_comment = ?
            WHERE project_id = ? AND id IN (' . $placeholders . ')
        ')->execute(array_merge([
            $sbcItemId,
            max(0.0, $this->decimal($_POST['sbc_quantity'] ?? 1, 1)),
            max(0.0, $this->decimal($_POST['sbc_stage_percent'] ?? 100, 100)),
            max(0.0, $indexCoeff),
            max(0.0, $this->decimal($_POST['sbc_adjustment_coeff'] ?? 1, 1)),
            trim((string) ($_POST['sbc_comment'] ?? '')),
            $id,
        ], $sectionIds));

        ActivityLogService::recordProject($id, (int) $user['id'], 'project.preproject_updated', 'СБЦ назначен выбранным разделам', 'Разделов: ' . count($sectionIds));
        flash('success', 'СБЦ назначен выбранным разделам: ' . count($sectionIds) . '.');
        redirect('/cost-estimates/' . $id . '#quick-planning');
    }

    public function bulkLaborEstimates(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        $preproject = $this->preproject($id, $user);
        if (!$preproject) {
            $this->notFound();
        }

        $sectionIds = $this->postedSectionIds($id);
        if ($sectionIds === []) {
            flash('error', 'Выберите разделы для создания строк оценки.');
            redirect('/cost-estimates/' . $id);
        }

        $sections = [];
        foreach ($this->sections($id) as $section) {
            $sections[(int) $section['id']] = $section;
        }
        $existing = $this->laborSectionIds($id);
        $forcedExecutorId = ($_POST['executor_id'] ?? '') !== '' ? (int) $_POST['executor_id'] : 0;
        if ($forcedExecutorId > 0 && !$this->userExists($forcedExecutorId)) {
            flash('error', 'Выбранный ответственный не найден.');
            redirect('/cost-estimates/' . $id . '#quick-planning');
        }

        $created = 0;
        $skipped = 0;
        $missingExecutor = 0;
        $this->db()->beginTransaction();
        try {
            foreach ($sectionIds as $sectionId) {
                $section = $sections[$sectionId] ?? null;
                if (!$section || isset($existing[$sectionId])) {
                    $skipped++;
                    continue;
                }
                $executorId = $forcedExecutorId > 0 ? $forcedExecutorId : (int) ($section['assignee_id'] ?? 0);
                if ($executorId <= 0 || !$this->userExists($executorId)) {
                    $missingExecutor++;
                    continue;
                }
                $sectionHours = $_POST['section_hours'][$sectionId] ?? $_POST['suggested_hours'][$sectionId] ?? 0;
                $input = [
                    'department_code' => trim((string) ($_POST['department_code'] ?? '')),
                    'work_title' => trim((string) ($section['title'] ?? '')) ?: trim((string) ($section['code'] ?? 'Раздел')),
                    'executor_hours' => $this->decimal($sectionHours, 0),
                    'model_quantity' => 1,
                    'model_complexity_coeff' => 1,
                    'model_typicality_coeff' => 1,
                    'model_bim_coeff' => 1,
                    'model_urgency_coeff' => 1,
                    'model_input_quality_coeff' => 1,
                ];
                $payload = $this->laborEstimatePayloadFromInput($preproject, $section, $executorId, $input);
                $this->insertLaborEstimateRecord(
                    $id,
                    (int) $section['id'],
                    $input['work_title'],
                    'Пакетная оценка по разделу.',
                    $executorId,
                    $payload,
                    (int) $user['id'],
                    'draft',
                    ''
                );
                $created++;
            }
            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        ActivityLogService::recordProject($id, (int) $user['id'], 'project.labor_estimate_created', 'Строки оценки созданы пакетно', 'Создано: ' . $created . ', пропущено: ' . $skipped);
        $message = 'Создано строк оценки: ' . $created . '.';
        if ($skipped > 0) {
            $message .= ' Уже существовали: ' . $skipped . '.';
        }
        if ($missingExecutor > 0) {
            $message .= ' Без ответственного: ' . $missingExecutor . '.';
        }
        flash($created > 0 ? 'success' : 'error', $message);
        redirect('/cost-estimates/' . $id . '#quick-planning');
    }

    public function storeLaborEstimate(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canAccessLaborEstimates($user)) {
            $this->forbidden();
        }
        $preproject = $this->preproject($id, $user);
        if (!$preproject) {
            $this->notFound();
        }

        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $sectionCode = trim((string) ($_POST['section_code'] ?? ''));
        $sectionTitle = trim((string) ($_POST['section_title'] ?? ''));
        $sectionVolume = trim((string) ($_POST['section_volume'] ?? ''));
        $executorId = PermissionService::canManagePreprojects($user) ? (int) ($_POST['executor_id'] ?? 0) : (int) $user['id'];
        $workTitle = trim((string) ($_POST['work_title'] ?? ''));
        $workDescription = trim((string) ($_POST['work_description'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if ($executorId <= 0 || !$this->userExists($executorId)) {
            flash('error', 'Выберите ответственного за оценку.');
            redirect('/cost-estimates/' . $id);
        }
        if ($workTitle === '') {
            flash('error', 'Укажите задачу или вид работ для оценки.');
            redirect('/cost-estimates/' . $id);
        }
        if ($sectionId <= 0 && $sectionTitle === '') {
            flash('error', 'Укажите раздел строки оценки.');
            redirect('/cost-estimates/' . $id);
        }
        if ($sectionId > 0 && !$this->section($id, $sectionId)) {
            flash('error', 'Выбранный раздел не найден.');
            redirect('/cost-estimates/' . $id);
        }

        $this->db()->beginTransaction();
        try {
            $section = $sectionId > 0
                ? $this->section($id, $sectionId)
                : $this->findOrCreateLaborSection($id, $sectionCode, $sectionTitle, $sectionVolume, $executorId);
            if (!$section) {
                throw new \RuntimeException('Раздел строки оценки не найден.');
            }

            $payload = $this->laborEstimatePayload($preproject, $section, $executorId);
            $status = PermissionService::canManagePreprojects($user) ? 'draft' : 'department_submitted';
            $this->insertLaborEstimateRecord($id, (int) $section['id'], $workTitle, $workDescription, $executorId, $payload, (int) $user['id'], $status, $comment);
            $estimateId = (int) $this->db()->lastInsertId();
            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        ActivityLogService::recordProject($id, (int) $user['id'], 'project.labor_estimate_created', 'Строка оценки назначена', $workTitle);
        flash('success', 'Строка оценки добавлена в реестр.');
        redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
    }

    public function submitDepartmentEstimate(int $id, int $estimateId): void
    {
        $user = require_auth();
        $row = $this->laborEstimateForAction($id, $estimateId, $user);
        if (!$this->canDepartmentEditRow($user, $row)) {
            $this->forbidden();
        }
        $payload = $this->laborEstimatePayload($this->preproject($id, $user) ?: [], $row, (int) $row['executor_id']);
        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET executor_hours = ?, executor_days = ?, executor_comment = ?, executor_submitted_at = CURRENT_TIMESTAMP,
                department_submitted_by = ?, department_submitted_at = CURRENT_TIMESTAMP, department_code = ?,
                model_object_type = ?, model_stage = ?, model_area_m2 = ?, model_quantity = ?,
                model_complexity_coeff = ?, model_typicality_coeff = ?, model_bim_coeff = ?, model_urgency_coeff = ?,
                model_input_quality_coeff = ?, model_suggested_hours = ?, model_basis = ?, sbc_item_id = ?,
                sbc_quantity = ?, sbc_stage_percent = ?, sbc_index_id = ?, sbc_adjustment_coeff = ?,
                sbc_cost_snapshot = ?, sbc_basis_snapshot = ?, returned_by = NULL, returned_at = NULL,
                return_comment = NULL, status = "department_submitted", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $payload['executor_hours'],
            $payload['executor_days'],
            trim((string) ($_POST['executor_comment'] ?? '')),
            (int) $user['id'],
            $payload['department_code'],
            $payload['model_object_type'],
            $payload['model_stage'],
            $payload['model_area_m2'],
            $payload['model_quantity'],
            $payload['model_complexity_coeff'],
            $payload['model_typicality_coeff'],
            $payload['model_bim_coeff'],
            $payload['model_urgency_coeff'],
            $payload['model_input_quality_coeff'],
            $payload['model_suggested_hours'],
            $payload['model_basis'],
            $payload['sbc_item_id'],
            $payload['sbc_quantity'],
            $payload['sbc_stage_percent'],
            $payload['sbc_index_id'],
            $payload['sbc_adjustment_coeff'],
            $payload['sbc_cost_snapshot'],
            $payload['sbc_basis_snapshot'],
            $estimateId,
        ]);
        flash('success', 'Оценка отдела отправлена ГИПу.');
        redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
    }

    public function gipAdjustEstimate(int $id, int $estimateId): void
    {
        $user = require_auth();
        if (!PermissionService::canGipApproveLaborEstimates($user)) {
            $this->forbidden();
        }
        $this->laborEstimateForAction($id, $estimateId, $user);
        $hours = max(0.0, $this->decimal($_POST['gip_hours'] ?? 0));
        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET gip_hours = ?, gip_days = ?, gip_comment = ?, gip_approved_by = ?, gip_approved_at = CURRENT_TIMESTAMP,
                gip_adjusted_at = CURRENT_TIMESTAMP, returned_by = NULL, returned_at = NULL, return_comment = NULL,
                status = "gip_adjusted", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$hours, round($hours / self::LABOR_HOURS_PER_DAY, 2), trim((string) ($_POST['gip_comment'] ?? '')), (int) $user['id'], $estimateId]);
        flash('success', 'ГИП скорректировал оценку.');
        redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
    }

    public function gipReturnEstimate(int $id, int $estimateId): void
    {
        $user = require_auth();
        if (!PermissionService::canGipApproveLaborEstimates($user)) {
            $this->forbidden();
        }
        $this->laborEstimateForAction($id, $estimateId, $user);
        $comment = trim((string) ($_POST['return_comment'] ?? ''));
        if ($comment === '') {
            flash('error', 'Укажите причину возврата.');
            redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
        }
        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET returned_by = ?, returned_at = CURRENT_TIMESTAMP, return_comment = ?, status = "returned_to_department", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $user['id'], $comment, $estimateId]);
        flash('success', 'Оценка возвращена отделу.');
        redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
    }

    public function directorApproveEstimate(int $id, int $estimateId): void
    {
        $user = require_auth();
        if (!PermissionService::canDirectorApproveLaborEstimates($user)) {
            $this->forbidden();
        }
        $row = $this->laborEstimateForAction($id, $estimateId, $user);
        $hours = max(0.0, $this->decimal($_POST['director_hours'] ?? ($row['gip_hours'] ?? 0)));
        $money = $this->moneyForRow($row, $hours);
        $this->db()->prepare('
            UPDATE project_labor_estimates
            SET director_hours = ?, director_days = ?, director_comment = ?, director_approved_by = ?,
                director_approved_at = CURRENT_TIMESTAMP, director_cost_thousand = ?, director_money_snapshot = ?,
                status = "director_approved", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $hours,
            round($hours / self::LABOR_HOURS_PER_DAY, 2),
            trim((string) ($_POST['director_comment'] ?? '')),
            (int) $user['id'],
            $money,
            json_encode(['hours' => $hours, 'hourly_rate' => (float) ($row['hourly_rate'] ?? 0), 'cost_thousand' => $money], JSON_UNESCAPED_UNICODE),
            $estimateId,
        ]);
        flash('success', 'Оценка утверждена директором.');
        redirect('/cost-estimates/' . $id . '#labor-' . $estimateId);
    }

    public function saveSbcIndex(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canDirectorApproveLaborEstimates($user)) {
            $this->forbidden();
        }
        (new SbcCatalogService())->saveIndex($this->db(), [
            'id' => (int) ($_POST['index_id'] ?? 0),
            'period_key' => $_POST['period_key'] ?? '',
            'label' => $_POST['label'] ?? '',
            'index_value' => $_POST['index_value'] ?? 1,
            'source_ref' => $_POST['source_ref'] ?? '',
            'source_date' => $_POST['source_date'] ?? '',
            'comment' => $_POST['comment'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ], (int) $user['id']);
        flash('success', 'Индекс СБЦ/ПИР сохранён.');
        redirect('/cost-estimates/' . $id . '#sbc-indices');
    }

    public function seedBundledSbc(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canDirectorApproveLaborEstimates($user)) {
            $this->forbidden();
        }
        $result = (new SbcCatalogService())->importBundled($this->db(), (int) $user['id']);
        flash('success', 'Встроенный СБЦ применён: +' . (int) $result['created'] . ', обновлено ' . (int) $result['updated'] . '. Индексы: +' . (int) ($result['indices']['created'] ?? 0) . ', обновлено ' . (int) ($result['indices']['updated'] ?? 0) . '.');
        redirect('/cost-estimates/' . $id . '#sbc-indices');
    }

    public function convertToProject(int $id): void
    {
        $user = require_auth();
        if (!PermissionService::canManagePreprojects($user)) {
            $this->forbidden();
        }
        if (!$this->preproject($id, $user)) {
            $this->notFound();
        }

        $gipId = ($_POST['gip_user_id'] ?? '') !== '' ? (int) $_POST['gip_user_id'] : null;
        $rpId = ($_POST['rp_user_id'] ?? '') !== '' ? (int) $_POST['rp_user_id'] : null;
        $stage = trim((string) ($_POST['stage'] ?? 'РД'));
        $cost = max(0, (float) ($_POST['budget_cost_thousand'] ?? -1));
        $profit = max(0, (float) ($_POST['budget_profit_thousand'] ?? -1));
        $bonus = max(0, (float) ($_POST['budget_bonus_thousand'] ?? -1));
        $budget = $cost + $profit + $bonus;
        if (!$gipId || !$rpId || $budget <= 0 || !in_array($stage, ['ПД', 'РД', 'ПД-РД', 'АН'], true)) {
            flash('error', 'Для перевода в проект укажите ГИПа, РП, стадию и бюджет больше нуля.');
            redirect('/cost-estimates/' . $id);
        }

        $this->db()->prepare('
            UPDATE projects
            SET kind = "project", stage = ?, budget_manual_thousand = ?, budget_cost_thousand = ?, budget_profit_thousand = ?, budget_bonus_thousand = ?, gip_user_id = ?, rp_user_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND COALESCE(kind, "project") = "preproject"
        ')->execute([$stage, $budget, $cost, $profit, $bonus, $gipId, $rpId, $id]);

        (new ProjectManagementTaskService($this->db()))->ensure($id, $gipId, (int) $user['id'], $rpId);
        ActivityLogService::recordProject($id, (int) $user['id'], 'project.updated', 'Предпроект переведён в проект', 'ГИП и РП назначены, создана задача управления проектом.');

        flash('success', 'Предпроект переведён в проект.');
        redirect('/projects/' . $id);
    }

    public function rates(): void
    {
        $user = require_auth();
        if (!PermissionService::canManageEmployeeRates($user)) {
            $this->forbidden();
        }

        $this->render('cost_estimates/rates', [
            'title' => 'Ставки сотрудников',
            'users' => $this->usersWithRates(),
        ]);
    }

    public function updateRate(): void
    {
        $user = require_auth();
        if (!PermissionService::canManageEmployeeRates($user)) {
            $this->forbidden();
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $rate = $this->decimal($_POST['hourly_rate'] ?? 0);
        if ($userId <= 0 || $rate < 0) {
            flash('error', 'Укажите сотрудника и ставку.');
            redirect('/cost-estimates/rates');
        }

        $driver = (string) config('db.connection');
        if ($driver === 'sqlite') {
            $this->db()->prepare('
                INSERT INTO employee_rates (user_id, hourly_rate, updated_by, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(user_id) DO UPDATE SET hourly_rate = excluded.hourly_rate, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP
            ')->execute([$userId, $rate, (int) $user['id']]);
        } else {
            $this->db()->prepare('
                INSERT INTO employee_rates (user_id, hourly_rate, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
            ')->execute([$userId, $rate, (int) $user['id']]);
        }

        flash('success', 'Ставка обновлена.');
        redirect('/cost-estimates/rates');
    }

    private function preprojects(array $user): array
    {
        [$where, $params] = $this->preprojectScope($user, 'p');
        $stmt = $this->db()->prepare('
            SELECT p.*,
                   COUNT(DISTINCT s.id) AS sections_count,
                   COUNT(DISTINCT le.id) AS labor_count,
                   COALESCE(ROUND(SUM(CASE
                       WHEN le.status = "director_approved" THEN COALESCE(le.director_hours, 0)
                       WHEN le.status IN ("gip_adjusted", "gip_approved", "returned_to_gip") THEN COALESCE(le.gip_hours, 0)
                       WHEN le.status IN ("department_submitted", "returned_to_department", "submitted", "returned_to_responsible") THEN COALESCE(le.executor_hours, 0)
                       ELSE 0
                   END), 2), 0) AS labor_hours
            FROM projects p
            LEFT JOIN project_sections s ON s.project_id = p.id
            LEFT JOIN project_labor_estimates le ON le.project_id = p.id
            WHERE ' . $where . '
            GROUP BY p.id
            ORDER BY p.updated_at DESC, p.code
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function preproject(int $id, array $user): ?array
    {
        [$where, $params] = $this->preprojectScope($user, 'p');
        $stmt = $this->db()->prepare('SELECT p.* FROM projects p WHERE p.id = :id AND ' . $where . ' LIMIT 1');
        $stmt->execute(['id' => $id] + $params);

        return $stmt->fetch() ?: null;
    }

    private function preprojectScope(array $user, string $alias): array
    {
        $base = 'COALESCE(' . $alias . '.kind, "project") = "preproject" AND ' . $alias . '.status = "active"';
        if (PermissionService::canManagePreprojects($user)) {
            return [$base, []];
        }
        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            return [
                $base . ' AND EXISTS (
                    SELECT 1
                    FROM project_labor_estimates le_scope
                    INNER JOIN users executor_scope ON executor_scope.id = le_scope.executor_id
                    WHERE le_scope.project_id = ' . $alias . '.id
                      AND executor_scope.department = :department
                )',
                ['department' => (string) ($user['department'] ?? '')],
            ];
        }

        return [$base . ' AND 1=0', []];
    }

    private function sections(int $projectId): array
    {
        $stmt = $this->db()->prepare('
            SELECT s.*,
                   u.name AS assignee_name,
                   si.collection_code AS sbc_collection_code,
                   si.collection_name AS sbc_collection_name,
                   si.edition AS sbc_edition,
                   si.table_code AS sbc_table_code,
                   si.item_code AS sbc_item_code,
                   si.work_name AS sbc_work_name,
                   si.base_price AS sbc_base_price,
                   si.default_labor_hours AS sbc_default_labor_hours
            FROM project_sections s
            LEFT JOIN users u ON u.id = s.assignee_id
            LEFT JOIN sbc_items si ON si.id = s.sbc_item_id
            WHERE s.project_id = ?
            ORDER BY COALESCE(s.volume, ""), COALESCE(s.code, ""), s.id
        ');
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['sbc_reference_cost'] = $this->sbcReferenceCost($row);
        }
        unset($row);

        return $rows;
    }

    private function section(int $projectId, int $sectionId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM project_sections WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$sectionId, $projectId]);

        return $stmt->fetch() ?: null;
    }

    private function findOrCreateLaborSection(int $projectId, string $code, string $title, string $volume, int $assigneeId): array
    {
        $stmt = $this->db()->prepare('
            SELECT *
            FROM project_sections
            WHERE project_id = ?
              AND COALESCE(code, "") = ?
              AND title = ?
            LIMIT 1
        ');
        $stmt->execute([$projectId, $code, $title]);
        $section = $stmt->fetch();
        if ($section) {
            return $section;
        }

        $this->db()->prepare('
            INSERT INTO project_sections (project_id, volume, code, title, status, assignee_id)
            VALUES (?, ?, ?, ?, "draft", ?)
        ')->execute([
            $projectId,
            $volume !== '' ? $volume : null,
            $code !== '' ? $code : null,
            $title,
            $assigneeId,
        ]);

        $newId = (int) $this->db()->lastInsertId();
        $section = $this->section($projectId, $newId);
        if (!$section) {
            throw new \RuntimeException('Не удалось создать раздел строки оценки.');
        }

        return $section;
    }

    private function laborRows(int $projectId, array $user, array $filters = []): array
    {
        $where = 'le.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD]) && !PermissionService::canManagePreprojects($user)) {
            $where .= ' AND executor.department = :department';
            $params['department'] = (string) ($user['department'] ?? '');
        }
        if (!empty($filters['status'])) {
            $where .= ' AND le.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['section_id'])) {
            $where .= ' AND le.section_id = :section_id';
            $params['section_id'] = (int) $filters['section_id'];
        }
        if (!empty($filters['executor_id'])) {
            $where .= ' AND le.executor_id = :executor_id';
            $params['executor_id'] = (int) $filters['executor_id'];
        }
        if (!empty($filters['allocation_user_id'])) {
            $where .= ' AND EXISTS (
                SELECT 1 FROM project_labor_estimate_allocations alloc_filter
                WHERE alloc_filter.labor_estimate_id = le.id AND alloc_filter.user_id = :allocation_user_id
            )';
            $params['allocation_user_id'] = (int) $filters['allocation_user_id'];
        }

        $stmt = $this->db()->prepare('
            SELECT le.*,
                   s.code AS section_code,
                   s.title AS section_title,
                   s.sbc_item_id,
                   s.sbc_quantity,
                   s.sbc_stage_percent,
                   s.sbc_deflator_coeff,
                   s.sbc_adjustment_coeff,
                   COALESCE(le.sbc_quantity, s.sbc_quantity) AS labor_sbc_quantity,
                   COALESCE(le.sbc_stage_percent, s.sbc_stage_percent) AS labor_sbc_stage_percent,
                   COALESCE(le.sbc_adjustment_coeff, s.sbc_adjustment_coeff) AS labor_sbc_adjustment_coeff,
                   labor_sbc.collection_code AS labor_sbc_collection_code,
                   labor_sbc.edition AS labor_sbc_edition,
                   labor_sbc.table_code AS labor_sbc_table_code,
                   labor_sbc.item_code AS labor_sbc_item_code,
                   labor_sbc.work_name AS labor_sbc_work_name,
                   COALESCE(labor_sbc.base_price, si.base_price) AS sbc_base_price,
                   idx.label AS sbc_index_label,
                   idx.index_value AS sbc_index_value,
                   t.title AS task_title,
                   t.status AS task_status,
                   executor.name AS executor_name,
                   executor.department AS executor_department,
                   requester.name AS requested_by_name,
                   gip_user.name AS gip_approved_by_name,
                   director_user.name AS director_approved_by_name,
                   return_user.name AS returned_by_name,
                   COALESCE(staff_personal.hourly_rate, rate.hourly_rate, staff_group.hourly_rate, cfo.hourly_rate, 0) AS hourly_rate
            FROM project_labor_estimates le
            INNER JOIN project_sections s ON s.id = le.section_id
            LEFT JOIN tasks t ON t.id = le.task_id
            INNER JOIN users executor ON executor.id = le.executor_id
            LEFT JOIN users requester ON requester.id = le.requested_by
            LEFT JOIN users gip_user ON gip_user.id = le.gip_approved_by
            LEFT JOIN users director_user ON director_user.id = le.director_approved_by
            LEFT JOIN users return_user ON return_user.id = le.returned_by
            LEFT JOIN employee_rates rate ON rate.user_id = le.executor_id
            LEFT JOIN staffing_periods staff_period ON staff_period.status = \'locked\' AND substr(staff_period.month_start, 1, 7) = substr(CURRENT_DATE, 1, 7)
            LEFT JOIN staffing_personal_rates staff_personal ON staff_personal.period_id = staff_period.id AND staff_personal.user_id = le.executor_id
            LEFT JOIN staffing_group_rates staff_group ON staff_group.period_id = staff_period.id AND staff_group.department_code = executor.department
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = executor.department
            LEFT JOIN sbc_items si ON si.id = s.sbc_item_id
            LEFT JOIN sbc_items labor_sbc ON labor_sbc.id = le.sbc_item_id
            LEFT JOIN sbc_indices idx ON idx.id = le.sbc_index_id
            WHERE ' . $where . '
            ORDER BY COALESCE(s.code, ""), executor.name, le.id
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['effective_hours'] = $this->effectiveHours($row);
            $row['money_thousand'] = ((float) $row['effective_hours'] * (float) ($row['hourly_rate'] ?? 0)) / 1000;
            $row['sbc_reference_cost'] = (float) ($row['sbc_cost_snapshot'] ?? 0) > 0 ? (float) $row['sbc_cost_snapshot'] : $this->sbcReferenceCost($row);
        }
        unset($row);

        return $rows;
    }

    private function totals(array $sections, array $laborRows): array
    {
        $hours = 0.0;
        $money = 0.0;
        foreach ($laborRows as $row) {
            $hours += (float) ($row['effective_hours'] ?? 0);
            $money += (float) ($row['money_thousand'] ?? 0);
        }

        $sbc = 0.0;
        foreach ($sections as $section) {
            $sbc += (float) ($section['sbc_reference_cost'] ?? 0);
        }
        foreach ($laborRows as $row) {
            $sbc += (float) ($row['sbc_reference_cost'] ?? 0);
        }

        return [
            'sections' => count($sections),
            'labor_rows' => count($laborRows),
            'hours' => round($hours, 2),
            'money' => round($money, 2),
            'sbc' => round($sbc, 2),
            'delta' => round($money - $sbc, 2),
        ];
    }

    private function preprojectPayload(): array
    {
        $code = trim((string) ($_POST['code'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($code === '') {
            $code = 'PRE-' . date('Ymd-His');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите название предпроекта.');
        }

        return [
            'code' => mb_substr($code, 0, 20),
            'title' => $title,
            'object' => trim((string) ($_POST['object'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'object_type' => trim((string) ($_POST['object_type'] ?? '')),
            'area_m2' => $this->nullableDecimal($_POST['area_m2'] ?? ''),
            'stages_text' => trim((string) ($_POST['stages_text'] ?? '')),
            'stage' => trim((string) ($_POST['stage'] ?? 'Предпроект')) ?: 'Предпроект',
            'color' => trim((string) ($_POST['color'] ?? '#9A6A00')) ?: '#9A6A00',
            'sections_text' => trim((string) ($_POST['sections_text'] ?? '')),
        ];
    }

    private function sectionPayload(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($title === '' && $code === '') {
            throw new \InvalidArgumentException('Укажите шифр или наименование раздела.');
        }

        return [
            'volume' => trim((string) ($_POST['volume'] ?? '')),
            'code' => $code,
            'title' => $title !== '' ? $title : $code,
            'status' => trim((string) ($_POST['status'] ?? 'draft')) ?: 'draft',
            'date_start' => $this->dateOrNull($_POST['date_start'] ?? ''),
            'date_end' => $this->dateOrNull($_POST['date_end'] ?? ''),
            'assignee_id' => ($_POST['assignee_id'] ?? '') !== '' ? (int) $_POST['assignee_id'] : null,
            'sbc_item_id' => ($_POST['sbc_item_id'] ?? '') !== '' ? (int) $_POST['sbc_item_id'] : null,
            'sbc_quantity' => $this->decimal($_POST['sbc_quantity'] ?? 1, 1),
            'sbc_stage_percent' => $this->decimal($_POST['sbc_stage_percent'] ?? 100, 100),
            'sbc_deflator_coeff' => $this->decimal($_POST['sbc_deflator_coeff'] ?? 1, 1),
            'sbc_adjustment_coeff' => $this->decimal($_POST['sbc_adjustment_coeff'] ?? 1, 1),
            'sbc_comment' => trim((string) ($_POST['sbc_comment'] ?? '')),
            'comments' => trim((string) ($_POST['comments'] ?? '')),
        ];
    }

    private function laborEstimatePayload(array $preproject, array $section, int $executorId): array
    {
        return $this->laborEstimatePayloadFromInput($preproject, $section, $executorId, $_POST);
    }

    private function laborEstimatePayloadFromInput(array $preproject, array $section, int $executorId, array $input): array
    {
        $sbcCatalog = new SbcCatalogService();
        $model = new CostEstimateModelService();
        $executor = $this->userById($executorId);
        $departmentCode = trim((string) ($input['department_code'] ?? ($executor['department'] ?? $section['code'] ?? '')));
        if ($departmentCode === '') {
            $departmentCode = trim((string) ($executor['department'] ?? $section['code'] ?? ''));
        }
        $sbcItemId = ($input['sbc_item_id'] ?? '') !== '' ? (int) $input['sbc_item_id'] : null;
        $sbcItem = $sbcCatalog->find($this->db(), $sbcItemId);
        $sbcIndexId = ($input['sbc_index_id'] ?? '') !== '' ? (int) $input['sbc_index_id'] : null;
        $sbcIndex = $sbcCatalog->indexById($this->db(), $sbcIndexId);
        $quantity = max(0.0, $this->decimal($input['model_quantity'] ?? $input['sbc_quantity'] ?? 1, 1));
        $context = [
            'department_code' => $departmentCode,
            'section_code' => (string) ($section['code'] ?? ''),
            'work_title' => trim((string) ($input['work_title'] ?? $section['work_title'] ?? '')),
            'quantity' => $quantity > 0 ? $quantity : 1,
            'complexity_coeff' => $this->decimal($input['model_complexity_coeff'] ?? 1, 1),
            'typicality_coeff' => $this->decimal($input['model_typicality_coeff'] ?? 1, 1),
            'bim_coeff' => $this->decimal($input['model_bim_coeff'] ?? 1, 1),
            'urgency_coeff' => $this->decimal($input['model_urgency_coeff'] ?? 1, 1),
            'input_quality_coeff' => $this->decimal($input['model_input_quality_coeff'] ?? 1, 1),
            'sbc_default_labor_hours' => $sbcItem['default_labor_hours'] ?? 0,
        ];
        $suggestion = $model->suggestHours($this->db(), $context);
        $hours = $this->decimal($input['executor_hours'] ?? $input['hours'] ?? 0, 0);
        $sbc = $model->sbcCost($sbcCatalog, $sbcItem, $sbcIndex, [
            'quantity' => $this->decimal($input['sbc_quantity'] ?? $quantity, $quantity),
            'stage_percent' => $this->decimal($input['sbc_stage_percent'] ?? 100, 100),
            'adjustment_coeff' => $this->decimal($input['sbc_adjustment_coeff'] ?? 1, 1),
        ]);

        return [
            'department_code' => $departmentCode,
            'model_object_type' => trim((string) ($input['model_object_type'] ?? ($preproject['object_type'] ?? ''))),
            'model_stage' => trim((string) ($input['model_stage'] ?? ($preproject['stage'] ?? ''))),
            'model_area_m2' => $this->nullableDecimal($input['model_area_m2'] ?? ($preproject['area_m2'] ?? '')),
            'model_quantity' => $quantity > 0 ? $quantity : 1,
            'model_complexity_coeff' => $context['complexity_coeff'],
            'model_typicality_coeff' => $context['typicality_coeff'],
            'model_bim_coeff' => $context['bim_coeff'],
            'model_urgency_coeff' => $context['urgency_coeff'],
            'model_input_quality_coeff' => $context['input_quality_coeff'],
            'model_suggested_hours' => $suggestion['hours'],
            'model_basis' => $suggestion['basis'],
            'sbc_item_id' => $sbcItemId,
            'sbc_quantity' => $this->decimal($input['sbc_quantity'] ?? $quantity, $quantity),
            'sbc_stage_percent' => $this->decimal($input['sbc_stage_percent'] ?? 100, 100),
            'sbc_index_id' => $sbcIndexId,
            'sbc_adjustment_coeff' => $this->decimal($input['sbc_adjustment_coeff'] ?? 1, 1),
            'sbc_cost_snapshot' => $sbc['cost'],
            'sbc_basis_snapshot' => $sbc['basis'],
            'executor_hours' => $hours,
            'executor_days' => round($hours / self::LABOR_HOURS_PER_DAY, 2),
        ];
    }

    private function insertLaborEstimateRecord(int $projectId, int $sectionId, string $workTitle, string $workDescription, int $executorId, array $payload, int $requestedBy, string $status, string $comment): void
    {
        $this->db()->prepare('
            INSERT INTO project_labor_estimates (
                project_id, section_id, work_title, work_description, task_id, executor_id, department_code,
                model_object_type, model_stage, model_area_m2, model_quantity, model_complexity_coeff,
                model_typicality_coeff, model_bim_coeff, model_urgency_coeff, model_input_quality_coeff,
                model_suggested_hours, model_basis, sbc_item_id, sbc_quantity, sbc_stage_percent,
                sbc_index_id, sbc_adjustment_coeff, sbc_cost_snapshot, sbc_basis_snapshot,
                requested_by, executor_hours, executor_days, executor_comment, executor_submitted_at,
                department_submitted_by, department_submitted_at, status
            )
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)
        ')->execute([
            $projectId,
            $sectionId,
            $workTitle,
            $workDescription,
            $executorId,
            $payload['department_code'],
            $payload['model_object_type'],
            $payload['model_stage'],
            $payload['model_area_m2'],
            $payload['model_quantity'],
            $payload['model_complexity_coeff'],
            $payload['model_typicality_coeff'],
            $payload['model_bim_coeff'],
            $payload['model_urgency_coeff'],
            $payload['model_input_quality_coeff'],
            $payload['model_suggested_hours'],
            $payload['model_basis'],
            $payload['sbc_item_id'],
            $payload['sbc_quantity'],
            $payload['sbc_stage_percent'],
            $payload['sbc_index_id'],
            $payload['sbc_adjustment_coeff'],
            $payload['sbc_cost_snapshot'],
            $payload['sbc_basis_snapshot'],
            $requestedBy,
            $payload['executor_hours'],
            $payload['executor_days'],
            $comment,
            $status === 'department_submitted' ? $requestedBy : null,
            $status === 'department_submitted' ? date('Y-m-d H:i:s') : null,
            $status,
        ]);
    }

    private function laborEstimateForAction(int $projectId, int $estimateId, array $user): array
    {
        $stmt = $this->db()->prepare('
            SELECT le.*, s.code AS section_code, s.title AS section_title, executor.department AS executor_department,
                   COALESCE(staff_personal.hourly_rate, rate.hourly_rate, staff_group.hourly_rate, cfo.hourly_rate, 0) AS hourly_rate
            FROM project_labor_estimates le
            INNER JOIN project_sections s ON s.id = le.section_id
            INNER JOIN users executor ON executor.id = le.executor_id
            LEFT JOIN employee_rates rate ON rate.user_id = le.executor_id
            LEFT JOIN staffing_periods staff_period ON staff_period.status = \'locked\' AND substr(staff_period.month_start, 1, 7) = substr(CURRENT_DATE, 1, 7)
            LEFT JOIN staffing_personal_rates staff_personal ON staff_personal.period_id = staff_period.id AND staff_personal.user_id = le.executor_id
            LEFT JOIN staffing_group_rates staff_group ON staff_group.period_id = staff_period.id AND staff_group.department_code = executor.department
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = executor.department
            WHERE le.id = ? AND le.project_id = ?
            LIMIT 1
        ');
        $stmt->execute([$estimateId, $projectId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->notFound();
        }
        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD]) && !PermissionService::canManagePreprojects($user)) {
            if ((string) ($row['executor_department'] ?? '') !== (string) ($user['department'] ?? '')) {
                $this->forbidden();
            }
        }

        return $row;
    }

    private function canDepartmentEditRow(array $user, array $row): bool
    {
        if (PermissionService::canManagePreprojects($user)) {
            return true;
        }

        return RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])
            && (string) ($row['executor_department'] ?? '') === (string) ($user['department'] ?? '')
            && in_array((string) ($row['status'] ?? ''), ['draft', 'returned_to_department', 'assigned', 'returned_to_responsible'], true);
    }

    private function moneyForRow(array $row, float $hours): float
    {
        return round(($hours * (float) ($row['hourly_rate'] ?? 0)) / 1000, 2);
    }

    private function sectionsFromText(string $text): array
    {
        $sections = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.+?)\s*[—-]\s*(.+)$/u', $line, $matches)) {
                $sections[] = ['code' => trim($matches[1]), 'title' => trim($matches[2])];
                continue;
            }
            $sections[] = ['code' => '', 'title' => $line];
        }

        return $sections;
    }

    private function insertSection(int $projectId, array $source): void
    {
        $payload = [
            'volume' => (string) ($source['volume'] ?? ''),
            'code' => (string) ($source['code'] ?? ''),
            'title' => (string) ($source['title'] ?? ''),
            'status' => (string) ($source['status'] ?? 'draft'),
            'date_start' => $source['date_start'] ?? null,
            'date_end' => $source['date_end'] ?? null,
            'assignee_id' => $source['assignee_id'] ?? null,
            'sbc_item_id' => $source['sbc_item_id'] ?? null,
            'sbc_quantity' => $source['sbc_quantity'] ?? 1,
            'sbc_stage_percent' => $source['sbc_stage_percent'] ?? 100,
            'sbc_deflator_coeff' => $source['sbc_deflator_coeff'] ?? 1,
            'sbc_adjustment_coeff' => $source['sbc_adjustment_coeff'] ?? 1,
            'sbc_comment' => (string) ($source['sbc_comment'] ?? ''),
            'comments' => (string) ($source['comments'] ?? ''),
        ];
        if (trim($payload['title']) === '' && trim($payload['code']) === '') {
            return;
        }

        $this->db()->prepare('
            INSERT INTO project_sections (
                project_id, volume, code, title, status, date_start, date_end, assignee_id,
                sbc_item_id, sbc_quantity, sbc_stage_percent, sbc_deflator_coeff, sbc_adjustment_coeff,
                sbc_comment, comments
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $projectId,
            $payload['volume'],
            $payload['code'],
            $payload['title'] !== '' ? $payload['title'] : $payload['code'],
            $payload['status'],
            $payload['date_start'],
            $payload['date_end'],
            $payload['assignee_id'],
            $payload['sbc_item_id'],
            $payload['sbc_quantity'],
            $payload['sbc_stage_percent'],
            $payload['sbc_deflator_coeff'],
            $payload['sbc_adjustment_coeff'],
            $payload['sbc_comment'],
            $payload['comments'],
        ]);
    }

    private function effectiveHours(array $row): float
    {
        return match ((string) ($row['status'] ?? 'assigned')) {
            'director_approved' => (float) ($row['director_hours'] ?? 0),
            'gip_adjusted', 'gip_approved', 'returned_to_gip' => (float) ($row['gip_hours'] ?? 0),
            'department_submitted', 'returned_to_department', 'submitted', 'returned_to_responsible' => (float) ($row['executor_hours'] ?? 0),
            default => 0.0,
        };
    }

    private function sbcReferenceCost(array $row): float
    {
        $base = (float) ($row['sbc_base_price'] ?? 0);
        if ($base <= 0) {
            return 0.0;
        }

        return round(
            $base
            * (float) ($row['labor_sbc_quantity'] ?? $row['sbc_quantity'] ?? 1)
            * (float) ($row['labor_sbc_stage_percent'] ?? $row['sbc_stage_percent'] ?? 100) / 100
            * (float) ($row['sbc_index_value'] ?? $row['sbc_deflator_coeff'] ?? 1)
            * (float) ($row['labor_sbc_adjustment_coeff'] ?? $row['sbc_adjustment_coeff'] ?? 1),
            2
        );
    }

    private function laborAllocations(array $laborEstimateIds): array
    {
        $laborEstimateIds = array_values(array_unique(array_filter(array_map('intval', $laborEstimateIds))));
        if ($laborEstimateIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($laborEstimateIds), '?'));
        $stmt = $this->db()->prepare('
            SELECT a.*, u.name AS user_name, u.department AS user_department
            FROM project_labor_estimate_allocations a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.labor_estimate_id IN (' . $placeholders . ')
            ORDER BY u.department, u.name, a.id
        ');
        $stmt->execute($laborEstimateIds);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['labor_estimate_id']][] = $row;
        }

        return $map;
    }

    private function laborResponsibleTotals(array $laborRows): array
    {
        $totals = [];
        foreach ($laborRows as $row) {
            $key = (int) ($row['executor_id'] ?? 0);
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'name' => (string) ($row['executor_name'] ?? 'Не назначено'),
                    'department' => (string) ($row['executor_department'] ?? ''),
                    'rows' => 0,
                    'hours' => 0.0,
                    'days' => 0.0,
                ];
            }
            $totals[$key]['rows']++;
            $totals[$key]['hours'] += (float) ($row['effective_hours'] ?? 0);
            $totals[$key]['days'] = round($totals[$key]['hours'] / self::LABOR_HOURS_PER_DAY, 2);
        }

        return array_values($totals);
    }

    private function laborAssigneeTotals(array $laborAllocations): array
    {
        $totals = [];
        foreach ($laborAllocations as $rows) {
            foreach ($rows as $row) {
                $key = (int) ($row['user_id'] ?? 0);
                if (!isset($totals[$key])) {
                    $totals[$key] = [
                        'name' => (string) ($row['user_name'] ?? 'Не назначено'),
                        'department' => (string) ($row['user_department'] ?? ''),
                        'rows' => 0,
                        'hours' => 0.0,
                        'days' => 0.0,
                    ];
                }
                $totals[$key]['rows']++;
                $totals[$key]['hours'] += (float) ($row['hours'] ?? 0);
                $totals[$key]['days'] += (float) ($row['days'] ?? 0);
            }
        }

        return array_values($totals);
    }

    /**
     * @return array<int, true>
     */
    private function laborSectionIds(int $projectId): array
    {
        $stmt = $this->db()->prepare('SELECT DISTINCT section_id FROM project_labor_estimates WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            if ($sectionId > 0) {
                $result[$sectionId] = true;
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function postedSectionIds(int $projectId): array
    {
        $raw = $_POST['section_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare('SELECT id FROM project_sections WHERE project_id = ? AND id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$projectId], $ids));
        $allowed = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());

        return array_values(array_intersect($ids, $allowed));
    }

    private function usersWithRates(): array
    {
        return $this->db()->query('
            SELECT u.id, u.name, u.role, u.department, u.email,
                   COALESCE(r.hourly_rate, 0) AS hourly_rate,
                   r.updated_at,
                   updater.name AS updated_by_name
            FROM users u
            LEFT JOIN employee_rates r ON r.user_id = u.id
            LEFT JOIN users updater ON updater.id = r.updated_by
            WHERE u.is_active = 1
            ORDER BY u.department, u.name
        ')->fetchAll();
    }

    private function activeUsers(): array
    {
        return $this->db()->query('SELECT id, name, role, department FROM users WHERE is_active = 1 ORDER BY department, name')->fetchAll();
    }

    private function userById(int $userId): ?array
    {
        $stmt = $this->db()->prepare('SELECT id, name, role, department FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    private function departments(): array
    {
        $stmt = $this->db()->query('SELECT DISTINCT department FROM users WHERE is_active = 1 AND COALESCE(department, "") != "" ORDER BY department');

        return array_map(static fn (array $row): string => (string) $row['department'], $stmt->fetchAll());
    }

    private function sectionBelongsToPreproject(int $projectId, int $sectionId): bool
    {
        $stmt = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_sections s
            INNER JOIN projects p ON p.id = s.project_id
            WHERE s.id = ? AND s.project_id = ? AND COALESCE(p.kind, "project") = "preproject"
        ');
        $stmt->execute([$sectionId, $projectId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function userExists(int $userId): bool
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasOpenLaborEstimate(int $sectionId, int $executorId): bool
    {
        $stmt = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_labor_estimates
            WHERE section_id = ? AND executor_id = ? AND status != "director_approved"
        ');
        $stmt->execute([$sectionId, $executorId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function ensureUniqueCode(string $code, ?int $exceptId = null): void
    {
        $sql = 'SELECT COUNT(*) FROM projects WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \InvalidArgumentException('Код уже используется.');
        }
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $text = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim((string) $value));
        if ($text === '') {
            return null;
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function decimal(mixed $value, float $default = 0): float
    {
        return $this->nullableDecimal($value) ?? $default;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function notFound(): never
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Оценка не найдена', 'message' => 'Предпроект недоступен.']);
        exit;
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для оценки.']);
        exit;
    }
}
