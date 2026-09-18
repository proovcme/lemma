<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

final class ProjectStructureService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{stages:list<array<string,mixed>>,templates:array<string,list<array<string,mixed>>>,sections:list<array<string,mixed>>,activities:list<array<string,mixed>>} */
    public function catalog(): array
    {
        return [
            'stages' => $this->dictionary('project_stage'),
            'templates' => [
                'pp87' => $this->dictionary('section_pp87'),
                'rd' => $this->dictionary('section_rd'),
            ],
            'sections' => $this->dictionary('section'),
            'activities' => $this->dictionary('project_activity'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function structure(int $projectId): array
    {
        $stages = $this->pdo->prepare('SELECT * FROM project_stages WHERE project_id = ? AND active = 1 ORDER BY sort_order, id');
        $stages->execute([$projectId]);
        $rows = $stages->fetchAll();

        $sections = $this->pdo->prepare('SELECT s.*, st.code AS stage_code, st.title AS stage_title
            FROM project_sections s
            LEFT JOIN project_stages st ON st.id = s.stage_id
            WHERE s.project_id = ? AND COALESCE(s.active, 1) = 1
            ORDER BY CASE WHEN s.work_kind = "activity" THEN 1 ELSE 0 END, COALESCE(st.sort_order, 9999), s.sort_order, s.code, s.id');
        $sections->execute([$projectId]);
        $sectionRows = $sections->fetchAll();
        $assignments = $this->assignmentsForSections(array_map(static fn (array $row): int => (int) $row['id'], $sectionRows));

        $byStage = [];
        foreach ($rows as $stage) {
            $stage['sections'] = [];
            $byStage[(int) $stage['id']] = $stage;
        }
        $activities = [];
        $unassignedSections = [];
        foreach ($sectionRows as $section) {
            $section['executors'] = $assignments[(int) $section['id']]['executor'] ?? [];
            $section['reviewers'] = $assignments[(int) $section['id']]['reviewer'] ?? [];
            if ((string) ($section['work_kind'] ?? 'section') === 'activity') {
                $activities[] = $section;
                continue;
            }
            $stageId = (int) ($section['stage_id'] ?? 0);
            if (isset($byStage[$stageId])) {
                $byStage[$stageId]['sections'][] = $section;
            } else {
                $unassignedSections[] = $section;
            }
        }

        $result = array_values($byStage);
        if ($unassignedSections !== []) {
            $result[] = ['id' => -1, 'code' => 'БЕЗ СТАДИИ', 'title' => 'Разделы, созданные до структуры проекта', 'is_legacy_group' => 1, 'sections' => $unassignedSections];
        }
        $result[] = ['id' => 0, 'code' => 'ОБЩИЕ', 'title' => 'Общие активности', 'is_activity_group' => 1, 'sections' => $activities];

        return $result;
    }

    /**
     * The project team table is a department roster, not a projection of an
     * already-created project structure. This keeps empty projects usable and
     * makes management departments such as ГИП and ТИМ first-class rows.
     *
     * @return list<array{code:string,title:string,section_ids:list<int>,executors:list<array<string,mixed>>,reviewer:?array<string,mixed>}>
     */
    public function departmentTeamRows(int $projectId): array
    {
        $departments = $this->pdo->query('SELECT code, name FROM departments ORDER BY code')->fetchAll();
        $stmt = $this->pdo->prepare('SELECT id, code FROM project_sections WHERE project_id = ? AND COALESCE(active, 1) = 1 ORDER BY id');
        $stmt->execute([$projectId]);
        $sectionsByCode = [];
        foreach ($stmt->fetchAll() as $section) {
            $code = $this->normalizeCode((string) ($section['code'] ?? ''));
            if ($code !== '') {
                $sectionsByCode[$code][] = (int) $section['id'];
            }
        }
        $allSectionIds = array_merge(...array_values($sectionsByCode ?: [[]]));
        $assignments = $this->assignmentsForSections($allSectionIds);

        $rows = [];
        foreach ($departments as $department) {
            $code = $this->normalizeCode((string) ($department['code'] ?? ''));
            if ($code === '') continue;
            $sectionIds = $sectionsByCode[$code] ?? [];
            $executorsById = [];
            $reviewersById = [];
            foreach ($sectionIds as $sectionId) {
                foreach ($assignments[$sectionId]['executor'] ?? [] as $person) {
                    $executorsById[(int) $person['user_id']] = $person;
                }
                foreach ($assignments[$sectionId]['reviewer'] ?? [] as $person) {
                    $reviewersById[(int) $person['user_id']] = $person;
                }
            }
            $rows[] = [
                'code' => $code,
                'title' => (string) ($department['name'] ?? $code),
                'section_ids' => $sectionIds,
                'executors' => array_values($executorsById),
                'reviewer' => array_values($reviewersById)[0] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * @param list<string> $departmentCodes
     * @param array<string,mixed> $executorIdsByCode
     * @param array<string,mixed> $reviewerIdByCode
     */
    public function syncDepartmentTeamTable(int $projectId, array $departmentCodes, array $executorIdsByCode, array $reviewerIdByCode): int
    {
        $departments = [];
        foreach ($this->pdo->query('SELECT code, name FROM departments ORDER BY code')->fetchAll() as $department) {
            $code = $this->normalizeCode((string) ($department['code'] ?? ''));
            if ($code !== '') $departments[$code] = $department;
        }
        $submittedCodes = $this->stringList($departmentCodes);
        $expectedCodes = array_keys($departments);
        sort($submittedCodes);
        sort($expectedCodes);
        if ($submittedCodes !== $expectedCodes) {
            throw new InvalidArgumentException('Состав разделов изменился. Обновите страницу и сохраните таблицу ещё раз.');
        }

        $assignmentsByCode = [];
        $allUserIds = [];
        foreach ($expectedCodes as $code) {
            $executorIds = $this->intList((array) ($executorIdsByCode[$code] ?? []));
            $reviewerIds = $this->intList((array) ($reviewerIdByCode[$code] ?? []));
            if (count($reviewerIds) > 1) {
                throw new InvalidArgumentException('Для одного раздела можно выбрать только одного проверяющего.');
            }
            if (array_intersect($executorIds, $reviewerIds) !== []) {
                throw new InvalidArgumentException('Один человек не может одновременно разрабатывать и проверять один раздел.');
            }
            $assignmentsByCode[$code] = ['executor' => $executorIds, 'reviewer' => $reviewerIds];
            $allUserIds = [...$allUserIds, ...$executorIds, ...$reviewerIds];
        }
        $this->assertActiveUsers(array_values(array_unique($allUserIds)));

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $sections = $this->pdo->prepare('SELECT id, code FROM project_sections WHERE project_id = ? AND COALESCE(active, 1) = 1 ORDER BY id');
            $sections->execute([$projectId]);
            $sectionIdsByCode = [];
            foreach ($sections->fetchAll() as $section) {
                $code = $this->normalizeCode((string) ($section['code'] ?? ''));
                if (isset($departments[$code])) $sectionIdsByCode[$code][] = (int) $section['id'];
            }

            foreach ($expectedCodes as $index => $code) {
                if (($sectionIdsByCode[$code] ?? []) === []) {
                    $department = $departments[$code];
                    $sectionIdsByCode[$code] = [$this->upsertSection(
                        $projectId,
                        null,
                        'section',
                        $code,
                        (string) ($department['name'] ?? $code),
                        ($index + 1) * 10
                    )];
                }
                foreach ($sectionIdsByCode[$code] as $sectionId) {
                    $roles = $assignmentsByCode[$code];
                    $this->replaceAssignments($projectId, $sectionId, $roles['executor'], $roles['reviewer']);
                }
            }
            if ($ownsTransaction) $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return count($expectedCodes);
    }

    /** @param array<string,mixed> $input */
    public function createForProject(int $projectId, array $input): void
    {
        $stageCodes = $this->stringList($input['stage_codes'] ?? []);
        if ($stageCodes === []) {
            throw new InvalidArgumentException('Выберите хотя бы одну стадию проекта.');
        }
        $templates = is_array($input['stage_templates'] ?? null) ? $input['stage_templates'] : [];
        $activityCodes = $this->stringList($input['activity_codes'] ?? ['ТИМ', 'УПРАВЛЕНИЕ']);
        $catalog = $this->catalog();
        $stageCatalog = $this->keyByValue($catalog['stages']);
        $activityCatalog = $this->keyByValue($catalog['activities']);

        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($stageCodes as $index => $code) {
                if (!isset($stageCatalog[$code])) {
                    throw new InvalidArgumentException('Стадия «' . $code . '» отсутствует в справочнике.');
                }
                $stageId = $this->upsertStage($projectId, $code, (string) $stageCatalog[$code]['label'], ($index + 1) * 10);
                $template = in_array((string) ($templates[$code] ?? ''), ['pp87', 'rd'], true) ? (string) $templates[$code] : '';
                if ($template !== '') {
                    foreach ($catalog['templates'][$template] as $sectionIndex => $section) {
                        $this->upsertSection($projectId, $stageId, 'section', (string) $section['value'], (string) $section['label'], ($sectionIndex + 1) * 10);
                    }
                }
            }
            foreach ($activityCodes as $index => $code) {
                if (!isset($activityCatalog[$code])) {
                    throw new InvalidArgumentException('Общая активность «' . $code . '» отсутствует в справочнике.');
                }
                $this->upsertSection($projectId, null, 'activity', $code, (string) $activityCatalog[$code]['label'], ($index + 1) * 10);
            }
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function addStage(int $projectId, string $code, string $title, bool $saveToCatalog): int
    {
        $code = $this->normalizeCode($code);
        $title = mb_substr(trim($title) ?: $code, 0, 255);
        if ($code === '') {
            throw new InvalidArgumentException('Укажите код стадии.');
        }
        if ($saveToCatalog) {
            $this->saveDictionary('project_stage', $code, $title);
        }
        return $this->upsertStage($projectId, $code, $title, $this->nextSort('project_stages', $projectId));
    }

    public function addWorkItem(int $projectId, ?int $stageId, string $kind, string $code, string $title, bool $saveToCatalog): int
    {
        $kind = $kind === 'activity' ? 'activity' : 'section';
        $code = $this->normalizeCode($code);
        $title = mb_substr(trim($title) ?: $code, 0, 255);
        if ($code === '') {
            throw new InvalidArgumentException('Укажите код раздела или активности.');
        }
        if ($kind === 'section') {
            if ((int) $stageId > 0) {
                $this->assertStage($projectId, (int) $stageId);
            } else {
                $stageId = null;
            }
        } else {
            $stageId = null;
        }
        if ($saveToCatalog) {
            $this->saveDictionary($kind === 'activity' ? 'project_activity' : 'section', $code, $title);
        }
        return $this->upsertSection($projectId, $stageId, $kind, $code, $title, $this->nextSectionSort($projectId, $stageId, $kind));
    }

    /** @param list<int|string> $executorIds @param list<int|string> $reviewerIds */
    public function addWorkItemWithAssignments(int $projectId, ?int $stageId, string $kind, string $code, string $title, bool $saveToCatalog, array $executorIds, array $reviewerIds): int
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $sectionId = $this->addWorkItem($projectId, $stageId, $kind, $code, $title, $saveToCatalog);
            $this->syncAssignments($projectId, $sectionId, $executorIds, $reviewerIds);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $sectionId;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int|string> $executorIds @param list<int|string> $reviewerIds */
    public function syncAssignments(int $projectId, int $sectionId, array $executorIds, array $reviewerIds): void
    {
        $this->syncAssignmentTable($projectId, [$sectionId], [$sectionId => $executorIds], [$sectionId => $reviewerIds]);
    }

    /**
     * @param list<int|string> $sectionIds
     * @param array<int|string,mixed> $executorIdsBySection
     * @param array<int|string,mixed> $reviewerIdsBySection
     */
    public function syncAssignmentTable(int $projectId, array $sectionIds, array $executorIdsBySection, array $reviewerIdsBySection): int
    {
        $sectionIds = $this->intList($sectionIds);
        if ($sectionIds === []) {
            throw new InvalidArgumentException('В проекте нет разделов для сохранения команды.');
        }
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND COALESCE(active, 1) = 1 AND id IN (' . $placeholders . ')');
        $stmt->execute([$projectId, ...$sectionIds]);
        $actualSectionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($actualSectionIds);
        $expectedSectionIds = $sectionIds;
        sort($expectedSectionIds);
        if ($actualSectionIds !== $expectedSectionIds) {
            throw new InvalidArgumentException('Один из разделов таблицы не относится к этому проекту.');
        }

        $assignments = [];
        $allUserIds = [];
        foreach ($sectionIds as $sectionId) {
            $executorIds = $this->intList((array) ($executorIdsBySection[$sectionId] ?? []));
            $reviewerIds = $this->intList((array) ($reviewerIdsBySection[$sectionId] ?? []));
            if (array_intersect($executorIds, $reviewerIds) !== []) {
                throw new InvalidArgumentException('Один человек не может одновременно разрабатывать и проверять один раздел.');
            }
            $assignments[$sectionId] = ['executor' => $executorIds, 'reviewer' => $reviewerIds];
            $allUserIds = [...$allUserIds, ...$executorIds, ...$reviewerIds];
        }
        $this->assertActiveUsers(array_values(array_unique($allUserIds)));

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($assignments as $sectionId => $roles) {
                $this->replaceAssignments($projectId, $sectionId, $roles['executor'], $roles['reviewer']);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return count($assignments);
    }

    /** @param list<int> $executorIds @param list<int> $reviewerIds */
    private function replaceAssignments(int $projectId, int $sectionId, array $executorIds, array $reviewerIds): void
    {
        $this->pdo->prepare('DELETE FROM project_section_assignments WHERE project_section_id = ?')->execute([$sectionId]);
        $insert = $this->pdo->prepare('INSERT INTO project_section_assignments (project_section_id, user_id, assignment_role, sort_order) VALUES (?, ?, ?, ?)');
        foreach (['executor' => $executorIds, 'reviewer' => $reviewerIds] as $role => $ids) {
            foreach ($ids as $index => $userId) {
                $insert->execute([$sectionId, $userId, $role, ($index + 1) * 10]);
                $this->ensureProjectMember($projectId, $userId);
            }
        }
        $this->pdo->prepare('UPDATE project_sections SET assignee_id = ?, reviewer_id = ? WHERE id = ? AND project_id = ?')
            ->execute([$executorIds[0] ?? null, $reviewerIds[0] ?? null, $sectionId, $projectId]);
    }

    public function managementActivityId(int $projectId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND work_kind = "activity" AND UPPER(code) = "УПРАВЛЕНИЕ" AND COALESCE(active, 1) = 1 LIMIT 1');
        $stmt->execute([$projectId]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    /** @return list<array<string,mixed>> */
    private function dictionary(string $kind): array
    {
        $stmt = $this->pdo->prepare('SELECT value, COALESCE(NULLIF(label, ""), value) AS label, sort_order FROM dictionary_items WHERE scope_project_id = 0 AND kind = ? AND active = 1 ORDER BY sort_order, value');
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    }

    /** @param list<int> $sectionIds @return array<int,array<string,list<array<string,mixed>>>> */
    private function assignmentsForSections(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT a.project_section_id, a.assignment_role, a.user_id, u.name, u.department
            FROM project_section_assignments a INNER JOIN users u ON u.id = a.user_id
            WHERE a.project_section_id IN (' . implode(',', array_fill(0, count($sectionIds), '?')) . ')
            ORDER BY a.assignment_role, a.sort_order, u.name');
        $stmt->execute($sectionIds);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['project_section_id']][(string) $row['assignment_role']][] = $row;
        }
        return $result;
    }

    private function upsertStage(int $projectId, string $code, string $title, int $sort): int
    {
        $find = $this->pdo->prepare('SELECT id FROM project_stages WHERE project_id = ? AND UPPER(code) = UPPER(?) LIMIT 1');
        $find->execute([$projectId, $code]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $this->pdo->prepare('UPDATE project_stages SET title = ?, sort_order = ?, active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$title, $sort, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO project_stages (project_id, code, title, sort_order, active) VALUES (?, ?, ?, ?, 1)')->execute([$projectId, $code, $title, $sort]);
        return (int) $this->pdo->lastInsertId();
    }

    private function upsertSection(int $projectId, ?int $stageId, string $kind, string $code, string $title, int $sort): int
    {
        $find = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND COALESCE(stage_id, 0) = ? AND work_kind = ? AND UPPER(code) = UPPER(?) LIMIT 1');
        $find->execute([$projectId, (int) $stageId, $kind, $code]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $this->pdo->prepare('UPDATE project_sections SET title = ?, sort_order = ?, active = 1 WHERE id = ?')->execute([$title, $sort, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO project_sections (project_id, stage_id, work_kind, sort_order, active, code, title, status) VALUES (?, ?, ?, ?, 1, ?, ?, "active")')
            ->execute([$projectId, $stageId, $kind, $sort, $code, $title]);
        return (int) $this->pdo->lastInsertId();
    }

    private function saveDictionary(string $kind, string $code, string $title): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = 'INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, active, sort_order) VALUES (NULL, 0, ?, ?, ?, 1, 900)
                ON CONFLICT(scope_project_id, kind, value) DO UPDATE SET label = excluded.label, active = 1, updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, active, sort_order) VALUES (NULL, 0, ?, ?, ?, 1, 900)
                ON DUPLICATE KEY UPDATE label = VALUES(label), active = 1, updated_at = CURRENT_TIMESTAMP';
        }
        $this->pdo->prepare($sql)->execute([$kind, $code, $title]);
    }

    private function ensureProjectMember(int $projectId, int $userId): void
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO project_members (project_id, user_id, project_role, active) VALUES (?, ?, "Участник структуры", 1) ON CONFLICT(project_id, user_id) DO UPDATE SET active = 1, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO project_members (project_id, user_id, project_role, active) VALUES (?, ?, "Участник структуры", 1) ON DUPLICATE KEY UPDATE active = 1, updated_at = CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([$projectId, $userId]);
    }

    private function assertStage(int $projectId, int $stageId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM project_stages WHERE id = ? AND project_id = ? AND active = 1');
        $stmt->execute([$stageId, $projectId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Выбранная стадия не относится к проекту.');
        }
    }

    private function assertSection(int $projectId, int $sectionId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM project_sections WHERE id = ? AND project_id = ? AND COALESCE(active, 1) = 1');
        $stmt->execute([$sectionId, $projectId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Выбранный раздел не относится к проекту.');
        }
    }

    /** @param list<int> $ids */
    private function assertActiveUsers(array $ids): void
    {
        if ($ids === []) return;
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $actual = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($actual); sort($ids);
        if ($actual !== $ids) throw new InvalidArgumentException('Назначить можно только действующих сотрудников.');
    }

    private function nextSort(string $table, int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . $table . ' WHERE project_id = ?');
        $stmt->execute([$projectId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextSectionSort(int $projectId, ?int $stageId, string $kind): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM project_sections WHERE project_id = ? AND COALESCE(stage_id, 0) = ? AND work_kind = ?');
        $stmt->execute([$projectId, (int) $stageId, $kind]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function keyByValue(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) $result[(string) $row['value']] = $row;
        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_unique(array_filter(array_map(fn ($v): string => $this->normalizeCode((string) $v), $values))));
    }

    /** @return list<int> */
    private function intList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
    }

    private function normalizeCode(string $value): string
    {
        return mb_substr(mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)) ?? '', 'UTF-8'), 0, 120);
    }
}
