<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PayrollService;
use App\Services\PermissionService;

/**
 * Настройка модуля ФОТ (Фаза 1): юрлица, статьи списания, назначение
 * сотрудников в юрлица со ставками/окладами. Доступ — Director/Admin.
 */
final class PayrollAdminController extends BaseController
{
    private function requirePayrollAdmin(): array
    {
        $user = require_auth();
        if (!PermissionService::canManagePayroll($user)) {
            $this->forbidden();
        }
        return $user;
    }

    private function dec(mixed $v): float
    {
        return (float) str_replace([',', ' '], ['.', ''], (string) $v);
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для этого раздела.']);
        exit;
    }

    // ---------------------------------------------------------------- Юрлица

    public function legalEntities(): void
    {
        $this->requirePayrollAdmin();
        $this->render('admin/legal_entities', [
            'title' => 'Юрлица',
            'entities' => PayrollService::legalEntities(),
        ]);
    }

    public function storeLegalEntity(): void
    {
        $this->requirePayrollAdmin();
        $code = mb_strtoupper(trim((string) ($_POST['code'] ?? '')), 'UTF-8');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '' || $name === '') {
            flash('error', 'Код и название юрлица обязательны.');
            redirect('/admin/legal-entities');
        }
        $pdo = $this->db();
        $id = (int) ($_POST['id'] ?? 0);
        $fields = [
            'code' => $code,
            'name' => $name,
            'full_name' => trim((string) ($_POST['full_name'] ?? '')) ?: null,
            'inn' => trim((string) ($_POST['inn'] ?? '')) ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE legal_entities SET code=?, name=?, full_name=?, inn=?, is_active=?, sort_order=? WHERE id=?');
            $stmt->execute([$fields['code'], $fields['name'], $fields['full_name'], $fields['inn'], $fields['is_active'], $fields['sort_order'], $id]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM legal_entities WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetchColumn()) {
                flash('error', "Юрлицо с кодом «{$code}» уже есть.");
                redirect('/admin/legal-entities');
            }
            $stmt = $pdo->prepare('INSERT INTO legal_entities (code, name, full_name, inn, is_active, sort_order) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$fields['code'], $fields['name'], $fields['full_name'], $fields['inn'], $fields['is_active'], $fields['sort_order']]);
        }
        flash('success', 'Юрлицо сохранено.');
        redirect('/admin/legal-entities');
    }

    public function deleteLegalEntity(int $id): void
    {
        $this->requirePayrollAdmin();
        $this->db()->prepare('DELETE FROM legal_entities WHERE id = ?')->execute([$id]);
        flash('success', 'Юрлицо удалено.');
        redirect('/admin/legal-entities');
    }

    // ------------------------------------------------------ Статьи списания

    public function articles(): void
    {
        $this->requirePayrollAdmin();
        $this->render('admin/writeoff_articles', [
            'title' => 'Статьи списания',
            'articles' => PayrollService::articles(),
            'categories' => ['' => '—', 'task' => 'Задача', 'learning' => 'Обучение', 'vacation' => 'Отпуск', 'sick_leave' => 'Болезнь', 'idle' => 'Простой', 'day_off' => 'Отгул', 'business_trip' => 'Командировка'],
        ]);
    }

    public function storeArticle(): void
    {
        $this->requirePayrollAdmin();
        $code = trim((string) ($_POST['code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '' || $name === '') {
            flash('error', 'Код и название статьи обязательны.');
            redirect('/admin/writeoff-articles');
        }
        $pdo = $this->db();
        $id = (int) ($_POST['id'] ?? 0);
        $kind = ($_POST['kind'] ?? '') === 'project' ? 'project' : 'nonproject';
        $maps = trim((string) ($_POST['maps_category'] ?? '')) ?: null;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE writeoff_articles SET code=?, name=?, kind=?, maps_category=?, is_active=?, sort_order=? WHERE id=?')
                ->execute([$code, $name, $kind, $maps, $active, $sort, $id]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM writeoff_articles WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetchColumn()) {
                flash('error', "Статья с кодом «{$code}» уже есть.");
                redirect('/admin/writeoff-articles');
            }
            $pdo->prepare('INSERT INTO writeoff_articles (code, name, kind, maps_category, is_active, sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$code, $name, $kind, $maps, $active, $sort]);
        }
        flash('success', 'Статья сохранена.');
        redirect('/admin/writeoff-articles');
    }

    public function deleteArticle(int $id): void
    {
        $this->requirePayrollAdmin();
        $this->db()->prepare('DELETE FROM writeoff_articles WHERE id = ?')->execute([$id]);
        flash('success', 'Статья удалена.');
        redirect('/admin/writeoff-articles');
    }

    // ----------------------------------------- Сотрудник × юрлицо (ставки)

    public function employeeEntities(): void
    {
        $this->requirePayrollAdmin();
        $users = $this->db()->query('SELECT id, name, tab_number, department FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
        $assignments = PayrollService::assignments();
        $norm = PayrollService::DEFAULT_NORM_HOURS;
        foreach ($assignments as &$a) {
            $a['computed_rate'] = PayrollService::hourlyRate($a, $norm);
        }
        unset($a);
        $this->render('admin/employee_entities', [
            'title' => 'Сотрудники по юрлицам и ставкам',
            'entities' => PayrollService::legalEntities(true),
            'users' => $users,
            'assignments' => $assignments,
            'normHours' => $norm,
        ]);
    }

    public function storeEmployeeEntity(): void
    {
        $this->requirePayrollAdmin();
        $user = require_auth();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $entityId = (int) ($_POST['legal_entity_id'] ?? 0);
        if ($userId <= 0 || $entityId <= 0) {
            flash('error', 'Выберите сотрудника и юрлицо.');
            redirect('/admin/employee-entities');
        }
        $pdo = $this->db();
        $vals = [
            'is_primary' => isset($_POST['is_primary']) ? 1 : 0,
            'daily_hours' => $this->dec($_POST['daily_hours'] ?? 0),
            'position' => trim((string) ($_POST['position'] ?? '')) ?: null,
            'cost_group' => trim((string) ($_POST['cost_group'] ?? '')) ?: null,
            'base_oklad' => $this->dec($_POST['base_oklad'] ?? 0),
            'base_nadbavka' => $this->dec($_POST['base_nadbavka'] ?? 0),
            'premium' => $this->dec($_POST['premium'] ?? 0),
            'project_nadbavka' => $this->dec($_POST['project_nadbavka'] ?? 0),
            'is_piecework' => isset($_POST['is_piecework']) ? 1 : 0,
            'rate_override' => ($_POST['rate_override'] ?? '') !== '' ? $this->dec($_POST['rate_override']) : null,
        ];
        $exists = $pdo->prepare('SELECT id FROM employee_legal_entities WHERE user_id = ? AND legal_entity_id = ? LIMIT 1');
        $exists->execute([$userId, $entityId]);
        $existingId = (int) $exists->fetchColumn();
        if ($existingId > 0) {
            $pdo->prepare('UPDATE employee_legal_entities SET is_primary=?, daily_hours=?, position=?, cost_group=?, base_oklad=?, base_nadbavka=?, premium=?, project_nadbavka=?, is_piecework=?, rate_override=?, updated_by=? WHERE id=?')
                ->execute([$vals['is_primary'], $vals['daily_hours'], $vals['position'], $vals['cost_group'], $vals['base_oklad'], $vals['base_nadbavka'], $vals['premium'], $vals['project_nadbavka'], $vals['is_piecework'], $vals['rate_override'], (int) $user['id'], $existingId]);
        } else {
            $pdo->prepare('INSERT INTO employee_legal_entities (user_id, legal_entity_id, is_primary, daily_hours, position, cost_group, base_oklad, base_nadbavka, premium, project_nadbavka, is_piecework, rate_override, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$userId, $entityId, $vals['is_primary'], $vals['daily_hours'], $vals['position'], $vals['cost_group'], $vals['base_oklad'], $vals['base_nadbavka'], $vals['premium'], $vals['project_nadbavka'], $vals['is_piecework'], $vals['rate_override'], (int) $user['id']]);
        }
        flash('success', 'Назначение сохранено.');
        redirect('/admin/employee-entities');
    }

    public function deleteEmployeeEntity(int $id): void
    {
        $this->requirePayrollAdmin();
        $this->db()->prepare('DELETE FROM employee_legal_entities WHERE id = ?')->execute([$id]);
        flash('success', 'Назначение удалено.');
        redirect('/admin/employee-entities');
    }
}
