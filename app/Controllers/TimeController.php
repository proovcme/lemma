<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\TimeApprovalService;
use App\Services\TimeService;
use Throwable;

final class TimeController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        $weekStart = TimeService::weekStart($_GET['week'] ?? $_GET['date'] ?? null);
        $service = new TimeService($this->db());
        $approvalService = new TimeApprovalService($this->db());
        $model = $service->weekModel($user, $weekStart);
        $selectedDate = $this->normalizedDate($_GET['date'] ?? date('Y-m-d'));
        if (!in_array($selectedDate, $model['dates'], true)) {
            $today = date('Y-m-d');
            $selectedDate = in_array($today, $model['dates'], true) ? $today : (string) $model['dates'][0];
        }
        $monthStart = TimeApprovalService::monthStart($selectedDate);
        $monthReview = $approvalService->reviewForUser((int) $user['id'], $monthStart);
        $monthLockedForEdit = $monthReview && (string) $monthReview['status'] === 'locked';
        $headerActions = [];
        if (!$monthLockedForEdit) {
            $headerActions[] = ['label' => 'Сохранить неделю', 'type' => 'button', 'buttonType' => 'submit', 'form' => 'time-week-form', 'class' => 'btn-red'];
        }

        $this->render('time/index', [
            'title' => 'Моё время',
            'subtitle' => 'Табель 8 часов, пакетное списание и факт по задачам',
            'headerActions' => $headerActions,
            'model' => $model,
            'selectedDate' => $selectedDate,
            'categories' => TimeService::CATEGORIES,
            'phases' => TimeService::PHASES,
            'monthStart' => $monthStart,
            'monthEnd' => TimeApprovalService::monthEnd($monthStart),
            'monthReview' => $monthReview,
            'monthLockedForEdit' => $monthLockedForEdit,
        ]);
    }

    public function saveWeek(): void
    {
        $user = require_auth();
        $weekStart = TimeService::weekStart($_POST['week'] ?? null);
        $service = new TimeService($this->db());

        try {
            $minutes = $service->saveWeek($user, $weekStart, $_POST);
            flash('success', 'Неделя сохранена: ' . TimeService::minutesToHours($minutes) . ' ч.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/time?week=' . $weekStart);
    }

    public function distributeDay(): void
    {
        $user = require_auth();
        $date = $this->normalizedDate($_POST['work_date'] ?? date('Y-m-d'));
        $weekStart = TimeService::weekStart($date);
        $service = new TimeService($this->db());

        try {
            if (($_POST['action'] ?? '') === 'repeat_previous') {
                $minutes = $service->repeatPreviousDay($user, $date);
                flash('success', 'День повторён: ' . TimeService::minutesToHours($minutes) . ' ч.');
            } else {
                $taskIds = is_array($_POST['task_ids'] ?? null) ? $_POST['task_ids'] : [];
                $minutes = TimeService::parseHours($_POST['total_hours'] ?? '');
                $method = in_array((string) ($_POST['method'] ?? 'even'), ['even', 'planned'], true)
                    ? (string) $_POST['method']
                    : 'even';
                $phase = (string) ($_POST['phase'] ?? 'auto');
                $written = $service->distributeDay($user, $date, $taskIds, $minutes, $method, $phase);
                flash('success', 'Пакетное списание создано: ' . TimeService::minutesToHours($written) . ' ч.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/time?week=' . $weekStart . '&date=' . $date);
    }

    public function taskEntry(): void
    {
        $user = require_auth();
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $date = $this->normalizedDate($_POST['work_date'] ?? date('Y-m-d'));
        $phase = (string) ($_POST['phase'] ?? 'execution');
        $service = new TimeService($this->db());

        try {
            // Quick buttons ([+0.5 ч] / [+1 ч]) post a preset `quick` value, bypass the required
            // `hours` input and ADD to the day's total; the manual field replaces the day's value.
            $quick = trim((string) ($_POST['quick'] ?? ''));
            if ($quick !== '') {
                $minutes = $service->addTaskQuickMinutes($user, $taskId, $date, TimeService::parseHours($quick), $phase);
            } else {
                $minutes = TimeService::parseHours((string) ($_POST['hours'] ?? ''));
                $service->saveTaskEntry($user, $taskId, $date, $minutes, $phase);
            }
            flash('success', 'Время списано: ' . TimeService::minutesToHours($minutes) . ' ч.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        $back = trim((string) ($_POST['back'] ?? ''));
        $allowedBack = $back !== '' && (str_starts_with($back, '/tasks/') || $back === '/my-day' || str_starts_with($back, '/my-day?'));
        redirect($allowedBack ? $back : '/time?week=' . TimeService::weekStart($date) . '&date=' . $date);
    }

    public function approvals(): void
    {
        $user = require_auth();
        if (!PermissionService::canReviewTime($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Приемка времени доступна ГИПу, руководителю отдела, директору и администратору.']);
            return;
        }
        $monthStart = TimeApprovalService::monthStart($_GET['month'] ?? date('Y-m-d'));
        $service = new TimeApprovalService($this->db());

        $this->render('time/approvals', [
            'title' => 'Приёмка времени',
            'subtitle' => 'Месячный срез списанного времени',
            'headerActions' => [
                ['label' => 'Моё время', 'href' => '/time?week=' . TimeService::weekStart($monthStart), 'class' => 'btn-outline'],
            ],
            'monthStart' => $monthStart,
            'monthEnd' => TimeApprovalService::monthEnd($monthStart),
            'prevMonth' => (new \DateTimeImmutable($monthStart))->modify('-1 month')->format('Y-m-d'),
            'nextMonth' => (new \DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d'),
            'reviews' => $service->reviewsForUser($user, $monthStart),
            'canDirectorApprove' => PermissionService::canDirectorApproveTime($user),
            'viewer' => $user,
        ]);
    }

    public function gipApproveMonth(int $id): void
    {
        $this->handleApprovalAction(static function (TimeApprovalService $service, array $user, string $monthStart) use ($id): void {
            $service->gipApproveSnapshot($id, $monthStart, $user);
        });
    }

    public function departmentApproveMonth(int $id): void
    {
        $this->handleApprovalAction(static function (TimeApprovalService $service, array $user, string $monthStart) use ($id): void {
            $service->departmentApproveSnapshot($id, $monthStart, $user);
        });
    }

    public function directorApproveMonth(int $id): void
    {
        $this->handleApprovalAction(static function (TimeApprovalService $service, array $user, string $monthStart) use ($id): void {
            $service->closeMonthSnapshot($id, $monthStart, $user);
        });
    }

    public function returnMonth(int $id): void
    {
        $comment = (string) ($_POST['comment'] ?? '');
        $this->handleApprovalAction(static function (TimeApprovalService $service, array $user, string $monthStart) use ($id, $comment): void {
            $service->returnSnapshot($id, $monthStart, $user, $comment);
        });
    }

    public function reopenMonth(int $id): void
    {
        $comment = (string) ($_POST['comment'] ?? '');
        $this->handleApprovalAction(static function (TimeApprovalService $service, array $user, string $monthStart) use ($id, $comment): void {
            $service->reopenLockedMonthForCorrection($id, $monthStart, $user, $comment);
        });
    }

    private function handleApprovalAction(callable $action): void
    {
        $user = require_auth();
        if (!PermissionService::canReviewTime($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Приемка времени доступна ГИПу, руководителю отдела, директору и администратору.']);
            return;
        }
        $monthStart = TimeApprovalService::monthStart($_POST['month'] ?? date('Y-m-d'));
        $service = new TimeApprovalService($this->db());

        try {
            $action($service, $user, $monthStart);
            flash('success', 'Статус месяца обновлен.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/time/approvals?month=' . $monthStart);
    }

    private function normalizedDate(mixed $value): string
    {
        $date = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return date('Y-m-d');
        }

        return $date;
    }
}
