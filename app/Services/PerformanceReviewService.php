<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PerformanceReviewService
{
    public const ANNUAL_TEMPLATE_TITLE = 'Ежегодный Performance Review';

    public const QUESTION_TYPES = [
        'text' => 'Короткий текст',
        'textarea' => 'Развёрнутый ответ',
        'rating_1_5' => 'Оценка 1-5',
        'yes_no' => 'Да/нет',
    ];

    public const QUESTION_SCOPES = [
        'both' => 'Все стадии',
        'self' => 'Самооценка',
        'manager' => 'Оценка руководителя',
        'hr' => 'Комментарий HR',
    ];

    public const REVIEW_STATUSES = [
        'draft' => 'Черновик',
        'self_review' => 'Самооценка',
        'manager_review' => 'Оценка руководителя',
        'hr_review' => 'Очная встреча',
        'closed' => 'Закрыто',
        'cancelled' => 'Отменено',
    ];

    public const CYCLE_KINDS = [
        'annual' => 'Официальный',
        'test' => 'Тестовый',
    ];

    public const CYCLE_STATUSES = [
        'draft' => 'Черновик',
        'active' => 'Идёт',
        'closed' => 'Заполнение закрыто',
        'cancelled' => 'Отменён',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function seedDefaults(): void
    {
        // Previous upgrades could leave more than one copy of the retired
        // built-in template. Keep history intact, but never expose any of
        // those copies as a template for a new cycle.
        $this->pdo->prepare('UPDATE performance_review_templates SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE title = ? AND is_active <> 0')
            ->execute(['Performance Review v1']);
        $this->seedAnnualTemplate();
    }

    public function dashboard(array $user): array
    {
        $this->seedDefaults();
        $reviews = PermissionService::canManagePerformanceReviews($user)
            ? $this->allReviews()
            : $this->reviewsForUser($user);
        $counts = array_fill_keys(array_keys(self::REVIEW_STATUSES), 0);
        foreach ($reviews as $review) {
            $status = (string) ($review['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $cycles = PermissionService::canManagePerformanceReviews($user)
            ? $this->pdo->query('
                SELECT c.*, t.title AS template_title,
                       COUNT(r.id) AS review_count,
                       SUM(CASE WHEN r.status = "closed" THEN 1 ELSE 0 END) AS closed_count,
                       SUM(CASE WHEN r.status = "draft" THEN 1 ELSE 0 END) AS pending_count,
                       SUM(CASE WHEN r.status <> "draft" THEN 1 ELSE 0 END) AS launched_count,
                       COUNT(DISTINCT CASE WHEN r.launch_batch_no IS NOT NULL THEN r.launch_batch_no END) AS batch_count
                FROM performance_review_cycles c
                JOIN performance_review_templates t ON t.id = c.template_id
                LEFT JOIN performance_reviews r ON r.cycle_id = c.id
                GROUP BY c.id
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT 20
            ')->fetchAll()
            : [];
        foreach ($cycles as &$cycle) {
            $cycle['pending_participants'] = in_array((string) ($cycle['status'] ?? ''), ['draft', 'active'], true)
                ? $this->pendingCycleParticipants((int) $cycle['id'])
                : [];
        }
        unset($cycle);

        return [
            'counts' => $counts,
            'reviews' => array_slice($reviews, 0, 20),
            'cycles' => $cycles,
        ];
    }

    public function templates(): array
    {
        $this->seedDefaults();
        $templates = $this->pdo->query('SELECT * FROM performance_review_templates WHERE is_active = 1 ORDER BY is_builtin DESC, title')->fetchAll();
        foreach ($templates as &$template) {
            $template['questions'] = $this->questions((int) $template['id']);
        }
        unset($template);

        return $templates;
    }

    public function createTemplate(array $input, int $userId): void
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Укажите название шаблона.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO performance_review_templates (title, description, is_builtin, is_active, created_by)
            VALUES (?, ?, 0, 1, ?)
        ');
        $stmt->execute([
            $title,
            trim((string) ($input['description'] ?? '')) ?: null,
            $userId,
        ]);
    }

    public function addQuestion(array $input): void
    {
        $templateId = (int) ($input['template_id'] ?? 0);
        $data = $this->normalizeQuestionInput($input);
        if ($templateId <= 0) {
            throw new RuntimeException('Выберите шаблон.');
        }

        $key = trim((string) ($input['question_key'] ?? ''));
        if ($key === '') {
            $key = 'q_' . substr(sha1($data['label'] . microtime(true)), 0, 10);
        }
        $key = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key) ?: ('q_' . time());

        $stmt = $this->pdo->prepare('
            INSERT INTO performance_review_questions (template_id, question_key, section_key, section_label, label, question_type, answer_scope, is_required, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $templateId,
            $key,
            $data['section_key'],
            $data['section_label'],
            $data['label'],
            $data['question_type'],
            $data['answer_scope'],
            $data['is_required'],
            $data['sort_order'],
        ]);
    }

    public function updateQuestion(int $id, array $input): void
    {
        if ($id <= 0) {
            throw new RuntimeException('Вопрос не найден.');
        }
        $exists = $this->pdo->prepare('SELECT id FROM performance_review_questions WHERE id = ? LIMIT 1');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Вопрос не найден.');
        }
        $data = $this->normalizeQuestionInput($input);
        $stmt = $this->pdo->prepare('
            UPDATE performance_review_questions
            SET section_key = ?, section_label = ?, label = ?, question_type = ?, answer_scope = ?, is_required = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([
            $data['section_key'],
            $data['section_label'],
            $data['label'],
            $data['question_type'],
            $data['answer_scope'],
            $data['is_required'],
            $data['sort_order'],
            $id,
        ]);
    }

    public function createCycle(array $input, int $userId): int
    {
        $data = $this->normalizeCycleInput($input);
        $templateId = $data['template_id'];
        $title = $data['title'];
        $reviewYear = $data['review_year'];
        $employeeIds = $data['employee_ids'];
        $this->assertAnnualYearAvailable($reviewYear, $data['cycle_kind']);

        $questions = $this->questions($templateId);
        $matrix = $this->competencyMatrix();
        $questionnaireSnapshot = $this->encodeSnapshot($questions);
        $competencySnapshot = $this->encodeSnapshot($matrix);
        $users = $this->usersByIds($employeeIds);
        if (count($users) !== count($employeeIds)) {
            throw new RuntimeException('В списке участников есть неактивный или недоступный сотрудник. Обновите страницу и повторите выбор.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO performance_review_cycles (
                    template_id, title, cycle_kind, review_year, period_start, period_end, response_deadline,
                    status, questionnaire_snapshot_json, competency_snapshot_json, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?)
            ');
            $stmt->execute([
                $templateId,
                $title,
                $data['cycle_kind'],
                $reviewYear,
                $data['period_start'],
                $data['period_end'],
                $data['response_deadline'],
                $questionnaireSnapshot,
                $competencySnapshot,
                $userId,
            ]);
            $cycleId = (int) $this->pdo->lastInsertId();
            $this->insertCycleReviews($cycleId, $employeeIds, $users, $matrix, $userId);
            $this->pdo->commit();

            return $cycleId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function draftCycleForEdit(int $cycleId): array
    {
        $cycle = $this->cycle($cycleId);
        if ((string) ($cycle['status'] ?? '') !== 'draft' || !empty($cycle['audience_opened_at'])) {
            throw new RuntimeException('Редактировать цикл можно только до запуска первой группы.');
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM performance_reviews WHERE cycle_id = ? AND status = "draft" ORDER BY user_id');
        $stmt->execute([$cycleId]);
        $cycle['employee_ids'] = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        return $cycle;
    }

    public function cycleWorkspace(int $cycleId): array
    {
        $cycle = $this->cycle($cycleId);
        $stmt = $this->pdo->prepare('
            SELECT r.id, r.user_id, r.manager_id, r.status, r.launch_batch_no, r.launched_at,
                   employee.name, employee.department, employee.email,
                   manager.name AS manager_name
            FROM performance_reviews r
            JOIN users employee ON employee.id = r.user_id
            LEFT JOIN users manager ON manager.id = r.manager_id
            WHERE r.cycle_id = ?
            ORDER BY CASE WHEN r.launch_batch_no IS NULL THEN 1 ELSE 0 END,
                     r.launch_batch_no, employee.department, employee.name
        ');
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();
        $batches = [];
        foreach ($participants as $participant) {
            $batchNo = (int) ($participant['launch_batch_no'] ?? 0);
            if ($batchNo <= 0) {
                continue;
            }
            if (!isset($batches[$batchNo])) {
                $batches[$batchNo] = [
                    'number' => $batchNo,
                    'launched_at' => (string) ($participant['launched_at'] ?? ''),
                    'participants' => [],
                ];
            }
            $batches[$batchNo]['participants'][] = $participant;
        }
        ksort($batches);
        $cycle['participants'] = $participants;
        $cycle['pending_participants'] = array_values(array_filter(
            $participants,
            static fn (array $participant): bool => (string) ($participant['status'] ?? '') === 'draft'
        ));
        $cycle['batches'] = array_values($batches);

        return $cycle;
    }

    public function updateDraftCycle(int $cycleId, array $input, int $userId): void
    {
        $cycle = $this->draftCycleForEdit($cycleId);
        $data = $this->normalizeCycleInput($input);
        $this->assertAnnualYearAvailable($data['review_year'], $data['cycle_kind'], $cycleId);

        $users = $this->usersByIds($data['employee_ids']);
        if (count($users) !== count($data['employee_ids'])) {
            throw new RuntimeException('В списке участников есть неактивный или недоступный сотрудник. Обновите страницу и повторите выбор.');
        }
        $questions = $this->questions($data['template_id']);
        $matrix = $this->competencyMatrix();

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT COUNT(*) FROM performance_reviews WHERE cycle_id = ? AND status <> "draft"');
            $lock->execute([$cycleId]);
            if ((int) $lock->fetchColumn() > 0) {
                throw new RuntimeException('Первая группа уже запущена. Состав и параметры цикла больше нельзя менять.');
            }
            $stmt = $this->pdo->prepare('
                UPDATE performance_review_cycles
                SET template_id = ?, title = ?, cycle_kind = ?, review_year = ?, period_start = ?, period_end = ?,
                    response_deadline = ?, questionnaire_snapshot_json = ?, competency_snapshot_json = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = "draft" AND audience_opened_at IS NULL
            ');
            $stmt->execute([
                $data['template_id'],
                $data['title'],
                $data['cycle_kind'],
                $data['review_year'],
                $data['period_start'],
                $data['period_end'],
                $data['response_deadline'],
                $this->encodeSnapshot($questions),
                $this->encodeSnapshot($matrix),
                $cycleId,
            ]);
            $stillDraft = $this->pdo->prepare('SELECT COUNT(*) FROM performance_review_cycles WHERE id = ? AND status = "draft" AND audience_opened_at IS NULL');
            $stillDraft->execute([$cycleId]);
            if ((int) $stillDraft->fetchColumn() !== 1) {
                throw new RuntimeException('Цикл уже был запущен другим пользователем. Обновите страницу.');
            }
            $this->pdo->prepare('DELETE FROM performance_reviews WHERE cycle_id = ? AND status = "draft"')->execute([$cycleId]);
            $this->insertCycleReviews($cycleId, $data['employee_ids'], $users, $matrix, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{batch_no:int,participant_count:int,notification_count:int} */
    public function openCycle(int $cycleId, int $userId, array $employeeIds): array
    {
        $cycle = $this->cycle($cycleId);
        if (!in_array((string) $cycle['status'], ['draft', 'active'], true)) {
            throw new RuntimeException('Добавлять участников можно только в черновик или открытый цикл.');
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if ($employeeIds === []) {
            throw new RuntimeException('Выберите хотя бы одного сотрудника для запуска.');
        }

        $this->pdo->beginTransaction();
        try {
            if ((string) $cycle['status'] === 'draft') {
                $stmt = $this->pdo->prepare('
                    UPDATE performance_review_cycles
                    SET status = "active", audience_opened_at = CURRENT_TIMESTAMP, audience_opened_by = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = "draft"
                ');
                $stmt->execute([$userId, $cycleId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Цикл уже был открыт другим пользователем.');
                }
            }
            $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
            $selected = $this->pdo->prepare("SELECT user_id FROM performance_reviews WHERE cycle_id = ? AND status = 'draft' AND user_id IN ({$placeholders})");
            $selected->execute([$cycleId, ...$employeeIds]);
            $openedEmployeeIds = array_values(array_map('intval', $selected->fetchAll(PDO::FETCH_COLUMN)));
            if ($openedEmployeeIds === []) {
                throw new RuntimeException('У выбранных сотрудников ревью уже запущено или они не входят в этот цикл.');
            }
            $batchStmt = $this->pdo->prepare('SELECT COALESCE(MAX(launch_batch_no), 0) + 1 FROM performance_reviews WHERE cycle_id = ?');
            $batchStmt->execute([$cycleId]);
            $batchNo = max(1, (int) $batchStmt->fetchColumn());
            $openedPlaceholders = implode(',', array_fill(0, count($openedEmployeeIds), '?'));
            $this->pdo->prepare("UPDATE performance_reviews
                SET status = 'self_review', launch_batch_no = ?, launched_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE cycle_id = ? AND status = 'draft' AND user_id IN ({$openedPlaceholders})")
                ->execute([$batchNo, $cycleId, ...$openedEmployeeIds]);
            $noticeCount = $this->notifyCycleAudience($cycleId, $cycle, $openedEmployeeIds);
            $this->pdo->commit();

            return [
                'batch_no' => $batchNo,
                'participant_count' => count($openedEmployeeIds),
                'notification_count' => $noticeCount,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function closeCycle(int $cycleId, int $userId): void
    {
        $cycle = $this->cycle($cycleId);
        if ((string) $cycle['status'] !== 'active') {
            throw new RuntimeException('Закрыть можно только открытый цикл.');
        }
        $stmt = $this->pdo->prepare('
            UPDATE performance_review_cycles
            SET status = "closed", closed_by = ?, closed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = "active"
        ');
        $stmt->execute([$userId, $cycleId]);
    }

    public function reopenCycle(int $cycleId): void
    {
        $cycle = $this->cycle($cycleId);
        if ((string) $cycle['status'] !== 'closed') {
            throw new RuntimeException('Открыть для заполнения можно только закрытый цикл.');
        }
        $stmt = $this->pdo->prepare('
            UPDATE performance_review_cycles
            SET status = "active", closed_by = NULL, closed_at = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = "closed"
        ');
        $stmt->execute([$cycleId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Состояние цикла уже изменилось. Обновите страницу.');
        }
    }

    public function makeCycleOfficial(int $cycleId): void
    {
        $cycle = $this->cycle($cycleId);
        if ((string) ($cycle['cycle_kind'] ?? '') !== 'test') {
            throw new RuntimeException('Этот цикл уже является официальным.');
        }
        $this->assertAnnualYearAvailable((int) ($cycle['review_year'] ?? 0), 'annual', $cycleId);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                UPDATE performance_review_cycles
                SET cycle_kind = "annual", updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND cycle_kind = "test"
            ');
            $stmt->execute([$cycleId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Тип цикла уже изменился. Обновите страницу.');
            }
            $this->pdo->prepare('
                UPDATE notifications
                SET body = REPLACE(body, "тестовый прогон Performance Review", "ежегодный Performance Review")
                WHERE id IN (
                    SELECT notification_id
                    FROM performance_review_cycle_notices
                    WHERE cycle_id = ? AND notification_id IS NOT NULL
                )
            ')->execute([$cycleId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reviewsForUser(array $user): array
    {
        return $this->reviewRows(
            'c.status = "active" AND r.status <> "draft" AND r.user_id = :uid',
            ['uid' => (int) ($user['id'] ?? 0)]
        );
    }

    /**
     * @return array{personal:array<int,array<string,mixed>>,manager_ready:array<int,array<string,mixed>>}
     */
    public function workdayActionsForUser(array $user): array
    {
        $personal = $this->reviewsForUser($user);
        $managerReady = array_values(array_filter(
            $this->managerReviewsForUser($user),
            static fn (array $review): bool => in_array((string) ($review['manager_state'] ?? ''), ['ready', 'meeting_ready'], true)
        ));

        return ['personal' => $personal, 'manager_ready' => $managerReady];
    }

    public function managerReviewsForUser(array $user): array
    {
        $rows = $this->reviewRows(
            'c.status = "active" AND r.status <> "draft" AND r.manager_id = :uid',
            ['uid' => (int) ($user['id'] ?? 0)]
        );
        foreach ($rows as &$row) {
            $row['manager_state'] = $this->managerState($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{reviews:array<int,array<string,mixed>>,metrics:array{total:int,ready:int,meeting_ready:int,done:int}}
     */
    public function managerReportForUser(array $user): array
    {
        $managerId = (int) ($user['id'] ?? 0);
        if ($managerId <= 0) {
            throw new RuntimeException('Не удалось определить руководителя для отчёта.');
        }

        $reviews = $this->reviewRows(
            'r.status <> "draft" AND r.manager_id = :uid',
            ['uid' => $managerId]
        );
        $scoresByReview = $this->scoresByReviewIds(array_map(static fn (array $review): int => (int) $review['id'], $reviews));
        $metrics = ['total' => count($reviews), 'ready' => 0, 'meeting_ready' => 0, 'done' => 0];
        foreach ($reviews as &$review) {
            $review['manager_state'] = $this->managerState($review);
            if (array_key_exists($review['manager_state'], $metrics)) {
                $metrics[$review['manager_state']]++;
            }
            $review['paired'] = !empty($review['self_matrix_submitted_at']) && !empty($review['manager_matrix_submitted_at']);
            $review['averages'] = $this->reviewAverages((array) ($scoresByReview[(int) $review['id']] ?? []), $review['paired']);
        }
        unset($review);

        return compact('reviews', 'metrics');
    }

    public function profileReviews(int $employeeId, array $viewer): array
    {
        $viewerId = (int) ($viewer['id'] ?? 0);
        if ($employeeId <= 0 || $viewerId <= 0) {
            return [];
        }
        if (PermissionService::canManagePerformanceReviews($viewer)) {
            return $this->reviewRows('r.user_id = :employee_id', ['employee_id' => $employeeId]);
        }
        if ($employeeId === $viewerId) {
            return $this->reviewRows(
                'c.status = "active" AND r.status <> "draft" AND r.user_id = :employee_id',
                ['employee_id' => $employeeId]
            );
        }

        return $this->reviewRows(
            'c.status = "active" AND r.status <> "draft" AND r.user_id = :employee_id AND r.manager_id = :viewer_id',
            ['employee_id' => $employeeId, 'viewer_id' => $viewerId]
        );
    }

    public function allReviews(): array
    {
        return $this->reviewRows('1=1');
    }

    private function reviewRows(string $where, array $params = []): array
    {

        $stmt = $this->pdo->prepare('
            SELECT r.*, c.title AS cycle_title, c.cycle_kind, c.review_year, c.period_start, c.period_end,
                   c.response_deadline, c.status AS cycle_status,
                   employee.name AS employee_name, employee.department AS employee_department,
                   manager.name AS manager_name
            FROM performance_reviews r
            JOIN performance_review_cycles c ON c.id = r.cycle_id
            JOIN users employee ON employee.id = r.user_id
            LEFT JOIN users manager ON manager.id = r.manager_id
            WHERE ' . $where . '
            ORDER BY r.updated_at DESC, r.id DESC
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function managerState(array $review): string
    {
        if (!empty($review['meeting_completed_at']) || (string) ($review['status'] ?? '') === 'closed') {
            return 'done';
        }
        if (!empty($review['manager_matrix_submitted_at'])) {
            return 'meeting_ready';
        }
        if (!empty($review['self_matrix_submitted_at'])) {
            return 'ready';
        }

        return 'waiting';
    }

    public function review(int $id, array $user): array
    {
        $stmt = $this->pdo->prepare('
            SELECT r.*, c.title AS cycle_title, c.cycle_kind, c.template_id, c.review_year, c.period_start, c.period_end,
                   c.response_deadline, c.status AS cycle_status,
                   c.questionnaire_snapshot_json, c.competency_snapshot_json,
                   employee.name AS employee_name, employee.department AS employee_department,
                   manager.name AS manager_name
            FROM performance_reviews r
            JOIN performance_review_cycles c ON c.id = r.cycle_id
            JOIN users employee ON employee.id = r.user_id
            LEFT JOIN users manager ON manager.id = r.manager_id
            WHERE r.id = ?
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $review = $stmt->fetch();
        if (!$review || !$this->canViewReview($review, $user)) {
            throw new RuntimeException('Ревью не найдено или недоступно.');
        }

        $review['questions'] = $this->decodeSnapshot((string) ($review['questionnaire_snapshot_json'] ?? ''))
            ?: $this->questions((int) $review['template_id']);
        $review['competency_matrix'] = $this->decodeSnapshot((string) ($review['competency_snapshot_json'] ?? ''))
            ?: $this->competencyMatrix();
        $review['answers'] = $this->answers($id);
        $review['competency_scores'] = $this->competencyScores($id);
        $this->applyVisibilityRules($review, $user);

        return $review;
    }

    public function saveSelfReview(int $id, array $input, array $user): void
    {
        $this->submitSelfQuestionnaire($id, $input, $user);
    }

    public function saveSelfQuestionnaireDraft(int $id, array $input, array $user): void
    {
        $review = $this->review($id, $user);
        if ((int) $review['user_id'] !== (int) $user['id'] || !empty($review['self_questionnaire_submitted_at']) || (string) ($review['cycle_status'] ?? '') !== 'active') {
            throw new RuntimeException('Самооценку сейчас заполнить нельзя.');
        }
        $this->saveAnswers($review, 'self', (array) ($input['answers'] ?? []), (int) $user['id'], false);
    }

    public function submitSelfQuestionnaire(int $id, array $input, array $user): void
    {
        $review = $this->review($id, $user);
        if ((int) $review['user_id'] !== (int) $user['id'] || !empty($review['self_questionnaire_submitted_at']) || (string) ($review['cycle_status'] ?? '') !== 'active') {
            throw new RuntimeException('Анкету самооценки сейчас отправить нельзя.');
        }
        $this->saveAnswers($review, 'self', (array) ($input['answers'] ?? []), (int) $user['id']);
        $this->pdo->prepare('UPDATE performance_reviews SET self_questionnaire_submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$id]);
        $this->refreshReviewStatus($id);
    }

    public function saveCompetencyDraft(int $id, string $scope, array $input, array $user): void
    {
        $review = $this->review($id, $user);
        $this->assertCompetencyEditor($review, $scope, $user);
        $this->saveCompetencyScores($review, $scope, (array) ($input['scores'] ?? []), (array) ($input['comments'] ?? []), (int) $user['id'], false);
    }

    public function submitCompetencyReview(int $id, string $scope, array $input, array $user): void
    {
        $review = $this->review($id, $user);
        $this->assertCompetencyEditor($review, $scope, $user);
        $this->saveCompetencyScores($review, $scope, (array) ($input['scores'] ?? []), (array) ($input['comments'] ?? []), (int) $user['id'], true);
        $column = $scope === 'self' ? 'self_matrix_submitted_at' : 'manager_matrix_submitted_at';
        $legacyColumn = $scope === 'self' ? 'self_submitted_at' : 'manager_submitted_at';
        $this->pdo->prepare("UPDATE performance_reviews SET {$column} = CURRENT_TIMESTAMP, {$legacyColumn} = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$id]);
        $this->refreshReviewStatus($id);
        if ($scope === 'self') {
            $this->notifyManagerReady($id);
        }
    }

    public function completeMeeting(int $id, array $input, array $user): void
    {
        $review = $this->review($id, $user);
        $managerId = (int) ($review['manager_id'] ?? 0);
        if ($managerId <= 0
            || $managerId !== (int) ($user['id'] ?? 0)
            || (string) $review['status'] !== 'hr_review'
            || (string) ($review['cycle_status'] ?? '') !== 'active') {
            throw new RuntimeException('Зафиксировать встречу может назначенный руководитель после завершения обеих оценок.');
        }
        $notes = trim((string) ($input['meeting_notes'] ?? ''));
        $actions = trim((string) ($input['next_year_actions'] ?? ''));
        if ($notes === '' || $actions === '') {
            throw new RuntimeException('Зафиксируйте итоги встречи и шаги на следующий год.');
        }
        $stmt = $this->pdo->prepare('
            UPDATE performance_reviews
            SET status = "closed", meeting_notes = ?, next_year_actions = ?,
                meeting_completed_at = CURRENT_TIMESTAMP, meeting_completed_by = ?,
                hr_closed_at = CURRENT_TIMESTAMP, hr_closed_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND manager_id = ? AND status = "hr_review"
        ');
        $stmt->execute([$notes, $actions, $managerId, $managerId, $id, $managerId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Ревью изменилось. Обновите страницу и повторите действие.');
        }
    }

    public function assignManager(int $id, int $managerId, array $user): void
    {
        $review = $this->review($id, $user);
        if (!PermissionService::canManagePerformanceReviews($user) || in_array((string) $review['status'], ['closed', 'cancelled'], true)) {
            throw new RuntimeException('Проверяющего для этого ревью изменить нельзя.');
        }
        if ($managerId <= 0 || $managerId === (int) $review['user_id']) {
            throw new RuntimeException('Назначьте действующего руководителя, отличного от оцениваемого сотрудника.');
        }
        $managerStmt = $this->pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $managerStmt->execute([$managerId]);
        if (!$managerStmt->fetchColumn()) {
            throw new RuntimeException('Выбранный руководитель не найден или уже неактивен.');
        }
        if ($managerId === (int) ($review['manager_id'] ?? 0)) {
            return;
        }

        $managerMatrixCompleted = !empty($review['manager_matrix_submitted_at']);
        $this->pdo->beginTransaction();
        try {
            $noticeStmt = $this->pdo->prepare('SELECT notification_id FROM performance_review_stage_notices WHERE review_id = ? AND stage = "manager_ready"');
            $noticeStmt->execute([$id]);
            $notificationIds = array_values(array_filter(array_map('intval', array_column($noticeStmt->fetchAll(), 'notification_id'))));
            $this->pdo->prepare('DELETE FROM performance_review_stage_notices WHERE review_id = ? AND stage = "manager_ready"')->execute([$id]);
            if ($notificationIds !== []) {
                $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
                $this->pdo->prepare('DELETE FROM notifications WHERE id IN (' . $placeholders . ')')->execute($notificationIds);
            }
            $this->pdo->prepare('UPDATE performance_reviews SET manager_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$managerId, $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($managerMatrixCompleted) {
            $this->notifyManagerMeetingReady($id);
        } else {
            $this->notifyManagerReady($id);
        }
    }

    public function activeUsers(): array
    {
        return $this->pdo->query('
            SELECT u.id, u.name, u.email, u.role, u.department, u.manager_id,
                   p.title AS position_title, p.grade AS position_grade,
                   p.competency_position_index
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            WHERE u.is_active = 1 AND u.role <> "admin"
            ORDER BY u.department, u.name
        ')->fetchAll();
    }

    public function competencyPositionProfiles(): array
    {
        $positions = (array) ($this->competencyMatrix()['positions'] ?? []);

        return array_values(array_map(static fn (array $position, int $index): array => [
            'index' => $index,
            'title' => (string) ($position['title'] ?? ''),
            'grade' => (string) ($position['grade'] ?? ''),
        ], $positions, array_keys($positions)));
    }

    public function cycleSummary(int $cycleId, array $user): array
    {
        if (!PermissionService::canManagePerformanceReviews($user)) {
            throw new RuntimeException('Сводка цикла доступна только директору и HR.');
        }
        $cycle = $this->cycle($cycleId);
        $stmt = $this->pdo->prepare('
            SELECT r.*, employee.name AS employee_name, employee.department AS employee_department,
                   manager.name AS manager_name
            FROM performance_reviews r
            JOIN users employee ON employee.id = r.user_id
            LEFT JOIN users manager ON manager.id = r.manager_id
            WHERE r.cycle_id = ?
            ORDER BY employee.department, employee.name
        ');
        $stmt->execute([$cycleId]);
        $participants = $stmt->fetchAll();
        $scoresByReview = [];
        $scoreStmt = $this->pdo->prepare('
            SELECT score.*
            FROM performance_review_competency_scores score
            JOIN performance_reviews review ON review.id = score.review_id
            WHERE review.cycle_id = ?
            ORDER BY score.review_id, score.competency_key, score.answer_scope
        ');
        $scoreStmt->execute([$cycleId]);
        foreach ($scoreStmt->fetchAll() as $score) {
            $scoresByReview[(int) $score['review_id']][(string) $score['competency_key']][(string) $score['answer_scope']] = $score;
        }
        $metrics = ['total' => count($participants), 'launched' => 0, 'self_done' => 0, 'manager_done' => 0, 'paired' => 0, 'closed' => 0];
        $competencies = [];
        foreach ($participants as &$participant) {
            if ((string) ($participant['status'] ?? '') !== 'draft') {
                $metrics['launched']++;
            }
            if (!empty($participant['self_matrix_submitted_at'])) {
                $metrics['self_done']++;
            }
            if (!empty($participant['manager_matrix_submitted_at'])) {
                $metrics['manager_done']++;
            }
            if ((string) ($participant['status'] ?? '') === 'closed') {
                $metrics['closed']++;
            }
            $paired = !empty($participant['self_matrix_submitted_at']) && !empty($participant['manager_matrix_submitted_at']);
            $participant['paired'] = $paired;
            $participant['averages'] = ['self' => null, 'manager' => null, 'target' => null, 'delta' => null, 'target_gap' => null];
            if (!$paired) {
                continue;
            }
            $metrics['paired']++;
            $byKey = (array) ($scoresByReview[(int) $participant['id']] ?? []);
            $selfValues = $managerValues = $targetValues = [];
            foreach ($byKey as $key => $pair) {
                if (!isset($pair['self']['score'], $pair['manager']['score'])) {
                    continue;
                }
                $self = (int) $pair['self']['score'];
                $manager = (int) $pair['manager']['score'];
                $target = is_numeric($pair['manager']['required_level_snapshot'] ?? null)
                    ? (int) $pair['manager']['required_level_snapshot']
                    : (is_numeric($pair['self']['required_level_snapshot'] ?? null) ? (int) $pair['self']['required_level_snapshot'] : null);
                $selfValues[] = $self;
                $managerValues[] = $manager;
                if ($target !== null) {
                    $targetValues[] = $target;
                }
                if (!isset($competencies[$key])) {
                    $competencies[$key] = ['name' => (string) ($pair['self']['competency_name_snapshot'] ?? $key), 'self' => [], 'manager' => [], 'target' => [], 'below_target' => 0];
                }
                $competencies[$key]['self'][] = $self;
                $competencies[$key]['manager'][] = $manager;
                if ($target !== null) {
                    $competencies[$key]['target'][] = $target;
                    if ($manager < $target) {
                        $competencies[$key]['below_target']++;
                    }
                }
            }
            $participant['averages']['self'] = $this->average($selfValues);
            $participant['averages']['manager'] = $this->average($managerValues);
            $participant['averages']['target'] = $this->average($targetValues);
            if ($participant['averages']['self'] !== null && $participant['averages']['manager'] !== null) {
                $participant['averages']['delta'] = round($participant['averages']['self'] - $participant['averages']['manager'], 2);
            }
            if ($participant['averages']['target'] !== null && $participant['averages']['manager'] !== null) {
                $participant['averages']['target_gap'] = round($participant['averages']['manager'] - $participant['averages']['target'], 2);
            }
        }
        unset($participant);
        foreach ($competencies as &$competency) {
            $competency['paired_count'] = count($competency['manager']);
            $competency['avg_self'] = $this->average($competency['self']);
            $competency['avg_manager'] = $this->average($competency['manager']);
            $competency['avg_target'] = $this->average($competency['target']);
            $competency['delta'] = round((float) $competency['avg_self'] - (float) $competency['avg_manager'], 2);
            unset($competency['self'], $competency['manager'], $competency['target']);
        }
        unset($competency);

        return compact('cycle', 'metrics', 'participants', 'competencies');
    }

    public function canViewReview(array $review, array $user): bool
    {
        if (PermissionService::canManagePerformanceReviews($user)) {
            return true;
        }
        if ((int) ($review['manager_id'] ?? 0) === (int) ($user['id'] ?? 0) && (string) ($review['status'] ?? 'draft') !== 'draft') {
            return true;
        }
        if ((string) ($review['cycle_status'] ?? 'active') !== 'active') {
            return false;
        }
        if ((string) ($review['status'] ?? 'draft') === 'draft') {
            return false;
        }

        return (int) ($review['user_id'] ?? 0) === (int) ($user['id'] ?? 0);
    }

    private function questions(int $templateId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM performance_review_questions WHERE template_id = ? ORDER BY sort_order, id');
        $stmt->execute([$templateId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function questionsForScope(array $questions, string $scope): array
    {
        return array_values(array_filter($questions, static function (array $question) use ($scope): bool {
            $questionScope = (string) ($question['answer_scope'] ?? 'both');
            return $questionScope === 'both' || $questionScope === $scope;
        }));
    }

    private function answers(int $reviewId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM performance_review_answers WHERE review_id = ? ORDER BY answer_scope, id');
        $stmt->execute([$reviewId]);
        $answers = [];
        foreach ($stmt->fetchAll() as $answer) {
            $answers[(int) $answer['question_id']][(string) $answer['answer_scope']] = $answer;
        }

        return $answers;
    }

    private function saveAnswers(array $review, string $scope, array $answers, int $userId, bool $validateRequired = true): void
    {
        if ((string) $review['status'] === 'closed') {
            throw new RuntimeException('Закрытое ревью нельзя менять.');
        }
        $questions = $this->questionsForScope((array) ($review['questions'] ?? []), $scope);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->upsertAnswerStatement();
            foreach ($questions as $question) {
                $questionId = (int) $question['id'];
                $value = trim((string) ($answers[$questionId] ?? ''));
                if ($validateRequired && (int) ($question['is_required'] ?? 0) === 1 && $value === '') {
                    throw new RuntimeException('Заполните обязательный вопрос: ' . (string) $question['label']);
                }
                if ($value === '' && !$validateRequired) {
                    continue;
                }
                $stmt->execute([
                    (int) $review['id'],
                    $questionId,
                    $scope,
                    $value,
                    (string) $question['label'],
                    (string) $question['question_type'],
                    $userId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function upsertAnswerStatement(): \PDOStatement
    {
        if ($this->isSqlite()) {
            return $this->pdo->prepare('
                INSERT INTO performance_review_answers (
                    review_id, question_id, answer_scope, answer_value, question_label_snapshot, question_type_snapshot, answered_by, answered_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(review_id, question_id, answer_scope) DO UPDATE SET
                    answer_value = excluded.answer_value,
                    question_label_snapshot = excluded.question_label_snapshot,
                    question_type_snapshot = excluded.question_type_snapshot,
                    answered_by = excluded.answered_by,
                    answered_at = CURRENT_TIMESTAMP
            ');
        }

        return $this->pdo->prepare('
            INSERT INTO performance_review_answers (
                review_id, question_id, answer_scope, answer_value, question_label_snapshot, question_type_snapshot, answered_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                answer_value = VALUES(answer_value),
                question_label_snapshot = VALUES(question_label_snapshot),
                question_type_snapshot = VALUES(question_type_snapshot),
                answered_by = VALUES(answered_by),
                answered_at = CURRENT_TIMESTAMP
        ');
    }

    /**
     * @return array{template_id:int,title:string,cycle_kind:string,review_year:int,period_start:?string,period_end:?string,response_deadline:?string,employee_ids:array<int,int>}
     */
    private function normalizeCycleInput(array $input): array
    {
        $templateId = (int) ($input['template_id'] ?? 0);
        $title = trim((string) ($input['title'] ?? ''));
        $reviewYear = (int) ($input['review_year'] ?? 0);
        $cycleKind = array_key_exists('cycle_form_version', $input)
            ? (isset($input['is_test']) ? 'test' : 'annual')
            : (string) ($input['cycle_kind'] ?? 'annual');
        if (!isset(self::CYCLE_KINDS[$cycleKind])) {
            $cycleKind = 'annual';
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['employee_ids'] ?? [])))));
        if ($templateId <= 0 || $title === '' || $reviewYear < 2000 || $reviewYear > 2200 || $employeeIds === []) {
            throw new RuntimeException('Нужны год, шаблон, название цикла и хотя бы один сотрудник.');
        }
        $templateExists = $this->pdo->prepare('SELECT id FROM performance_review_templates WHERE id = ? AND is_active = 1 LIMIT 1');
        $templateExists->execute([$templateId]);
        if (!$templateExists->fetchColumn()) {
            throw new RuntimeException('Выбранный шаблон архивирован или недоступен.');
        }
        $periodStart = $this->dateOrNull($input['period_start'] ?? null);
        $periodEnd = $this->dateOrNull($input['period_end'] ?? null);
        if ($periodStart !== null && $periodEnd !== null && $periodStart > $periodEnd) {
            throw new RuntimeException('Начало оцениваемого периода не может быть позже его окончания.');
        }

        return [
            'template_id' => $templateId,
            'title' => $title,
            'cycle_kind' => $cycleKind,
            'review_year' => $reviewYear,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'response_deadline' => $this->dateOrNull($input['response_deadline'] ?? null),
            'employee_ids' => $employeeIds,
        ];
    }

    /**
     * @param array<int, int> $employeeIds
     * @param array<int, array<string, mixed>> $users
     */
    private function insertCycleReviews(int $cycleId, array $employeeIds, array $users, array $matrix, int $userId): void
    {
        $reviewStmt = $this->pdo->prepare('
            INSERT INTO performance_reviews (
                cycle_id, user_id, manager_id, position_title_snapshot, position_grade_snapshot,
                competency_position_index, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, "draft", ?)
        ');
        foreach ($employeeIds as $employeeId) {
            $managerId = (int) ($users[$employeeId]['manager_id'] ?? 0);
            $positionTitle = trim((string) ($users[$employeeId]['position_title'] ?? ''));
            $positionGrade = trim((string) ($users[$employeeId]['position_grade'] ?? ''));
            $configuredIndex = $users[$employeeId]['competency_position_index'] ?? null;
            $reviewStmt->execute([
                $cycleId,
                $employeeId,
                $managerId > 0 ? $managerId : null,
                $positionTitle !== '' ? $positionTitle : null,
                $positionGrade !== '' ? $positionGrade : null,
                is_numeric($configuredIndex) ? (int) $configuredIndex : $this->matchCompetencyPosition($matrix, $positionTitle, $positionGrade),
                $userId,
            ]);
        }
    }

    private function usersByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.manager_id, p.title AS position_title, p.grade AS position_grade,
                   p.competency_position_index
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            WHERE u.id IN ({$placeholders}) AND u.is_active = 1
        ");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $date = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function assertAnnualYearAvailable(int $year, string $kind, int $exceptCycleId = 0): void
    {
        if ($kind !== 'annual') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM performance_review_cycles WHERE review_year = ? AND cycle_kind = "annual" AND id <> ? LIMIT 1');
        $stmt->execute([$year, $exceptCycleId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Годовой цикл за этот год уже существует. Тестовые прогоны можно создавать без ограничений.');
        }
    }

    /** @return array<int, array<string, array<string, mixed>>> */
    private function scoresByReviewIds(array $reviewIds): array
    {
        $reviewIds = array_values(array_unique(array_filter(array_map('intval', $reviewIds))));
        if ($reviewIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM performance_review_competency_scores WHERE review_id IN (' . $placeholders . ') ORDER BY review_id, competency_key, answer_scope');
        $stmt->execute($reviewIds);
        $scores = [];
        foreach ($stmt->fetchAll() as $score) {
            $scores[(int) $score['review_id']][(string) $score['competency_key']][(string) $score['answer_scope']] = $score;
        }

        return $scores;
    }

    /** @return array{self:?float,manager:?float,target:?float,delta:?float,target_gap:?float} */
    private function reviewAverages(array $scoresByKey, bool $paired): array
    {
        $averages = ['self' => null, 'manager' => null, 'target' => null, 'delta' => null, 'target_gap' => null];
        if (!$paired) {
            return $averages;
        }
        $selfValues = $managerValues = $targetValues = [];
        foreach ($scoresByKey as $pair) {
            if (!isset($pair['self']['score'], $pair['manager']['score'])) {
                continue;
            }
            $selfValues[] = (int) $pair['self']['score'];
            $managerValues[] = (int) $pair['manager']['score'];
            $target = $pair['manager']['required_level_snapshot'] ?? $pair['self']['required_level_snapshot'] ?? null;
            if (is_numeric($target)) {
                $targetValues[] = (int) $target;
            }
        }
        $averages['self'] = $this->average($selfValues);
        $averages['manager'] = $this->average($managerValues);
        $averages['target'] = $this->average($targetValues);
        if ($averages['self'] !== null && $averages['manager'] !== null) {
            $averages['delta'] = round($averages['self'] - $averages['manager'], 2);
        }
        if ($averages['target'] !== null && $averages['manager'] !== null) {
            $averages['target_gap'] = round($averages['manager'] - $averages['target'], 2);
        }

        return $averages;
    }

    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * @return array{section_key:?string, section_label:?string, label:string, question_type:string, answer_scope:string, is_required:int, sort_order:int}
     */
    private function normalizeQuestionInput(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Заполните текст вопроса.');
        }
        $type = (string) ($input['question_type'] ?? 'textarea');
        if (!isset(self::QUESTION_TYPES[$type])) {
            $type = 'textarea';
        }
        $scope = (string) ($input['answer_scope'] ?? 'both');
        if (!isset(self::QUESTION_SCOPES[$scope])) {
            $scope = 'both';
        }

        return [
            'section_key' => trim((string) ($input['section_key'] ?? '')) ?: null,
            'section_label' => trim((string) ($input['section_label'] ?? '')) ?: null,
            'label' => $label,
            'question_type' => $type,
            'answer_scope' => $scope,
            'is_required' => isset($input['is_required']) ? 1 : 0,
            'sort_order' => (int) ($input['sort_order'] ?? 100),
        ];
    }

    private function seedAnnualTemplate(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM performance_review_templates WHERE title = ? LIMIT 1');
        $stmt->execute([self::ANNUAL_TEMPLATE_TITLE]);
        $templateId = (int) $stmt->fetchColumn();
        if ($templateId <= 0) {
            $this->pdo->prepare('
                INSERT INTO performance_review_templates (title, description, is_builtin, is_active)
                VALUES (?, ?, 1, 1)
            ')->execute([
                self::ANNUAL_TEMPLATE_TITLE,
                'Ежегодная процедура: анкета сотрудника, независимая оценка по матрице и очная встреча.',
            ]);
            $templateId = (int) $this->pdo->lastInsertId();
        }

        $seedFile = dirname(__DIR__, 2) . '/config/performance_review_annual_seed.php';
        $questions = is_file($seedFile) ? require $seedFile : [];
        if (!is_array($questions)) {
            return $templateId;
        }
        $statement = $this->isSqlite()
            ? $this->pdo->prepare('
                INSERT INTO performance_review_questions (
                    template_id, question_key, section_key, section_label, label, question_type, answer_scope, is_required, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(template_id, question_key) DO UPDATE SET
                    section_key = excluded.section_key,
                    section_label = excluded.section_label,
                    label = excluded.label,
                    question_type = excluded.question_type,
                    answer_scope = excluded.answer_scope,
                    is_required = excluded.is_required,
                    sort_order = excluded.sort_order,
                    updated_at = CURRENT_TIMESTAMP
            ')
            : $this->pdo->prepare('
                INSERT INTO performance_review_questions (
                    template_id, question_key, section_key, section_label, label, question_type, answer_scope, is_required, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    section_key = VALUES(section_key),
                    section_label = VALUES(section_label),
                    label = VALUES(label),
                    question_type = VALUES(question_type),
                    answer_scope = VALUES(answer_scope),
                    is_required = VALUES(is_required),
                    sort_order = VALUES(sort_order),
                    updated_at = CURRENT_TIMESTAMP
            ');
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $statement->execute([
                $templateId,
                (string) ($question['question_key'] ?? ''),
                (string) ($question['section_key'] ?? ''),
                (string) ($question['section_label'] ?? ''),
                (string) ($question['label'] ?? ''),
                (string) ($question['question_type'] ?? 'textarea'),
                (string) ($question['answer_scope'] ?? 'self'),
                (int) ($question['is_required'] ?? 1),
                (int) ($question['sort_order'] ?? 100),
            ]);
        }

        return $templateId;
    }

    private function competencyMatrix(): array
    {
        $file = dirname(__DIR__, 2) . '/config/performance_review_matrix.php';
        $matrix = is_file($file) ? require $file : [];

        return is_array($matrix) ? $matrix : [];
    }

    private function cycle(int $cycleId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM performance_review_cycles WHERE id = ? LIMIT 1');
        $stmt->execute([$cycleId]);
        $cycle = $stmt->fetch();
        if (!$cycle) {
            throw new RuntimeException('Цикл performance review не найден.');
        }

        return $cycle;
    }

    private function encodeSnapshot(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $json;
    }

    private function decodeSnapshot(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function matchCompetencyPosition(array $matrix, string $title, string $grade): ?int
    {
        $normalize = static fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
        $titleKey = $normalize($title);
        $gradeKey = $normalize($grade);
        foreach ((array) ($matrix['positions'] ?? []) as $index => $position) {
            if ($titleKey !== '' && $normalize((string) ($position['title'] ?? '')) === $titleKey) {
                return (int) $index;
            }
        }
        if ($gradeKey !== '') {
            $matches = [];
            foreach ((array) ($matrix['positions'] ?? []) as $index => $position) {
                if ($normalize((string) ($position['grade'] ?? '')) === $gradeKey) {
                    $matches[] = (int) $index;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    private function pendingCycleParticipants(int $cycleId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT r.user_id, employee.name, employee.department, manager.name AS manager_name
            FROM performance_reviews r
            JOIN users employee ON employee.id = r.user_id
            LEFT JOIN users manager ON manager.id = r.manager_id
            WHERE r.cycle_id = ? AND r.status = "draft"
            ORDER BY employee.department, employee.name
        ');
        $stmt->execute([$cycleId]);

        return $stmt->fetchAll();
    }

    private function notifyCycleAudience(int $cycleId, array $cycle, array $employeeIds): int
    {
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT user_id, manager_id
            FROM performance_reviews
            WHERE cycle_id = ? AND user_id IN (' . $placeholders . ')
        ');
        $stmt->execute([$cycleId, ...$employeeIds]);
        $userIds = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id > 0) {
                $userIds[$id] = true;
            }
        }
        $insertNotice = $this->pdo->prepare(
            ($this->isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE')
            . ' INTO performance_review_cycle_notices (cycle_id, user_id) VALUES (?, ?)'
        );
        $insertNotification = $this->pdo->prepare('
            INSERT INTO notifications (user_id, task_id, type, body, target_url)
            VALUES (?, NULL, "performance_review_opened", ?, "/profile#performance-review")
        ');
        $linkNotice = $this->pdo->prepare('UPDATE performance_review_cycle_notices SET notification_id = ? WHERE cycle_id = ? AND user_id = ?');
        $deadline = $this->dateLabel((string) ($cycle['response_deadline'] ?? ''));
        $kindLabel = (string) (($cycle['cycle_kind'] ?? 'annual') === 'test' ? 'тестовый прогон Performance Review' : 'ежегодный Performance Review');
        $body = 'Открыт ' . $kindLabel . ': ' . (string) $cycle['title'] . '.';
        if ($deadline !== '') {
            $body .= ' Заполните анкету и оценку по матрице до ' . $deadline . '.';
        }
        $count = 0;
        foreach (array_keys($userIds) as $id) {
            $insertNotice->execute([$cycleId, $id]);
            if ($insertNotice->rowCount() !== 1) {
                continue;
            }
            $insertNotification->execute([$id, $body]);
            $linkNotice->execute([(int) $this->pdo->lastInsertId(), $cycleId, $id]);
            $count++;
        }

        return $count;
    }

    private function notifyManagerReady(int $reviewId): void
    {
        $stmt = $this->pdo->prepare('
            SELECT r.id, r.manager_id, employee.name AS employee_name, c.title AS cycle_title
            FROM performance_reviews r
            JOIN performance_review_cycles c ON c.id = r.cycle_id
            JOIN users employee ON employee.id = r.user_id
            WHERE r.id = ? AND c.status = "active" AND r.self_matrix_submitted_at IS NOT NULL
              AND r.manager_matrix_submitted_at IS NULL AND r.manager_id IS NOT NULL
            LIMIT 1
        ');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) {
            return;
        }

        $insertNotice = $this->pdo->prepare(
            ($this->isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE')
            . ' INTO performance_review_stage_notices (review_id, user_id, stage) VALUES (?, ?, "manager_ready")'
        );
        $insertNotice->execute([$reviewId, (int) $review['manager_id']]);
        if ($insertNotice->rowCount() !== 1) {
            return;
        }

        $body = 'Можно оценивать сотрудника ' . (string) $review['employee_name']
            . ': самооценка завершена. Ответы и баллы сотрудника будут скрыты до отправки вашей оценки.';
        $notification = $this->pdo->prepare('
            INSERT INTO notifications (user_id, task_id, type, body, target_url)
            VALUES (?, NULL, "performance_review_manager_ready", ?, "/performance-review/manager")
        ');
        $notification->execute([(int) $review['manager_id'], $body]);
        $this->pdo->prepare('
            UPDATE performance_review_stage_notices
            SET notification_id = ?
            WHERE review_id = ? AND user_id = ? AND stage = "manager_ready"
        ')->execute([(int) $this->pdo->lastInsertId(), $reviewId, (int) $review['manager_id']]);
    }

    private function notifyManagerMeetingReady(int $reviewId): void
    {
        $stmt = $this->pdo->prepare('
            SELECT r.id, r.manager_id, employee.name AS employee_name
            FROM performance_reviews r
            JOIN performance_review_cycles c ON c.id = r.cycle_id
            JOIN users employee ON employee.id = r.user_id
            WHERE r.id = ? AND c.status = "active" AND r.self_matrix_submitted_at IS NOT NULL
              AND r.manager_matrix_submitted_at IS NOT NULL AND r.manager_id IS NOT NULL
            LIMIT 1
        ');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) {
            return;
        }

        $this->pdo->prepare('
            INSERT INTO notifications (user_id, task_id, type, body, target_url)
            VALUES (?, NULL, "performance_review_meeting_ready", ?, ?)
        ')->execute([
            (int) $review['manager_id'],
            'Вы назначены руководителем ревью сотрудника ' . (string) $review['employee_name'] . '. Матрицы заполнены: зафиксируйте итоги встречи.',
            '/performance-review/' . (int) $review['id'],
        ]);
    }

    private function dateLabel(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return '';
        }

        return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
    }

    private function competencyScores(int $reviewId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM performance_review_competency_scores WHERE review_id = ? ORDER BY competency_key, answer_scope');
        $stmt->execute([$reviewId]);
        $scores = [];
        foreach ($stmt->fetchAll() as $row) {
            $scores[(string) $row['competency_key']][(string) $row['answer_scope']] = $row;
        }

        return $scores;
    }

    private function applyVisibilityRules(array &$review, array $user): void
    {
        $review['visibility'] = ['self' => true, 'manager' => true, 'comparison' => true, 'target' => true];
        $viewerId = (int) ($user['id'] ?? 0);
        $isEmployee = (int) ($review['user_id'] ?? 0) === $viewerId;
        $isManager = (int) ($review['manager_id'] ?? 0) === $viewerId;
        if (PermissionService::canManagePerformanceReviews($user) && !$isManager && !$isEmployee) {
            return;
        }
        if (($isEmployee || $isManager) && (empty($review['self_matrix_submitted_at']) || empty($review['manager_matrix_submitted_at']))) {
            foreach (array_keys((array) ($review['competency_matrix']['competencies'] ?? [])) as $competencyKey) {
                unset($review['competency_matrix']['competencies'][$competencyKey]['required']);
            }
            $review['visibility']['target'] = false;
        }
        if ($isManager && empty($review['manager_matrix_submitted_at'])) {
            foreach ($review['answers'] as $questionId => $scopes) {
                unset($review['answers'][$questionId]['self']);
            }
            foreach ($review['competency_scores'] as $key => $scopes) {
                unset($review['competency_scores'][$key]['self']);
            }
            $review['visibility']['self'] = false;
            $review['visibility']['comparison'] = false;
        }
        if ($isEmployee && (empty($review['self_matrix_submitted_at']) || empty($review['manager_matrix_submitted_at']))) {
            foreach ($review['competency_scores'] as $key => $scopes) {
                unset($review['competency_scores'][$key]['manager']);
            }
            $review['visibility']['manager'] = false;
            $review['visibility']['comparison'] = false;
        }
        if ($isEmployee) {
            foreach ($review['answers'] as $questionId => $scopes) {
                unset($review['answers'][$questionId]['manager'], $review['answers'][$questionId]['hr']);
            }
        }
    }

    private function assertCompetencyEditor(array $review, string $scope, array $user): void
    {
        if (!in_array($scope, ['self', 'manager'], true) || (string) ($review['cycle_status'] ?? '') !== 'active') {
            throw new RuntimeException('Оценку по матрице сейчас заполнить нельзя.');
        }
        if (empty($review['self_questionnaire_submitted_at'])) {
            throw new RuntimeException('Сначала сотрудник должен завершить анкету самооценки.');
        }
        if ($scope === 'self') {
            if ((int) $review['user_id'] !== (int) $user['id'] || !empty($review['self_matrix_submitted_at'])) {
                throw new RuntimeException('Самооценку по матрице сейчас изменить нельзя.');
            }
            return;
        }
        if (empty($review['self_matrix_submitted_at'])) {
            throw new RuntimeException('Оценка руководителя откроется после завершения самооценки сотрудника по матрице.');
        }
        if ((int) ($review['manager_id'] ?? 0) !== (int) $user['id'] || !empty($review['manager_matrix_submitted_at'])) {
            throw new RuntimeException('Оценку руководителя сейчас изменить нельзя.');
        }
    }

    private function saveCompetencyScores(array $review, string $scope, array $scores, array $comments, int $userId, bool $requireComplete): void
    {
        $matrix = (array) ($review['competency_matrix'] ?? []);
        $competencies = (array) ($matrix['competencies'] ?? []);
        if ($competencies === []) {
            throw new RuntimeException('Матрица компетенций для цикла не найдена.');
        }
        $positionIndex = is_numeric($review['competency_position_index'] ?? null)
            ? (int) $review['competency_position_index']
            : null;
        $stmt = $this->upsertCompetencyStatement();
        $this->pdo->beginTransaction();
        try {
            foreach ($competencies as $key => $competency) {
                $score = (int) ($scores[(string) $key] ?? $scores[(int) $key] ?? 0);
                if ($requireComplete && ($score < 1 || $score > 5)) {
                    throw new RuntimeException('Оцените все компетенции по шкале от 1 до 5.');
                }
                if ($score < 1 || $score > 5) {
                    continue;
                }
                $levels = (array) ($competency['levels'] ?? []);
                $required = $positionIndex !== null ? ($competency['required'][$positionIndex] ?? null) : null;
                $stmt->execute([
                    (int) $review['id'],
                    (string) $key,
                    $scope,
                    $score,
                    trim((string) ($comments[(string) $key] ?? $comments[(int) $key] ?? '')) ?: null,
                    (string) ($competency['name'] ?? ''),
                    (string) ($competency['desc'] ?? ''),
                    (string) ($levels[1] ?? ''),
                    (string) ($levels[2] ?? ''),
                    (string) ($levels[3] ?? ''),
                    (string) ($levels[4] ?? ''),
                    (string) ($levels[5] ?? ''),
                    is_numeric($required) ? (int) $required : null,
                    $userId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function upsertCompetencyStatement(): \PDOStatement
    {
        $columns = '
            review_id, competency_key, answer_scope, score, comment,
            competency_name_snapshot, competency_description_snapshot,
            level_1_snapshot, level_2_snapshot, level_3_snapshot, level_4_snapshot, level_5_snapshot,
            required_level_snapshot, answered_by
        ';
        if ($this->isSqlite()) {
            return $this->pdo->prepare("\n                INSERT INTO performance_review_competency_scores ({$columns})\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ON CONFLICT(review_id, competency_key, answer_scope) DO UPDATE SET\n                    score = excluded.score, comment = excluded.comment, answered_by = excluded.answered_by,\n                    answered_at = CURRENT_TIMESTAMP\n            ");
        }

        return $this->pdo->prepare("\n            INSERT INTO performance_review_competency_scores ({$columns})\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ON DUPLICATE KEY UPDATE\n                score = VALUES(score), comment = VALUES(comment), answered_by = VALUES(answered_by),\n                answered_at = CURRENT_TIMESTAMP\n        ");
    }

    private function refreshReviewStatus(int $reviewId): void
    {
        $stmt = $this->pdo->prepare('
            SELECT self_questionnaire_submitted_at, self_matrix_submitted_at,
                   manager_matrix_submitted_at, meeting_completed_at
            FROM performance_reviews WHERE id = ? LIMIT 1
        ');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) {
            return;
        }
        $status = 'self_review';
        if (!empty($review['meeting_completed_at'])) {
            $status = 'closed';
        } elseif (!empty($review['self_questionnaire_submitted_at'])
            && !empty($review['self_matrix_submitted_at'])
            && !empty($review['manager_matrix_submitted_at'])) {
            $status = 'hr_review';
        } elseif (!empty($review['self_matrix_submitted_at'])) {
            $status = 'manager_review';
        }
        $this->pdo->prepare('UPDATE performance_reviews SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status <> "closed"')
            ->execute([$status, $reviewId]);
    }
}
