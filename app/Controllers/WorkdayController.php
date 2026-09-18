<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\PerformanceReviewService;
use App\Services\TimeService;
use App\Services\VacationService;
use App\Services\WorkdayService;

final class WorkdayController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        $model = (new WorkdayService($this->db()))->model($user, $_GET);
        $vacations = new VacationService($this->db());
        $performanceReviewActions = (new PerformanceReviewService($this->db()))->workdayActionsForUser($user);
        $notificationStmt = $this->db()->prepare('
            SELECT id, type, body, target_url, created_at
            FROM notifications
            WHERE user_id = ? AND read_at IS NULL
            ORDER BY created_at DESC, id DESC
            LIMIT 5
        ');
        $notificationStmt->execute([(int) $user['id']]);

        $this->render('workday/index', [
            'title' => 'Мой день',
            'subtitle' => 'Что принять, что сделать, что проверить и где списать время',
            'model' => $model,
            'categories' => TimeService::CATEGORIES,
            'phases' => TimeService::PHASES,
            'filters' => $_GET,
            'vacations' => $vacations->forUser((int) $user['id']),
            'vacationSubstitutions' => $vacations->substitutionsFor((int) $user['id']),
            'vacationCandidates' => $this->db()->query("SELECT id, name, department FROM users WHERE is_active = 1 AND role <> 'admin' ORDER BY department, name")->fetchAll(),
            'dayNotifications' => $notificationStmt->fetchAll(),
            'performanceReviewActions' => $performanceReviewActions,
        ]);
    }

    public function createVacation(): void
    {
        $user = require_auth();
        try {
            $id = (new VacationService($this->db()))->create(
                (int) $user['id'],
                (string) ($_POST['date_from'] ?? ''),
                (string) ($_POST['date_to'] ?? ''),
                (int) ($_POST['substitute_user_id'] ?? 0),
                (string) ($_POST['note'] ?? ''),
                (int) $user['id']
            );
            AuditService::record('employee_vacation_created', ['vacation_id' => $id, 'employee_id' => (int) $user['id']]);
            flash('success', 'Режим «Отпуск» сохранён. Замена будет видна коллегам в задачах и команде.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable) {
            flash('error', 'Не удалось сохранить режим отпуска. Сообщите администратору.');
        }
        redirect('/my-day');
    }

    public function cancelVacation(int $id): void
    {
        $user = require_auth();
        $cancelled = (new VacationService($this->db()))->cancel($id, (int) $user['id'], (int) $user['id']);
        if ($cancelled) {
            AuditService::record('employee_vacation_cancelled', ['vacation_id' => $id, 'employee_id' => (int) $user['id']]);
            flash('success', 'Режим «Отпуск» отменён.');
        } else {
            flash('error', 'Действующий или будущий отпуск не найден.');
        }
        redirect('/my-day');
    }
}
