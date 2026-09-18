<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\BudgetService;
use App\Services\PermissionService;
use App\Services\ProjectPortfolioService;

final class DirectorController extends BaseController
{
    public function portfolio(): void
    {
        $this->requireDirector();
        $this->noStore();
        $this->render('director/portfolio', [
            'title' => 'Портфель проектов',
            'subtitle' => 'Ожидаемые расчёты и действующие проекты в одной воронке',
            'directorTab' => 'portfolio',
            'portfolio' => (new ProjectPortfolioService($this->db()))->dashboard(),
        ]);
    }

    public function budget(): void
    {
        $this->requireDirector();
        $this->noStore();
        $year = max(2000, min(2100, (int) ($_GET['year'] ?? date('Y'))));
        $projectId = max(0, (int) ($_GET['project_id'] ?? 0));
        $dashboard = (new BudgetService($this->db()))->dashboard($year, $projectId);
        $this->render('director/budget', [
            'title' => 'Бюджет',
            'subtitle' => 'Портфель проектов, поступления, ФОТ и денежный поток',
            'directorTab' => 'budget',
            'dashboard' => $dashboard,
            'selectedProjectId' => $projectId,
        ]);
    }

    public function saveProjectBudget(int $id): void
    {
        $user = $this->requireDirector();
        try {
            (new BudgetService($this->db()))->saveProjectBudget($id, $_POST['budget_cost_thousand'] ?? '', $_POST['budget_profit_thousand'] ?? '', $_POST['budget_bonus_thousand'] ?? '', (string) ($_POST['budget_comment'] ?? ''), $_POST['budget_total_thousand'] ?? '');
            AuditService::record('director_project_budget_updated', ['project_id' => $id, 'actor_id' => (int) $user['id']]);
            flash('success', 'Бюджет проекта сохранён.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/budget');
    }

    public function savePayment(?int $id = null): void
    {
        $user = $this->requireDirector();
        try {
            $paymentId = (new BudgetService($this->db()))->savePayment($id, $_POST, (int) $user['id']);
            AuditService::record($id ? 'director_payment_updated' : 'director_payment_created', ['payment_id' => $paymentId, 'project_id' => (int) ($_POST['project_id'] ?? 0)]);
            flash('success', $id ? 'Платёж обновлён.' : 'Платёж добавлен в график.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/budget');
    }

    public function deletePayment(int $id): void
    {
        $this->requireDirector();
        try {
            (new BudgetService($this->db()))->deletePayment($id);
            AuditService::record('director_payment_deleted', ['payment_id' => $id]);
            flash('success', 'Платёж удалён из графика.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/budget');
    }

    private function requireDirector(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageDepartmentBudget($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Бюджет и штатное расписание доступны только директору департамента.']);
            exit;
        }
        return $user;
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
    }
}
