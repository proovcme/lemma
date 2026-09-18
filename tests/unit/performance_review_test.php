<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PerformanceReviewService;
use App\Services\PermissionService;
use App\Services\RoleService;

function performance_review_test_pdo(): PDO
{
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('
        CREATE TABLE positions (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            grade TEXT,
            competency_position_index INTEGER
        );
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            manager_id INTEGER,
            position_id INTEGER,
            is_active INTEGER DEFAULT 1
        );
        CREATE TABLE performance_review_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            is_builtin INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE performance_review_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            question_key TEXT NOT NULL,
            section_key TEXT,
            section_label TEXT,
            label TEXT NOT NULL,
            question_type TEXT NOT NULL DEFAULT "textarea",
            answer_scope TEXT NOT NULL DEFAULT "both",
            is_required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 100,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(template_id, question_key)
        );
        CREATE TABLE performance_review_cycles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            cycle_kind TEXT NOT NULL DEFAULT "annual",
            review_year INTEGER,
            period_start TEXT,
            period_end TEXT,
            response_deadline TEXT,
            status TEXT NOT NULL DEFAULT "draft",
            questionnaire_snapshot_json TEXT,
            competency_snapshot_json TEXT,
            audience_opened_at TEXT,
            audience_opened_by INTEGER,
            created_by INTEGER,
            closed_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT
        );
        CREATE TABLE performance_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            manager_id INTEGER,
            position_title_snapshot TEXT,
            position_grade_snapshot TEXT,
            competency_position_index INTEGER,
            status TEXT NOT NULL DEFAULT "draft",
            launch_batch_no INTEGER,
            launched_at TEXT,
            self_submitted_at TEXT,
            self_questionnaire_submitted_at TEXT,
            self_matrix_submitted_at TEXT,
            manager_submitted_at TEXT,
            manager_matrix_submitted_at TEXT,
            hr_closed_at TEXT,
            hr_closed_by INTEGER,
            meeting_completed_at TEXT,
            meeting_completed_by INTEGER,
            meeting_notes TEXT,
            next_year_actions TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(cycle_id, user_id)
        );
        CREATE TABLE performance_review_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            review_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            answer_scope TEXT NOT NULL,
            answer_value TEXT,
            question_label_snapshot TEXT NOT NULL,
            question_type_snapshot TEXT NOT NULL,
            answered_by INTEGER,
            answered_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(review_id, question_id, answer_scope)
        );
        CREATE TABLE performance_review_competency_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            review_id INTEGER NOT NULL,
            competency_key TEXT NOT NULL,
            answer_scope TEXT NOT NULL,
            score INTEGER,
            comment TEXT,
            competency_name_snapshot TEXT NOT NULL,
            competency_description_snapshot TEXT,
            level_1_snapshot TEXT,
            level_2_snapshot TEXT,
            level_3_snapshot TEXT,
            level_4_snapshot TEXT,
            level_5_snapshot TEXT,
            required_level_snapshot INTEGER,
            answered_by INTEGER,
            answered_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(review_id, competency_key, answer_scope)
        );
        CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            task_id INTEGER,
            type TEXT NOT NULL,
            body TEXT NOT NULL,
            target_url TEXT,
            read_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE performance_review_cycle_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            notification_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(cycle_id, user_id)
        );
        CREATE TABLE performance_review_stage_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            review_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            stage TEXT NOT NULL,
            notification_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(review_id, user_id, stage)
        );
    ');
    $pdo->exec("INSERT INTO positions (id, title, grade, competency_position_index) VALUES (1, 'Инженер-проектировщик', 'N-11', 2), (2, 'Руководитель группы', 'N-7', 11)");
    $pdo->exec("INSERT INTO users (id, name, email, role, department, manager_id, position_id, is_active) VALUES
        (1, 'HR', 'hr@example.local', 'hr', 'HR', NULL, NULL, 1),
        (2, 'Директор', 'director@example.local', 'director', 'Дирекция', NULL, NULL, 1),
        (3, 'Руководитель', 'manager@example.local', 'department_head', 'ОВ', NULL, 2, 1),
        (4, 'Сотрудник', 'employee@example.local', 'engineer', 'ОВ', 3, 1, 1),
        (5, 'Чужой', 'other@example.local', 'engineer', 'АР', NULL, 1, 1),
        (6, 'Новый руководитель', 'new-manager@example.local', 'department_head', 'ВК', NULL, 2, 1),
        (7, 'Архивный руководитель', 'former-manager@example.local', 'department_head', 'ВК', NULL, 2, 0)
    ");

    return $pdo;
}

/** @return array<int, string> */
function performance_review_answers(array $review): array
{
    $answers = [];
    foreach ($review['questions'] as $question) {
        if (in_array((string) $question['answer_scope'], ['self', 'both'], true)) {
            $answers[(int) $question['id']] = 'Конкретный ответ на вопрос ' . (int) $question['id'];
        }
    }

    return $answers;
}

/** @return array<string, int> */
function performance_review_scores(array $review, int $score): array
{
    $scores = [];
    foreach (array_keys((array) ($review['competency_matrix']['competencies'] ?? [])) as $key) {
        $scores[(string) $key] = $score;
    }

    return $scores;
}

test('роль HR имеет только HR-доступ по умолчанию', function (): void {
    assert_same('HR', RoleService::label('hr'));
    assert_same('/hr', RoleService::homePath('hr'));
    assert_same([RoleService::CAP_LOCIA, RoleService::CAP_HR], RoleService::defaultCapabilities('hr'));
    assert_same(true, PermissionService::canManagePerformanceReviews(['role' => 'hr']));
    assert_same(true, PermissionService::canManagePerformanceReviews(['role' => 'director']));
    assert_same(false, PermissionService::canManagePerformanceReviews(['role' => 'engineer']));
});

test('ежегодный Performance Review проходит три этапа и сохраняет snapshots', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateStmt = $pdo->prepare('SELECT id FROM performance_review_templates WHERE title = ?');
    $templateStmt->execute([PerformanceReviewService::ANNUAL_TEMPLATE_TITLE]);
    $templateId = (int) $templateStmt->fetchColumn();
    assert_true($templateId > 0, 'annual template is seeded');
    assert_same('17', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_questions WHERE template_id = ' . $templateId)->fetchColumn());

    $cycleId = $service->createCycle([
        'template_id' => $templateId,
        'title' => 'Performance Review 2026',
        'review_year' => 2026,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'response_deadline' => '2026-09-01',
        'employee_ids' => [4, 5],
    ], 1);
    $reviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE cycle_id = ' . $cycleId . ' AND user_id = 4')->fetchColumn();
    $pendingReviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE cycle_id = ' . $cycleId . ' AND user_id = 5')->fetchColumn();
    assert_same('draft', (string) $pdo->query('SELECT status FROM performance_review_cycles WHERE id = ' . $cycleId)->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    assert_throws(static fn () => $service->review($reviewId, ['id' => 4, 'role' => 'engineer']), 'черновик ещё не доступен сотруднику');
    assert_throws(static fn () => $service->createCycle([
        'template_id' => $templateId,
        'title' => 'Дубликат',
        'review_year' => 2026,
        'employee_ids' => [4],
    ], 1), 'за один год может быть только один цикл');

    $firstLaunch = $service->openCycle($cycleId, 1, [4]);
    assert_same(1, $firstLaunch['batch_no'], 'first pilot is recorded as batch one');
    assert_same(1, $firstLaunch['notification_count'], 'cycle opening notifies only the pilot employee');
    assert_same('active', (string) $pdo->query('SELECT status FROM performance_review_cycles WHERE id = ' . $cycleId)->fetchColumn());
    assert_same('self_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $pendingReviewId)->fetchColumn());
    assert_throws(static fn () => $service->review($pendingReviewId, ['id' => 5, 'role' => 'engineer']), 'сотрудник вне пилота ещё не видит ревью');
    assert_same([], $service->reviewsForUser(['id' => 5, 'role' => 'engineer']), 'черновик участника не появляется в постоянной ссылке');
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM notifications WHERE type = "performance_review_opened" AND target_url = "/profile#performance-review"')->fetchColumn());
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_cycle_notices')->fetchColumn());
    $secondLaunch = $service->openCycle($cycleId, 1, [5]);
    assert_same(2, $secondLaunch['batch_no'], 'remaining employee joins a separate second batch');
    assert_same(1, $secondLaunch['notification_count'], 'second batch receives its own notice');
    assert_same('self_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $pendingReviewId)->fetchColumn());
    assert_same('2', (string) $pdo->query('SELECT launch_batch_no FROM performance_reviews WHERE id = ' . $pendingReviewId)->fetchColumn());
    assert_same('2', (string) $pdo->query('SELECT COUNT(*) FROM notifications WHERE type = "performance_review_opened" AND target_url = "/profile#performance-review"')->fetchColumn());
    assert_throws(static fn () => $service->openCycle($cycleId, 1, [5]), 'повторный запуск не создаёт дубль');
    $workspace = $service->cycleWorkspace($cycleId);
    assert_same(2, count($workspace['batches']), 'cycle workspace keeps a visible history of batches');
    assert_same([], $workspace['pending_participants'], 'all selected participants are launched');

    $employee = ['id' => 4, 'role' => 'engineer'];
    $manager = ['id' => 3, 'role' => 'department_head'];
    $hr = ['id' => 1, 'role' => 'hr'];
    $director = ['id' => 2, 'role' => 'director'];
    $employeeReview = $service->review($reviewId, $employee);
    assert_same(22, count($employeeReview['competency_matrix']['competencies'] ?? []), 'all competencies are in cycle snapshot');
    assert_same(2, (int) $employeeReview['competency_position_index'], 'position matches attached matrix');
    assert_same(false, $employeeReview['visibility']['target'], 'target levels are hidden while employee is scoring');
    assert_same(false, isset($employeeReview['competency_matrix']['competencies']['1']['required']), 'hidden target is removed server-side');

    $answers = performance_review_answers($employeeReview);
    $firstQuestion = (int) array_key_first($answers);
    $service->saveSelfQuestionnaireDraft($reviewId, ['answers' => [$firstQuestion => 'Черновик']], $employee);
    assert_same('Черновик', (string) $pdo->query('SELECT answer_value FROM performance_review_answers LIMIT 1')->fetchColumn());
    assert_throws(static fn () => $service->submitCompetencyReview($reviewId, 'manager', ['scores' => []], $manager), 'матрица закрыта до завершения анкеты');

    $service->submitSelfQuestionnaire($reviewId, ['answers' => $answers], $employee);
    assert_same('self_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    assert_throws(static fn () => $service->submitCompetencyReview($reviewId, 'manager', ['scores' => performance_review_scores($service->review($reviewId, $manager), 4)], $manager), 'руководитель ждёт завершения матрицы сотрудника');
    $service->saveCompetencyDraft($reviewId, 'self', ['scores' => ['1' => 2], 'comments' => ['1' => 'Черновик сотрудника']], $employee);

    $blindManagerReview = $service->review($reviewId, $manager);
    assert_same(false, $blindManagerReview['visibility']['self']);
    assert_same([], array_filter($blindManagerReview['answers'], static fn (array $scopes): bool => isset($scopes['self'])));
    assert_same([], array_filter($blindManagerReview['competency_scores'], static fn (array $scopes): bool => isset($scopes['self'])));

    $employeeReview = $service->review($reviewId, $employee);
    $service->submitCompetencyReview($reviewId, 'self', ['scores' => performance_review_scores($employeeReview, 3)], $employee);
    assert_same('manager_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_stage_notices WHERE review_id = ' . $reviewId . ' AND stage = "manager_ready"')->fetchColumn());
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 3 AND type = "performance_review_manager_ready" AND target_url = "/performance-review/manager"')->fetchColumn());
    assert_same('ready', (string) $service->managerReviewsForUser($manager)[0]['manager_state']);
    assert_same([], $service->reviewsForUser($manager), 'личная страница руководителя не превращается в список подчинённых');
    $employeeBeforeManager = $service->review($reviewId, $employee);
    assert_same(false, $employeeBeforeManager['visibility']['manager']);

    $managerReview = $service->review($reviewId, $manager);
    $service->submitCompetencyReview($reviewId, 'manager', ['scores' => performance_review_scores($managerReview, 4)], $manager);
    assert_same('hr_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    assert_same('meeting_ready', (string) $service->managerReviewsForUser($manager)[0]['manager_state'], 'после оценки руководителю остаётся финальная встреча');
    assert_same('meeting_ready', (string) $service->workdayActionsForUser($manager)['manager_ready'][0]['manager_state'], 'финальная встреча остаётся действием в Моём дне');
    $managerAfterSubmit = $service->review($reviewId, $manager);
    assert_same(true, $managerAfterSubmit['visibility']['self']);
    assert_same(true, $managerAfterSubmit['visibility']['target']);
    assert_same(3, (int) $managerAfterSubmit['competency_scores']['1']['self']['score']);
    $employeeAfterBoth = $service->review($reviewId, $employee);
    assert_same(true, $employeeAfterBoth['visibility']['comparison']);
    assert_same(4, (int) $employeeAfterBoth['competency_scores']['1']['manager']['score']);
    $summary = $service->cycleSummary($cycleId, $hr);
    assert_same(2, (int) $summary['metrics']['total']);
    assert_same(1, (int) $summary['metrics']['paired'], 'сводка считает только завершённые пары');
    $pairedParticipants = array_values(array_filter($summary['participants'], static fn (array $row): bool => !empty($row['paired'])));
    assert_same(-1.0, (float) $pairedParticipants[0]['averages']['delta']);
    assert_same(22, count($summary['competencies']));
    assert_throws(static fn () => $service->cycleSummary($cycleId, $employee), 'сводка закрыта от сотрудника');

    $pdo->exec('UPDATE performance_review_questions SET label = "Изменённый исходный вопрос" WHERE id = ' . $firstQuestion);
    $hrReview = $service->review($reviewId, $hr);
    assert_true((string) $hrReview['questions'][0]['label'] !== 'Изменённый исходный вопрос', 'questionnaire remains frozen in cycle snapshot');
    $meetingInput = [
        'meeting_notes' => 'Обсудили результаты и зоны роста.',
        'next_year_actions' => 'Освоить новый инструмент и провести внутреннее обучение.',
    ];
    assert_throws(static fn () => $service->completeMeeting($reviewId, $meetingInput, $hr), 'HR контролирует процесс, но не закрывает ревью за руководителя');
    assert_throws(static fn () => $service->completeMeeting($reviewId, $meetingInput, $director), 'директор контролирует процесс, но не закрывает ревью за руководителя');
    $service->completeMeeting($reviewId, [
        'meeting_notes' => 'Обсудили результаты и зоны роста.',
        'next_year_actions' => 'Освоить новый инструмент и провести внутреннее обучение.',
    ], $manager);
    assert_same('closed', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    $completedBy = $pdo->query('SELECT meeting_completed_by, hr_closed_by FROM performance_reviews WHERE id = ' . $reviewId)->fetch();
    assert_same(3, (int) $completedBy['meeting_completed_by'], 'встреча зафиксирована назначенным руководителем');
    assert_same(3, (int) $completedBy['hr_closed_by'], 'совместимое поле аудита хранит того же руководителя');
    assert_same('done', (string) $service->managerReviewsForUser($manager)[0]['manager_state'], 'после фиксации итогов ревью уходит из активной очереди');
    $closedSummary = $service->cycleSummary($cycleId, $hr);
    $closedParticipant = array_values(array_filter($closedSummary['participants'], static fn (array $row): bool => (int) $row['id'] === $reviewId))[0];
    assert_same('Обсудили результаты и зоны роста.', (string) $closedParticipant['meeting_notes'], 'сводка сохраняет комментарий руководителя для итоговой выгрузки');
    assert_same('Освоить новый инструмент и провести внутреннее обучение.', (string) $closedParticipant['next_year_actions'], 'сводка сохраняет шаги следующего года для итоговой выгрузки');
    assert_throws(static fn () => $service->saveSelfQuestionnaireDraft($reviewId, ['answers' => [$firstQuestion => 'Изменение']], $employee), 'закрытое ревью нельзя менять');

    $service->closeCycle($cycleId, 1);
    assert_throws(static fn () => $service->review($reviewId, $employee), 'после закрытия аудитории сотрудник не видит модуль');
    assert_same('Обсудили результаты и зоны роста.', (string) $service->review($reviewId, $hr)['meeting_notes']);
    Database::reset();
});

test('видимость ревью ограничена сотрудником, руководителем и HR', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $stmt = $pdo->prepare('SELECT id FROM performance_review_templates WHERE title = ?');
    $stmt->execute([PerformanceReviewService::ANNUAL_TEMPLATE_TITLE]);
    $cycleId = $service->createCycle([
        'template_id' => (int) $stmt->fetchColumn(),
        'title' => 'Visibility 2027',
        'review_year' => 2027,
        'employee_ids' => [4],
    ], 1);
    $service->openCycle($cycleId, 1, [4]);
    $reviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE user_id = 4')->fetchColumn();

    assert_true($service->review($reviewId, ['id' => 4, 'role' => 'engineer'])['id'] > 0, 'сотрудник видит своё ревью');
    assert_true($service->review($reviewId, ['id' => 3, 'role' => 'department_head'])['id'] > 0, 'руководитель видит ревью подчинённого');
    assert_true($service->review($reviewId, ['id' => 1, 'role' => 'hr'])['id'] > 0, 'HR видит ревью');
    assert_true($service->review($reviewId, ['id' => 2, 'role' => 'director'])['id'] > 0, 'директор видит ревью');
    assert_throws(static fn () => $service->review($reviewId, ['id' => 5, 'role' => 'engineer']), 'чужой сотрудник не видит ревью');

    $pdo->exec('UPDATE performance_reviews SET manager_id = 2 WHERE id = ' . $reviewId);
    $employee = ['id' => 4, 'role' => 'engineer'];
    $employeeReview = $service->review($reviewId, $employee);
    $service->submitSelfQuestionnaire($reviewId, ['answers' => performance_review_answers($employeeReview)], $employee);
    $service->submitCompetencyReview($reviewId, 'self', ['scores' => performance_review_scores($employeeReview, 3)], $employee);
    $directorAsManager = $service->review($reviewId, ['id' => 2, 'role' => 'director']);
    assert_same(false, $directorAsManager['visibility']['self'], 'директор, назначенный руководителем, не обходит слепую оценку');
    assert_same([], array_filter($directorAsManager['competency_scores'], static fn (array $scopes): bool => isset($scopes['self'])));
    Database::reset();
});

test('переназначение руководителя очищает старое действие и уведомляет нового', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $cycleId = $service->createCycle(['template_id' => $templateId, 'title' => 'Переназначение', 'review_year' => 2032, 'employee_ids' => [4]], 1);
    $service->openCycle($cycleId, 1, [4]);
    $reviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE user_id = 4')->fetchColumn();
    $employee = ['id' => 4, 'role' => 'engineer'];
    $oldManager = ['id' => 3, 'role' => 'department_head'];
    $hr = ['id' => 1, 'role' => 'hr'];
    $review = $service->review($reviewId, $employee);
    $service->submitSelfQuestionnaire($reviewId, ['answers' => performance_review_answers($review)], $employee);
    $service->submitCompetencyReview($reviewId, 'self', ['scores' => performance_review_scores($service->review($reviewId, $employee), 3)], $employee);

    assert_throws(static fn () => $service->assignManager($reviewId, 4, $hr), 'сотрудник не может быть собственным руководителем ревью');
    assert_throws(static fn () => $service->assignManager($reviewId, 7, $hr), 'неактивного руководителя нельзя назначить');
    $service->assignManager($reviewId, 6, $hr);

    assert_same([], $service->managerReviewsForUser($oldManager), 'старый руководитель больше не получает действие');
    assert_same('ready', (string) $service->managerReviewsForUser(['id' => 6, 'role' => 'department_head'])[0]['manager_state']);
    assert_same('0', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_stage_notices WHERE review_id = ' . $reviewId . ' AND user_id = 3 AND stage = "manager_ready"')->fetchColumn());
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 6 AND type = "performance_review_manager_ready" AND target_url = "/performance-review/manager"')->fetchColumn());
    Database::reset();
});

test('переназначение после заполненной матрицы сохраняет её для нового руководителя', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $cycleId = $service->createCycle(['template_id' => $templateId, 'title' => 'Переназначение после оценки', 'review_year' => 2034, 'employee_ids' => [4]], 1);
    $service->openCycle($cycleId, 1, [4]);
    $reviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE user_id = 4')->fetchColumn();
    $employee = ['id' => 4, 'role' => 'engineer'];
    $oldManager = ['id' => 3, 'role' => 'department_head'];
    $hr = ['id' => 1, 'role' => 'hr'];

    $service->submitSelfQuestionnaire($reviewId, ['answers' => performance_review_answers($service->review($reviewId, $employee))], $employee);
    $service->submitCompetencyReview($reviewId, 'self', ['scores' => performance_review_scores($service->review($reviewId, $employee), 3)], $employee);
    $service->submitCompetencyReview($reviewId, 'manager', ['scores' => performance_review_scores($service->review($reviewId, $oldManager), 4)], $oldManager);
    assert_same('hr_review', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());

    $service->assignManager($reviewId, 6, $hr);

    $updated = $pdo->query('SELECT manager_id, status, manager_matrix_submitted_at, manager_submitted_at FROM performance_reviews WHERE id = ' . $reviewId)->fetch();
    assert_same(6, (int) $updated['manager_id']);
    assert_same('hr_review', (string) $updated['status']);
    assert_true($updated['manager_matrix_submitted_at'] !== null);
    assert_true($updated['manager_submitted_at'] !== null);
    assert_same('22', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_competency_scores WHERE review_id = ' . $reviewId . ' AND answer_scope = "manager"')->fetchColumn());
    assert_same('22', (string) $pdo->query('SELECT COUNT(*) FROM performance_review_competency_scores WHERE review_id = ' . $reviewId . ' AND answer_scope = "self"')->fetchColumn());
    assert_same('3', (string) $pdo->query('SELECT answered_by FROM performance_review_competency_scores WHERE review_id = ' . $reviewId . ' AND answer_scope = "manager" LIMIT 1')->fetchColumn());
    assert_same('meeting_ready', (string) $service->managerReviewsForUser(['id' => 6, 'role' => 'department_head'])[0]['manager_state']);
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 6 AND type = "performance_review_meeting_ready" AND target_url = "/performance-review/' . $reviewId . '"')->fetchColumn());
    $service->completeMeeting($reviewId, ['meeting_notes' => 'Итоги сохранены.', 'next_year_actions' => 'План развития согласован.'], ['id' => 6, 'role' => 'department_head']);
    assert_same('closed', (string) $pdo->query('SELECT status FROM performance_reviews WHERE id = ' . $reviewId)->fetchColumn());
    Database::reset();
});

test('смена руководителя возвращает в существующую карточку ревью', function (): void {
    $controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/HrController.php');
    $start = strpos($controller, 'public function assignManager');
    $end = strpos($controller, 'public function templates', $start ?: 0);
    $method = substr($controller, (int) $start, $end === false ? null : $end - (int) $start);
    assert_true(str_contains($method, "redirect('/performance-review/' . \$id);"));
});

test('руководительский реестр ограничен назначенными ревью и не раскрывает незавершённые оценки', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $cycleId = $service->createCycle(['template_id' => $templateId, 'title' => 'Реестр руководителя', 'review_year' => 2033, 'employee_ids' => [4, 5]], 1);
    $service->openCycle($cycleId, 1, [4, 5]);
    $pdo->exec('UPDATE performance_reviews SET manager_id = 6 WHERE user_id = 5');

    $report = $service->managerReportForUser(['id' => 3, 'role' => 'department_head']);
    assert_same(1, count($report['reviews']));
    assert_same(4, (int) $report['reviews'][0]['user_id']);
    assert_same(null, $report['reviews'][0]['averages']['self']);
    assert_same(null, $report['reviews'][0]['averages']['manager']);
    Database::reset();
});

test('черновик ежегодного цикла редактируется только до первой волны', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $stmt = $pdo->prepare('SELECT id FROM performance_review_templates WHERE title = ?');
    $stmt->execute([PerformanceReviewService::ANNUAL_TEMPLATE_TITLE]);
    $templateId = (int) $stmt->fetchColumn();
    $cycleId = $service->createCycle([
        'template_id' => $templateId,
        'title' => 'Черновик 2028',
        'review_year' => 2028,
        'period_start' => '2028-01-01',
        'period_end' => '2028-12-31',
        'response_deadline' => '2028-09-01',
        'employee_ids' => [4, 5],
    ], 1);

    assert_same([4, 5], $service->draftCycleForEdit($cycleId)['employee_ids']);
    $service->updateDraftCycle($cycleId, [
        'template_id' => $templateId,
        'title' => 'Черновик 2028 уточнён',
        'review_year' => 2028,
        'period_start' => '2027-07-01',
        'period_end' => '2028-06-30',
        'response_deadline' => '2028-08-15',
        'employee_ids' => [4],
    ], 1);
    $cycle = $service->draftCycleForEdit($cycleId);
    assert_same('Черновик 2028 уточнён', (string) $cycle['title']);
    assert_same('2027-07-01', (string) $cycle['period_start']);
    assert_same([4], $cycle['employee_ids']);
    assert_same('1', (string) $pdo->query('SELECT COUNT(*) FROM performance_reviews WHERE cycle_id = ' . $cycleId)->fetchColumn());
    assert_throws(static fn () => $service->updateDraftCycle($cycleId, [
        'template_id' => $templateId,
        'title' => 'Неверный период',
        'review_year' => 2028,
        'period_start' => '2028-12-31',
        'period_end' => '2028-01-01',
        'employee_ids' => [4],
    ], 1), 'обратный диапазон периода не сохраняется');

    $service->openCycle($cycleId, 1, [4]);
    assert_throws(static fn () => $service->draftCycleForEdit($cycleId), 'после запуска первой волны форма редактирования закрыта');
    assert_throws(static fn () => $service->updateDraftCycle($cycleId, [
        'template_id' => $templateId,
        'title' => 'Поздняя правка',
        'review_year' => 2028,
        'employee_ids' => [4],
    ], 1), 'после запуска первой волны сервер запрещает правку');
    Database::reset();
});

test('тестовые прогоны можно создавать многократно и они не занимают годовой цикл', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $payload = [
        'template_id' => $templateId,
        'cycle_kind' => 'test',
        'review_year' => 2029,
        'employee_ids' => [4],
    ];
    $first = $service->createCycle(array_merge($payload, ['title' => 'Тест 1']), 1);
    $second = $service->createCycle(array_merge($payload, ['title' => 'Тест 2']), 1);
    $annual = $service->createCycle(array_merge($payload, ['title' => 'Годовой 2029', 'cycle_kind' => 'annual']), 1);
    assert_true($first > 0 && $second > $first && $annual > $second, 'повторные тесты и годовой цикл созданы');
    assert_same('test', (string) $pdo->query('SELECT cycle_kind FROM performance_review_cycles WHERE id = ' . $first)->fetchColumn());
    assert_throws(static fn () => $service->createCycle(array_merge($payload, ['title' => 'Второй годовой', 'cycle_kind' => 'annual']), 1), 'второй годовой цикл запрещён');
    Database::reset();
});

test('запущенный тест можно сделать официальным и временно закрыть без потери данных', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $cycleId = $service->createCycle([
        'template_id' => $templateId,
        'cycle_kind' => 'test',
        'title' => 'Пилот 2031',
        'review_year' => 2031,
        'employee_ids' => [4],
    ], 1);
    $service->openCycle($cycleId, 1, [4]);
    $reviewId = (int) $pdo->query('SELECT id FROM performance_reviews WHERE cycle_id = ' . $cycleId)->fetchColumn();
    $employee = ['id' => 4, 'role' => 'engineer'];
    $review = $service->review($reviewId, $employee);
    $firstQuestion = (int) array_key_first(performance_review_answers($review));
    $service->saveSelfQuestionnaireDraft($reviewId, ['answers' => [$firstQuestion => 'Сохранённый черновик']], $employee);

    $service->makeCycleOfficial($cycleId);
    assert_same('annual', (string) $pdo->query('SELECT cycle_kind FROM performance_review_cycles WHERE id = ' . $cycleId)->fetchColumn());
    assert_true(str_contains((string) $pdo->query('SELECT body FROM notifications LIMIT 1')->fetchColumn(), 'ежегодный Performance Review'));

    $service->closeCycle($cycleId, 1);
    assert_same([], $service->reviewsForUser($employee));
    $service->reopenCycle($cycleId);
    assert_same(1, count($service->reviewsForUser($employee)));
    assert_same('Сохранённый черновик', (string) $pdo->query('SELECT answer_value FROM performance_review_answers WHERE review_id = ' . $reviewId)->fetchColumn());
    Database::reset();
});

test('тест нельзя сделать официальным, если годовой цикл этого года уже существует', function (): void {
    $pdo = performance_review_test_pdo();
    $service = new PerformanceReviewService($pdo);
    $service->seedDefaults();
    $templateId = (int) $pdo->query("SELECT id FROM performance_review_templates WHERE title = 'Ежегодный Performance Review'")->fetchColumn();
    $service->createCycle(['template_id' => $templateId, 'cycle_kind' => 'annual', 'title' => 'Годовой', 'review_year' => 2032, 'employee_ids' => [4]], 1);
    $testId = $service->createCycle(['template_id' => $templateId, 'cycle_kind' => 'test', 'title' => 'Пилот', 'review_year' => 2032, 'employee_ids' => [5]], 1);
    assert_throws(static fn () => $service->makeCycleOfficial($testId), 'годовой цикл остаётся единственным');
    assert_same('test', (string) $pdo->query('SELECT cycle_kind FROM performance_review_cycles WHERE id = ' . $testId)->fetchColumn());
    Database::reset();
});

test('миграция старых циклов в тестовые сохраняет всю историю ревью', function (): void {
    $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/093_performance_review_batches_and_legacy_tests.sql');
    assert_true(str_contains($migration, "SET cycle_kind = 'test'"), 'existing cycles are explicitly marked as tests');
    assert_true(str_contains($migration, 'r.launch_batch_no = 1'), 'already launched reviews receive a historical first batch');
    assert_true(!preg_match('/\bDELETE\b/i', $migration), 'migration does not delete cycles, reviews, answers, notices or snapshots');
});
