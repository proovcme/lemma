<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MotivationService;
use App\Services\PermissionService;
use RuntimeException;

final class MotivationController extends BaseController
{
    private function requireManager(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageMotivation($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Мотивация доступна только директору и администратору.']);
            exit;
        }

        return $user;
    }

    private function service(): MotivationService
    {
        return new MotivationService($this->db());
    }

    public function index(): void
    {
        $this->requireManager();
        $service = $this->service();
        $month = MotivationService::monthStart($_GET['month'] ?? null);
        $lockedRun = $service->lockedRun($month);
        $preview = $lockedRun === null ? $service->preview($month) : null;
        $control = $service->control($month);

        $this->render('motivation/index', [
            'title' => 'Мотивация',
            'subtitle' => 'Месячная витрина KPI и проектной надбавки',
            'month' => $month,
            'prevMonth' => date('Y-m-d', strtotime($month . ' -1 month')),
            'nextMonth' => date('Y-m-d', strtotime($month . ' +1 month')),
            'lockedRun' => $lockedRun,
            'preview' => $preview,
            'control' => $control,
        ]);
    }

    public function lock(): void
    {
        $user = $this->requireManager();
        $month = MotivationService::monthStart($_POST['month'] ?? null);
        try {
            $this->service()->lockRun($month, (int) $user['id']);
            flash('success', 'Расчёт мотивации за месяц зафиксирован.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/motivation?month=' . urlencode($month));
    }

    public function projects(): void
    {
        $this->requireManager();
        $this->render('motivation/projects', [
            'title' => 'Фонды проектов',
            'subtitle' => 'Ручные фонды и признак оплаты для проектной надбавки',
            'projects' => $this->service()->projectSettings(),
        ]);
    }

    public function saveProject(): void
    {
        $user = $this->requireManager();
        $this->service()->saveProjectSettings($_POST, (int) $user['id']);
        flash('success', 'Настройки проекта сохранены.');
        redirect('/motivation/projects');
    }

    public function settings(): void
    {
        $this->requireManager();
        $service = $this->service();
        $this->render('motivation/settings', [
            'title' => 'Настройки мотивации',
            'subtitle' => 'Коэффициенты, веса KPI и максимум месячной выплаты',
            'settings' => $service->settings(),
            'grades' => $service->gradeCoefficients(),
        ]);
    }

    public function saveSettings(): void
    {
        $user = $this->requireManager();
        $this->service()->updateSettings($_POST, (int) $user['id']);
        flash('success', 'Настройки мотивации сохранены.');
        redirect('/motivation/settings');
    }
}
