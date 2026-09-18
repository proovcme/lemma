<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

final class ProjectTeamStructureService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*,
                   assignee.name AS assignee_name,
                   reviewer.name AS reviewer_name
            FROM project_sections s
            LEFT JOIN users assignee ON assignee.id = s.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = s.reviewer_id
            WHERE s.project_id = ?
            ORDER BY COALESCE(NULLIF(s.volume, ""), NULLIF(s.code, ""), s.title), s.code, s.id
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $projectIds
     * @return list<array<string, mixed>>
     */
    public function optionsForProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)));
        if ($projectIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT s.id, s.project_id, s.volume, s.code, s.title, s.assignee_id, s.reviewer_id, s.work_kind,
                   st.code AS stage_code, st.title AS stage_title,
                   assignee.name AS assignee_name,
                   reviewer.name AS reviewer_name
            FROM project_sections s
            LEFT JOIN users assignee ON assignee.id = s.assignee_id AND assignee.is_active = 1
            LEFT JOIN users reviewer ON reviewer.id = s.reviewer_id AND reviewer.is_active = 1
            LEFT JOIN project_stages st ON st.id = s.stage_id
            WHERE s.project_id IN (' . $placeholders . ')
              AND COALESCE(s.active, 1) = 1
              AND (COALESCE(s.code, "") <> "" OR COALESCE(s.title, "") <> "" OR COALESCE(s.volume, "") <> "")
            ORDER BY s.project_id, CASE WHEN s.work_kind = "activity" THEN 1 ELSE 0 END, COALESCE(st.sort_order, 9999), s.sort_order, s.code, s.id
        ');
        $stmt->execute($projectIds);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function assignment(int $projectId, int $sectionId): ?array
    {
        if ($projectId <= 0 || $sectionId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT s.id, s.project_id, s.volume, s.code, s.title, s.assignee_id, s.reviewer_id
            FROM project_sections s
            WHERE s.id = ? AND s.project_id = ?
            LIMIT 1
        ');
        $stmt->execute([$sectionId, $projectId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array{value:string,label:string,source:string}> */
    public function sectionOptions(int $projectId): array
    {
        $options = [];
        $global = $this->pdo->query('
            SELECT value, COALESCE(NULLIF(label, ""), value) AS label
            FROM dictionary_items
            WHERE scope_project_id = 0 AND kind = "section" AND active = 1
            ORDER BY sort_order, value
        ')->fetchAll();
        foreach ($global as $item) {
            $value = $this->normalizeSectionCode((string) ($item['value'] ?? ''));
            if ($value !== '') {
                $options[$value] = ['value' => $value, 'label' => (string) ($item['label'] ?? $value), 'source' => 'global'];
            }
        }

        $stmt = $this->pdo->prepare('
            SELECT code, title
            FROM project_sections
            WHERE project_id = ? AND COALESCE(code, "") <> ""
            ORDER BY code, title, id
        ');
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll() as $item) {
            $value = $this->normalizeSectionCode((string) ($item['code'] ?? ''));
            if ($value !== '' && !isset($options[$value])) {
                $options[$value] = ['value' => $value, 'label' => trim((string) ($item['title'] ?? '')) ?: $value, 'source' => 'project'];
            }
        }

        return array_values($options);
    }

    public function assignPerson(
        int $projectId,
        int $assigneeId,
        int $reviewerId,
        string $selectedSection,
        string $newSectionCode,
        string $newSectionTitle
    ): int {
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            throw new InvalidArgumentException('Проект не найден.');
        }
        if ($assigneeId <= 0) {
            throw new InvalidArgumentException('Выберите сотрудника.');
        }
        if ($reviewerId > 0 && $reviewerId === $assigneeId) {
            throw new InvalidArgumentException('Исполнитель и проверяющий раздела должны быть разными сотрудниками.');
        }
        $this->assertActiveUsers(array_values(array_filter([$assigneeId, $reviewerId])));

        $isNew = trim($newSectionCode) !== '';
        $sectionCode = $this->normalizeSectionCode($isNew ? $newSectionCode : $selectedSection);
        if ($sectionCode === '') {
            throw new InvalidArgumentException('Выберите раздел из списка или укажите код нового раздела.');
        }
        $sectionTitle = trim($newSectionTitle);
        if (!$isNew) {
            $option = $this->sectionOption($projectId, $sectionCode);
            if ($option === null) {
                throw new InvalidArgumentException('Выбранный раздел отсутствует в справочнике. Обновите страницу или создайте новый.');
            }
            $sectionTitle = (string) $option['label'];
        }
        $sectionTitle = mb_substr($sectionTitle !== '' ? $sectionTitle : $sectionCode, 0, 255);

        $this->pdo->beginTransaction();
        try {
            if ($isNew) {
                $this->saveGlobalSection($sectionCode, $sectionTitle);
            }
            $find = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND UPPER(code) = UPPER(?) ORDER BY id LIMIT 1');
            $find->execute([$projectId, $sectionCode]);
            $sectionId = (int) $find->fetchColumn();
            if ($sectionId > 0) {
                $this->pdo->prepare('
                    UPDATE project_sections
                    SET code = ?, title = ?, assignee_id = ?, reviewer_id = ?
                    WHERE id = ? AND project_id = ?
                ')->execute([$sectionCode, $sectionTitle, $assigneeId, $reviewerId ?: null, $sectionId, $projectId]);
            } else {
                $this->pdo->prepare('
                    INSERT INTO project_sections (project_id, code, title, status, assignee_id, reviewer_id)
                    VALUES (?, ?, ?, "active", ?, ?)
                ')->execute([$projectId, $sectionCode, $sectionTitle, $assigneeId, $reviewerId ?: null]);
                $sectionId = (int) $this->pdo->lastInsertId();
            }
            $this->ensureProjectMember($projectId, $assigneeId);
            if ($reviewerId > 0) {
                $this->ensureProjectMember($projectId, $reviewerId);
            }
            $this->pdo->commit();

            return $sectionId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function createGlobalSection(int $projectId, string $code, string $title): string
    {
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            throw new InvalidArgumentException('Проект не найден.');
        }
        $code = $this->normalizeSectionCode($code);
        if ($code === '') {
            throw new InvalidArgumentException('Укажите код нового раздела.');
        }
        $title = mb_substr(trim($title) ?: $code, 0, 255);
        $this->saveGlobalSection($code, $title);

        return $code;
    }

    /**
     * Табличная модель команды: один сотрудник выбирает один раздел и одну роль
     * в нём. Один раздел может иметь по одному исполнителю и проверяющему.
     *
     * @param list<int> $rosterUserIds все сотрудники, показанные в таблице
     * @param list<int> $selectedUserIds отмеченные участники проекта
     * @param array<int|string, mixed> $sectionCodes раздел по user_id
     * @param array<int|string, mixed> $sectionRoles executor|reviewer по user_id
     * @param array<int|string, mixed> $previousSectionCodes прежний раздел строки по user_id
     * @param array<int|string, mixed> $previousSectionRoles прежняя роль строки по user_id
     */
    public function syncRosterAssignments(
        int $projectId,
        array $rosterUserIds,
        array $selectedUserIds,
        array $sectionCodes,
        array $sectionRoles,
        array $previousSectionCodes = [],
        array $previousSectionRoles = []
    ): int {
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            throw new InvalidArgumentException('Проект не найден.');
        }
        $rosterUserIds = array_values(array_unique(array_filter(array_map('intval', $rosterUserIds), static fn (int $id): bool => $id > 0)));
        $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $selectedUserIds), static fn (int $id): bool => $id > 0)));
        $this->assertActiveUsers($selectedUserIds);

        $availableSections = [];
        foreach ($this->sectionOptions($projectId) as $option) {
            $availableSections[(string) $option['value']] = (string) $option['label'];
        }

        /** @var array<string, array{title:string,executor:?int,reviewer:?int}> $assignments */
        $assignments = [];
        foreach ($selectedUserIds as $userId) {
            $code = $this->normalizeSectionCode((string) ($sectionCodes[$userId] ?? ''));
            $role = trim((string) ($sectionRoles[$userId] ?? ''));
            if ($code === '' && $role === '') {
                continue;
            }
            if ($code === '' || !in_array($role, ['executor', 'reviewer'], true)) {
                throw new InvalidArgumentException('Для отмеченного сотрудника выберите и раздел, и роль в разделе.');
            }
            if (!isset($availableSections[$code])) {
                throw new InvalidArgumentException('Раздел «' . $code . '» отсутствует в справочнике. Создайте его над таблицей и повторите сохранение.');
            }
            $assignments[$code] ??= ['title' => $availableSections[$code], 'executor' => null, 'reviewer' => null];
            if ($assignments[$code][$role] !== null) {
                $roleLabel = $role === 'executor' ? 'исполнитель' : 'проверяющий';
                throw new InvalidArgumentException('Для раздела «' . $code . '» уже выбран ' . $roleLabel . '. Оставьте одного сотрудника на эту роль.');
            }
            $assignments[$code][$role] = $userId;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($rosterUserIds !== [] && ($previousSectionCodes !== [] || $previousSectionRoles !== [])) {
                $clearExecutor = $this->pdo->prepare('UPDATE project_sections SET assignee_id = NULL WHERE project_id = ? AND UPPER(code) = UPPER(?) AND assignee_id = ?');
                $clearReviewer = $this->pdo->prepare('UPDATE project_sections SET reviewer_id = NULL WHERE project_id = ? AND UPPER(code) = UPPER(?) AND reviewer_id = ?');
                foreach ($rosterUserIds as $userId) {
                    $previousCode = $this->normalizeSectionCode((string) ($previousSectionCodes[$userId] ?? ''));
                    $previousRole = (string) ($previousSectionRoles[$userId] ?? '');
                    if ($previousCode === '' || !in_array($previousRole, ['executor', 'reviewer'], true)) {
                        continue;
                    }
                    ($previousRole === 'executor' ? $clearExecutor : $clearReviewer)->execute([$projectId, $previousCode, $userId]);
                }
            } elseif ($rosterUserIds !== []) {
                $placeholders = implode(',', array_fill(0, count($rosterUserIds), '?'));
                $this->pdo->prepare('UPDATE project_sections SET assignee_id = NULL WHERE project_id = ? AND assignee_id IN (' . $placeholders . ')')
                    ->execute([$projectId, ...$rosterUserIds]);
                $this->pdo->prepare('UPDATE project_sections SET reviewer_id = NULL WHERE project_id = ? AND reviewer_id IN (' . $placeholders . ')')
                    ->execute([$projectId, ...$rosterUserIds]);
            }

            $find = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND UPPER(code) = UPPER(?) ORDER BY id LIMIT 1');
            $updateIdentity = $this->pdo->prepare('UPDATE project_sections SET code = ?, title = ? WHERE id = ? AND project_id = ?');
            $updateExecutor = $this->pdo->prepare('UPDATE project_sections SET assignee_id = ? WHERE id = ? AND project_id = ?');
            $updateReviewer = $this->pdo->prepare('UPDATE project_sections SET reviewer_id = ? WHERE id = ? AND project_id = ?');
            $insert = $this->pdo->prepare('INSERT INTO project_sections (project_id, code, title, status, assignee_id, reviewer_id) VALUES (?, ?, ?, "active", ?, ?)');
            foreach ($assignments as $code => $assignment) {
                $find->execute([$projectId, $code]);
                $sectionId = (int) $find->fetchColumn();
                if ($sectionId > 0) {
                    $updateIdentity->execute([$code, $assignment['title'], $sectionId, $projectId]);
                    if ($assignment['executor'] !== null) {
                        $updateExecutor->execute([$assignment['executor'], $sectionId, $projectId]);
                    }
                    if ($assignment['reviewer'] !== null) {
                        $updateReviewer->execute([$assignment['reviewer'], $sectionId, $projectId]);
                    }
                } else {
                    $insert->execute([$projectId, $code, $assignment['title'], $assignment['executor'], $assignment['reviewer']]);
                }
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

    /**
     * @param list<int> $sectionIds
     * @param array<int|string, mixed> $assigneeIds
     * @param array<int|string, mixed> $reviewerIds
     */
    public function sync(int $projectId, array $sectionIds, array $assigneeIds, array $reviewerIds): int
    {
        $sectionIds = array_values(array_unique(array_filter(array_map('intval', $sectionIds), static fn (int $id): bool => $id > 0)));
        if ($sectionIds === []) {
            throw new InvalidArgumentException('В проекте нет разделов для назначения команды.');
        }
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $sections = $this->pdo->prepare('SELECT id FROM project_sections WHERE project_id = ? AND id IN (' . $placeholders . ')');
        $sections->execute([$projectId, ...$sectionIds]);
        $validSectionIds = array_map('intval', $sections->fetchAll(PDO::FETCH_COLUMN));
        sort($validSectionIds);
        $expectedSectionIds = $sectionIds;
        sort($expectedSectionIds);
        if ($validSectionIds !== $expectedSectionIds) {
            throw new InvalidArgumentException('Один из выбранных разделов не относится к этому проекту.');
        }

        $assignments = [];
        $userIds = [];
        foreach ($sectionIds as $sectionId) {
            $assigneeId = (int) ($assigneeIds[$sectionId] ?? 0);
            $reviewerId = (int) ($reviewerIds[$sectionId] ?? 0);
            if ($assigneeId > 0 && $assigneeId === $reviewerId) {
                throw new InvalidArgumentException('Исполнитель и проверяющий раздела должны быть разными сотрудниками.');
            }
            $assignments[$sectionId] = [$assigneeId ?: null, $reviewerId ?: null];
            if ($assigneeId > 0) {
                $userIds[] = $assigneeId;
            }
            if ($reviewerId > 0) {
                $userIds[] = $reviewerId;
            }
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds !== []) {
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
            $users = $this->pdo->prepare('SELECT id FROM users WHERE is_active = 1 AND id IN (' . $userPlaceholders . ')');
            $users->execute($userIds);
            $activeIds = array_map('intval', $users->fetchAll(PDO::FETCH_COLUMN));
            sort($activeIds);
            $expectedUserIds = $userIds;
            sort($expectedUserIds);
            if ($activeIds !== $expectedUserIds) {
                throw new InvalidArgumentException('Исполнителем или проверяющим можно назначить только действующего сотрудника.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE project_sections SET assignee_id = ?, reviewer_id = ? WHERE id = ? AND project_id = ?');
            foreach ($assignments as $sectionId => [$assigneeId, $reviewerId]) {
                $update->execute([$assigneeId, $reviewerId, $sectionId, $projectId]);
                foreach (array_filter([$assigneeId, $reviewerId]) as $userId) {
                    $this->ensureProjectMember($projectId, (int) $userId);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return count($assignments);
    }

    private function ensureProjectMember(int $projectId, int $userId): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare('
                INSERT INTO project_members (project_id, user_id, project_role, active)
                VALUES (?, ?, "Участник раздела", 1)
                ON CONFLICT(project_id, user_id) DO UPDATE SET
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO project_members (project_id, user_id, project_role, active)
                VALUES (?, ?, "Участник раздела", 1)
                ON DUPLICATE KEY UPDATE
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ');
        }
        $stmt->execute([$projectId, $userId]);
    }

    /** @return array{value:string,label:string}|null */
    private function sectionOption(int $projectId, string $sectionCode): ?array
    {
        foreach ($this->sectionOptions($projectId) as $option) {
            if ((string) $option['value'] === $sectionCode) {
                return ['value' => (string) $option['value'], 'label' => (string) $option['label']];
            }
        }

        return null;
    }

    private function saveGlobalSection(string $code, string $title): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare('
                INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
                VALUES (NULL, 0, "section", ?, ?, ?, 1, 900)
                ON CONFLICT(scope_project_id, kind, value) DO UPDATE SET
                    label = excluded.label,
                    discipline = excluded.discipline,
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
                VALUES (NULL, 0, "section", ?, ?, ?, 1, 900)
                ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    discipline = VALUES(discipline),
                    active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ');
        }
        $stmt->execute([$code, $title, $code]);
    }

    /** @param list<int> $userIds */
    private function assertActiveUsers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE is_active = 1 AND id IN (' . $placeholders . ')');
        $stmt->execute($userIds);
        $activeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($activeIds);
        sort($userIds);
        if ($activeIds !== $userIds) {
            throw new InvalidArgumentException('Назначить можно только действующего сотрудника.');
        }
    }

    private function projectExists(int $projectId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);

        return (bool) $stmt->fetchColumn();
    }

    private function normalizeSectionCode(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_substr(mb_strtoupper($value, 'UTF-8'), 0, 100);
    }
}
