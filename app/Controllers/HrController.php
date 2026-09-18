<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PerformanceReviewService;
use App\Services\PerformanceReviewExportService;
use App\Services\PermissionService;
use RuntimeException;

final class HrController extends BaseController
{
    private function service(): PerformanceReviewService
    {
        return new PerformanceReviewService($this->db());
    }

    private function requireHrAccess(): array
    {
        $user = require_auth();
        if (!PermissionService::canOpenHr($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'HR-раздел доступен только по праву HR.']);
            exit;
        }

        return $user;
    }

    private function requireHrManager(): array
    {
        $user = $this->requireHrAccess();
        if (!PermissionService::canManagePerformanceReviews($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Управлять performance review может HR, директор или администратор.']);
            exit;
        }

        return $user;
    }

    public function index(): void
    {
        $user = $this->requireHrAccess();
        $this->render('hr/index', [
            'title' => 'Performance Review',
            'subtitle' => 'Циклы, черновики, партии и история',
            'dashboard' => $this->service()->dashboard($user),
            'canManage' => PermissionService::canManagePerformanceReviews($user),
        ]);
    }

    public function newCycle(): void
    {
        $this->requireHrManager();
        $this->render('hr/cycle-new', [
            'title' => 'Начать Performance Review',
            'subtitle' => 'Состав цикла и первый черновик',
            'templates' => $this->service()->templates(),
            'users' => $this->service()->activeUsers(),
        ]);
    }

    public function showCycle(int $id): void
    {
        $this->requireHrManager();
        try {
            $cycle = $this->service()->cycleWorkspace($id);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr');
        }
        $this->render('hr/cycle-show', [
            'title' => (string) ($cycle['title'] ?? 'Performance Review'),
            'subtitle' => 'Проверка состава и запуск партиями',
            'cycle' => $cycle,
        ]);
    }

    public function reviews(): void
    {
        require_auth();
        redirect('/profile#performance-review');
    }

    public function managerReviews(): void
    {
        $user = require_auth();
        $reviews = $this->service()->managerReviewsForUser($user);
        $this->render('hr/manager-reviews', [
            'title' => 'Оценки сотрудников',
            'subtitle' => 'Отдельная очередь руководителя',
            'reviews' => $reviews,
            'report' => $this->service()->managerReportForUser($user),
            'readyCount' => count(array_filter($reviews, static fn (array $review): bool => in_array((string) ($review['manager_state'] ?? ''), ['ready', 'meeting_ready'], true))),
        ]);
    }

    public function exportManagerReviews(): void
    {
        $user = require_auth();
        try {
            (new PerformanceReviewExportService())->downloadManager($this->service()->managerReportForUser($user));
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/performance-review/manager');
        }
    }

    public function hrReviews(): void
    {
        $this->requireHrManager();
        $this->render('hr/reviews', [
            'title' => 'Все Performance Review',
            'subtitle' => 'Контроль HR и директора',
            'reviews' => $this->service()->allReviews(),
            'canManage' => true,
        ]);
    }

    public function showReview(int $id): void
    {
        $user = require_auth();
        try {
            $review = $this->service()->review($id, $user);
        } catch (RuntimeException $e) {
            http_response_code(404);
            view('layouts/error', ['title' => 'Ревью не найдено', 'message' => $e->getMessage()]);
            return;
        }

        $this->render('hr/review', [
            'title' => 'Performance Review',
            'review' => $review,
            'users' => PermissionService::canManagePerformanceReviews($user) ? $this->service()->activeUsers() : [],
            'canManage' => PermissionService::canManagePerformanceReviews($user),
            'questionTypes' => PerformanceReviewService::QUESTION_TYPES,
            'questionScopes' => PerformanceReviewService::QUESTION_SCOPES,
            'statuses' => PerformanceReviewService::REVIEW_STATUSES,
            'viewer' => $user,
            'reviewContext' => (int) ($review['user_id'] ?? 0) === (int) ($user['id'] ?? 0)
                ? 'self'
                : ((int) ($review['manager_id'] ?? 0) === (int) ($user['id'] ?? 0) ? 'manager' : 'hr'),
        ]);
    }

    public function exportReview(int $id): void
    {
        $user = require_auth();
        try {
            (new PerformanceReviewExportService())->downloadReview($this->service()->review($id, $user));
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/performance-review/' . $id);
        }
    }

    public function cycleSummary(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            $summary = $this->service()->cycleSummary($id, $user);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr');
        }
        $this->render('hr/cycle-summary', [
            'title' => 'Сводка Performance Review',
            'subtitle' => 'Готовность, результаты и отклонения',
            'summary' => $summary,
        ]);
    }

    public function exportCycle(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            (new PerformanceReviewExportService())->downloadCycle($this->service()->cycleSummary($id, $user));
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr/cycles/' . $id . '/summary');
        }
    }

    public function submitSelf(int $id): void
    {
        $user = require_auth();
        try {
            $this->service()->saveSelfReview($id, $_POST, $user);
            flash('success', 'Самооценка отправлена руководителю.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function saveSelfDraft(int $id): void
    {
        $user = require_auth();
        try {
            $this->service()->saveSelfQuestionnaireDraft($id, $_POST, $user);
            flash('success', 'Черновик анкеты сохранён.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function saveCompetencyDraft(int $id, string $scope): void
    {
        $user = require_auth();
        try {
            $this->service()->saveCompetencyDraft($id, $scope, $_POST, $user);
            flash('success', 'Черновик оценки сохранён.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function submitCompetency(int $id, string $scope): void
    {
        $user = require_auth();
        try {
            $this->service()->submitCompetencyReview($id, $scope, $_POST, $user);
            flash('success', $scope === 'manager' ? 'Оценка руководителя завершена.' : 'Самооценка по матрице завершена.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function completeMeeting(int $id): void
    {
        $user = require_auth();
        try {
            $this->service()->completeMeeting($id, $_POST, $user);
            flash('success', 'Итоги очной встречи зафиксированы. Ревью закрыто.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function assignManager(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            $this->service()->assignManager($id, (int) ($_POST['manager_id'] ?? 0), $user);
            flash('success', 'Руководитель ревью обновлён.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/performance-review/' . $id);
    }

    public function templates(): void
    {
        $this->requireHrManager();
        $this->render('hr/templates', [
            'title' => 'Шаблоны Performance Review',
            'subtitle' => 'Конструктор вопросов без правки кода',
            'templates' => $this->service()->templates(),
            'questionTypes' => PerformanceReviewService::QUESTION_TYPES,
            'questionScopes' => PerformanceReviewService::QUESTION_SCOPES,
            'users' => $this->service()->activeUsers(),
        ]);
    }

    public function createTemplate(): void
    {
        $user = $this->requireHrManager();
        try {
            $this->service()->createTemplate($_POST, (int) $user['id']);
            flash('success', 'Шаблон создан.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/templates');
    }

    public function addQuestion(): void
    {
        $this->requireHrManager();
        try {
            $this->service()->addQuestion($_POST);
            flash('success', 'Вопрос добавлен.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/templates');
    }

    public function updateQuestion(int $id): void
    {
        $this->requireHrManager();
        try {
            $this->service()->updateQuestion($id, $_POST);
            flash('success', 'Вопрос сохранён.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/templates');
    }

    public function createCycle(): void
    {
        $user = $this->requireHrManager();
        try {
            $cycleId = $this->service()->createCycle($_POST, (int) $user['id']);
            flash('success', 'Черновик сохранён. Проверьте состав и запустите первую партию, когда всё готово.');
            redirect('/hr/cycles/' . $cycleId);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr/cycles/new');
        }
    }

    public function editCycle(int $id): void
    {
        $this->requireHrManager();
        try {
            $cycle = $this->service()->draftCycleForEdit($id);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr');
        }
        $this->render('hr/cycle-edit', [
            'title' => 'Редактирование Performance Review',
            'subtitle' => 'Черновик цикла до запуска первой группы',
            'cycle' => $cycle,
            'templates' => $this->service()->templates(),
            'users' => $this->service()->activeUsers(),
        ]);
    }

    public function updateCycle(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            $this->service()->updateDraftCycle($id, $_POST, (int) $user['id']);
            flash('success', 'Черновик цикла сохранён.');
            redirect('/hr/cycles/' . $id);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/hr/cycles/' . $id . '/edit');
        }
    }

    public function openCycle(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            $launch = $this->service()->openCycle($id, (int) $user['id'], (array) ($_POST['employee_ids'] ?? []));
            flash('success', 'Партия №' . $launch['batch_no'] . ' запущена для ' . $launch['participant_count']
                . ' сотрудников. Уведомлений создано: ' . $launch['notification_count'] . '.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/cycles/' . $id);
    }

    public function closeCycle(int $id): void
    {
        $user = $this->requireHrManager();
        try {
            $this->service()->closeCycle($id, (int) $user['id']);
            flash('success', 'Цикл закрыт для сотрудников. История сохранена для директора и HR.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/cycles/' . $id);
    }

    public function reopenCycle(int $id): void
    {
        $this->requireHrManager();
        try {
            $this->service()->reopenCycle($id);
            flash('success', 'Цикл снова открыт для заполнения. Участники продолжат с сохранённых ответов.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/cycles/' . $id);
    }

    public function makeCycleOfficial(int $id): void
    {
        $this->requireHrManager();
        try {
            $this->service()->makeCycleOfficial($id);
            flash('success', 'Тестовый цикл переведён в официальный. Все ответы и результаты сохранены.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/hr/cycles/' . $id);
    }
}
