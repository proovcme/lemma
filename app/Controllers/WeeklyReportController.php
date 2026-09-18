<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\WeeklyReportService;

final class WeeklyReportController extends BaseController
{
    private WeeklyReportService $service;

    public function __construct()
    {
        $this->service = new WeeklyReportService();
    }

    public function index(): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        $this->render('reports/weekly/index', [
            'title' => 'Периодические отчёты',
            'reports' => $this->service->reportList($user),
            'projects' => $this->service->visibleProjects($user),
            'canEditWeeklyReports' => WeeklyReportService::canEdit($user),
            'statusLabels' => WeeklyReportService::statusLabels(),
            'periodTypeLabels' => WeeklyReportService::periodTypeLabels(),
            'defaultPeriod' => WeeklyReportService::defaultPeriodValues(),
        ]);
    }

    public function create(): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        try {
            $id = $this->service->createDraft($user, $_POST);
            flash('success', 'Черновик отчёта создан.');
            redirect('/reports/periodic/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/reports/periodic');
        }
    }

    public function show(int $id): void
    {
        $user = require_auth();
        $this->ensureAccess($user);
        $report = $this->service->report($user, $id);
        if (!$report) {
            http_response_code(404);
            view('layouts/error', ['title' => 'Отчёт не найден', 'message' => 'Отчёт не найден или недоступен.']);
            return;
        }

        $this->render('reports/weekly/show', [
            'title' => 'Отчёт',
            'report' => $report,
            'canEditWeeklyReports' => WeeklyReportService::canEdit($user) && (string) $report['state'] === 'draft',
            'statusLabels' => WeeklyReportService::statusLabels(),
            'periodTypeLabels' => WeeklyReportService::periodTypeLabels(),
            'sectionLabels' => WeeklyReportService::sectionLabels(),
            'severityLabels' => WeeklyReportService::severityLabels(),
        ]);
    }

    public function update(int $id): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        try {
            $this->service->updateReport($user, $id, $_POST);
            flash('success', 'Отчёт обновлён.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/reports/periodic/' . $id);
    }

    public function addItem(int $id): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        try {
            $this->service->addItem($user, $id, $_POST);
            flash('success', 'Строка отчёта добавлена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/reports/periodic/' . $id);
    }

    public function deleteItem(int $id, int $itemId): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        try {
            $this->service->deleteItem($user, $id, $itemId);
            flash('success', 'Строка отчёта удалена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/reports/periodic/' . $id);
    }

    public function lock(int $id): void
    {
        $user = require_auth();
        $this->ensureAccess($user);

        try {
            $this->service->lock($user, $id);
            flash('success', 'Отчёт зафиксирован.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/reports/periodic/' . $id);
    }

    private function ensureAccess(array $user): void
    {
        if (PermissionService::canOpenReports($user)) {
            return;
        }

        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Периодические отчёты доступны только ролям с доступом к отчётам.']);
        exit;
    }
}
