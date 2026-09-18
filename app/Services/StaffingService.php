<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class StaffingService
{
    private const INCLUDED_STATUSES = ['occupied', 'vacancy', 'hiring', 'transfer'];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::pdo();
    }

    public static function rateSchemaAvailable(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM staffing_periods LIMIT 1');
            $pdo->query('SELECT 1 FROM staffing_personal_rates LIMIT 1');
            $pdo->query('SELECT 1 FROM staffing_group_rates LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function periods(): array
    {
        return $this->pdo->query("SELECT p.*, creator.name AS creator_name, locker.name AS locker_name,
                COUNT(r.id) AS positions_count,
                COALESCE(SUM(CASE WHEN r.status IN ('occupied','vacancy','hiring','transfer') THEN r.monthly_fot ELSE 0 END), 0) AS total_fot
            FROM staffing_periods p
            LEFT JOIN staffing_plan_rows r ON r.period_id = p.id
            LEFT JOIN users creator ON creator.id = p.created_by
            LEFT JOIN users locker ON locker.id = p.locked_by
            GROUP BY p.id ORDER BY p.month_start DESC, p.revision DESC")->fetchAll();
    }

    public function createPeriod(string $month, float $days, float $hours, ?int $copyFrom, int $actorId, float $burdenPct = 0, float $overheadPct = 0): int
    {
        $month = $this->normalizeMonth($month);
        $this->validateCalendar($days, $hours);
        $this->validatePercent($burdenPct);
        $this->validatePercent($overheadPct);
        if ($this->fetchOne('SELECT id FROM staffing_periods WHERE month_start = ? LIMIT 1', [$month])) {
            throw new RuntimeException('Для этого месяца уже есть версия. Создайте корректировку из зафиксированной версии.');
        }

        return $this->createRevision($month, 1, $days, $hours, $burdenPct, $overheadPct, $copyFrom, $actorId, null);
    }

    public function createCorrection(int $sourceId, int $actorId): int
    {
        $source = $this->period($sourceId);
        if (!$source || !in_array((string) $source['status'], ['locked', 'superseded'], true)) {
            throw new RuntimeException('Корректировку можно создать только из зафиксированной версии.');
        }
        if ($this->fetchOne("SELECT id FROM staffing_periods WHERE month_start = ? AND status = 'draft' LIMIT 1", [$source['month_start']])) {
            throw new RuntimeException('Для этого месяца уже есть черновик корректировки.');
        }
        $next = (int) ($this->fetchOne('SELECT COALESCE(MAX(revision), 0) AS revision FROM staffing_periods WHERE month_start = ?', [$source['month_start']])['revision'] ?? 0) + 1;

        return $this->createRevision(
            (string) $source['month_start'],
            $next,
            (float) $source['working_days'],
            (float) $source['working_hours'],
            (float) $source['payroll_burden_pct'],
            (float) $source['overhead_pct'],
            $sourceId,
            $actorId,
            'Корректировка версии ' . (int) $source['revision']
        );
    }

    private function createRevision(string $month, int $revision, float $days, float $hours, float $burdenPct, float $overheadPct, ?int $copyFrom, int $actorId, ?string $note): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO staffing_periods
                (month_start, revision, working_days, working_hours, payroll_burden_pct, overhead_pct, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$month, $revision, $days, $hours, $burdenPct, $overheadPct, $note, $actorId]);
            $id = (int) $this->pdo->lastInsertId();
            if ($copyFrom !== null) {
                if (!$this->period($copyFrom)) {
                    throw new RuntimeException('Исходный период не найден.');
                }
                $this->pdo->prepare('INSERT INTO staffing_plan_rows
                    (period_id, department_code, department_name, group_id, group_name, position_id, position_title,
                     user_id, employee_name, tab_number, fte, monthly_fot, status, change_type, change_amount, comment, sort_order)
                    SELECT ?, department_code, department_name, group_id, group_name, position_id, position_title,
                           user_id, employee_name, tab_number, fte, monthly_fot, status, change_type, NULL, comment, sort_order
                    FROM staffing_plan_rows WHERE period_id = ?')->execute([$id, $copyFrom]);
            } else {
                $this->pdo->prepare("INSERT INTO staffing_plan_rows
                    (period_id, department_code, department_name, group_id, group_name, position_id, position_title,
                     user_id, employee_name, tab_number, fte, monthly_fot, status, change_type, sort_order)
                    SELECT ?, u.department, d.name, u.group_id, g.name, u.position_id,
                           COALESCE(p.title, 'Без должности'), u.id, u.name, u.tab_number, 1, 0, 'occupied', 'none', 100
                    FROM users u
                    JOIN departments d ON d.code = u.department
                    LEFT JOIN department_groups g ON g.id = u.group_id
                    LEFT JOIN positions p ON p.id = u.position_id
                    WHERE u.is_active = 1 AND u.role <> 'admin'")
                    ->execute([$id]);
            }
            $this->pdo->commit();
            AuditService::record('staffing_period_created', ['period_id' => $id, 'month' => $month, 'revision' => $revision, 'copied_from' => $copyFrom]);
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updatePeriod(int $id, float $days, float $hours, float $burdenPct, float $overheadPct, string $note): void
    {
        $this->assertDraft($id);
        $this->validateCalendar($days, $hours);
        $this->validatePercent($burdenPct);
        $this->validatePercent($overheadPct);
        $this->pdo->prepare('UPDATE staffing_periods SET working_days = ?, working_hours = ?, payroll_burden_pct = ?, overhead_pct = ?, note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$days, $hours, $burdenPct, $overheadPct, trim($note) ?: null, $id]);
    }

    public function saveRow(int $periodId, array $input, ?int $rowId = null): int
    {
        $this->assertDraft($periodId);
        $departmentCode = trim((string) ($input['department_code'] ?? ''));
        $department = $this->fetchOne('SELECT code, name FROM departments WHERE code = ?', [$departmentCode]);
        if (!$department) {
            throw new RuntimeException('Выберите существующий отдел.');
        }
        $groupId = $this->nullableInt($input['group_id'] ?? null);
        $group = $groupId ? $this->fetchOne('SELECT id, name, department_code FROM department_groups WHERE id = ?', [$groupId]) : null;
        if ($groupId && (!$group || (string) $group['department_code'] !== $departmentCode)) {
            throw new RuntimeException('Группа не относится к выбранному отделу.');
        }
        $positionId = $this->nullableInt($input['position_id'] ?? null);
        $position = $positionId ? $this->fetchOne('SELECT id, title FROM positions WHERE id = ?', [$positionId]) : null;
        $positionTitle = trim((string) ($input['position_title'] ?? ($position['title'] ?? '')));
        if ($positionTitle === '') {
            throw new RuntimeException('Укажите должность.');
        }
        $userId = $this->nullableInt($input['user_id'] ?? null);
        $user = $userId ? $this->fetchOne('SELECT id, name, tab_number FROM users WHERE id = ? AND is_active = 1', [$userId]) : null;
        if ($userId && !$user) {
            throw new RuntimeException('Сотрудник не найден.');
        }
        $status = (string) ($input['status'] ?? ($user ? 'occupied' : 'vacancy'));
        if (!in_array($status, [...self::INCLUDED_STATUSES, 'reduction'], true)) {
            throw new RuntimeException('Некорректный статус позиции.');
        }
        if ($status === 'occupied' && !$user) {
            throw new RuntimeException('Для занятой позиции выберите сотрудника.');
        }
        $changeType = (string) ($input['change_type'] ?? 'none');
        if (!in_array($changeType, ['none', 'hire', 'transfer', 'reduction', 'other'], true)) {
            throw new RuntimeException('Некорректный тип изменения.');
        }
        $fte = $this->number($input['fte'] ?? 1, 'Количество ставок');
        if ($fte <= 0 || $fte > 2) {
            throw new RuntimeException('Количество ставок должно быть больше 0 и не больше 2.');
        }
        $monthly = $this->money($input['monthly_fot'] ?? 0);
        $change = trim((string) ($input['change_amount'] ?? '')) === '' ? null : $this->signedMoney($input['change_amount']);
        $employeeName = $user ? (string) $user['name'] : trim((string) ($input['employee_name'] ?? ''));
        if ($employeeName === '') {
            $employeeName = $status === 'hiring' ? 'Подбор' : ($status === 'reduction' ? 'Сокращение' : 'Вакансия');
        }
        if ($userId && $this->duplicateUser($periodId, $userId, $rowId)) {
            throw new RuntimeException('Сотрудник уже включён в этот период.');
        }
        $values = [
            $departmentCode, (string) $department['name'], $groupId, $group['name'] ?? null,
            $positionId, $positionTitle, $userId, $employeeName, $user['tab_number'] ?? null,
            $fte, $monthly, $status, $changeType, $change, trim((string) ($input['comment'] ?? '')) ?: null,
            (int) ($input['sort_order'] ?? 100),
        ];
        if ($rowId) {
            if (!$this->fetchOne('SELECT id FROM staffing_plan_rows WHERE id = ? AND period_id = ?', [$rowId, $periodId])) {
                throw new RuntimeException('Строка не найдена.');
            }
            $this->pdo->prepare('UPDATE staffing_plan_rows SET department_code=?, department_name=?, group_id=?, group_name=?, position_id=?, position_title=?, user_id=?, employee_name=?, tab_number=?, fte=?, monthly_fot=?, status=?, change_type=?, change_amount=?, comment=?, sort_order=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND period_id=?')
                ->execute([...$values, $rowId, $periodId]);
            return $rowId;
        }
        $this->pdo->prepare('INSERT INTO staffing_plan_rows (department_code, department_name, group_id, group_name, position_id, position_title, user_id, employee_name, tab_number, fte, monthly_fot, status, change_type, change_amount, comment, sort_order, period_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$values, $periodId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteRow(int $periodId, int $rowId): void
    {
        $this->assertDraft($periodId);
        $this->pdo->prepare('DELETE FROM staffing_plan_rows WHERE id = ? AND period_id = ?')->execute([$rowId, $periodId]);
    }

    public function lock(int $periodId, int $actorId): void
    {
        $this->assertDraft($periodId);
        $period = $this->period($periodId);
        $rows = $this->rows($periodId);
        $included = array_values(array_filter($rows, fn (array $row): bool => $this->isIncluded((string) $row['status'])));
        if ($included === []) {
            throw new RuntimeException('Нельзя зафиксировать пустое штатное расписание.');
        }
        foreach ($included as $row) {
            if ((float) $row['monthly_fot'] <= 0) {
                throw new RuntimeException('У всех включённых позиций должен быть указан месячный ФОТ.');
            }
            if ($row['status'] === 'occupied' && (int) ($row['user_id'] ?? 0) <= 0) {
                throw new RuntimeException('У всех занятых позиций должен быть выбран сотрудник.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $hours = (float) $period['working_hours'];
            $isLatestEffectivePeriod = !$this->fetchOne(
                "SELECT id FROM staffing_periods WHERE status='locked' AND month_start > ? LIMIT 1",
                [$period['month_start']]
            );
            $this->pdo->prepare('DELETE FROM staffing_personal_rates WHERE period_id = ?')->execute([$periodId]);
            $this->pdo->prepare('DELETE FROM staffing_group_rates WHERE period_id = ?')->execute([$periodId]);
            foreach ($included as $row) {
                if ((int) ($row['user_id'] ?? 0) <= 0) {
                    continue;
                }
                $rate = round((float) $row['monthly_fot'] / ($hours * (float) $row['fte']), 4);
                $this->pdo->prepare('INSERT INTO staffing_personal_rates (period_id, user_id, hourly_rate) VALUES (?, ?, ?)')
                    ->execute([$periodId, (int) $row['user_id'], $rate]);
                if ($isLatestEffectivePeriod) {
                    $this->upsertEmployeeRate((int) $row['user_id'], $rate, $actorId);
                }
            }
            foreach ($this->costGroups($periodId) as $group) {
                $this->pdo->prepare('INSERT INTO staffing_group_rates (period_id, department_code, hourly_rate, total_fte, total_fot) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$periodId, $group['department_code'], $group['avg_hourly'], $group['total_fte'], $group['total_fot']]);
                if ($isLatestEffectivePeriod) {
                    $this->upsertCostGroup((string) $group['department_code'], (float) $group['avg_hourly'], 'Штатное расписание ' . $period['month_start'] . ' · версия ' . $period['revision']);
                }
            }
            $this->pdo->prepare("UPDATE staffing_periods SET status='superseded', updated_at=CURRENT_TIMESTAMP WHERE month_start=? AND status='locked' AND id<>?")
                ->execute([$period['month_start'], $periodId]);
            $this->pdo->prepare("UPDATE staffing_periods SET status='locked', locked_by=?, locked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'")
                ->execute([$actorId, $periodId]);
            $this->pdo->commit();
            AuditService::record('staffing_period_locked', ['period_id' => $periodId, 'month' => $period['month_start'], 'revision' => (int) $period['revision'], 'rows' => count($rows)]);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function dashboard(int $periodId): array
    {
        $period = $this->period($periodId);
        if (!$period) {
            throw new RuntimeException('Период не найден.');
        }
        $rows = $this->rows($periodId);
        $active = array_values(array_filter($rows, fn (array $row): bool => $this->isIncluded((string) $row['status'])));
        $occupied = array_values(array_filter($active, static fn (array $row): bool => $row['status'] === 'occupied'));
        $total = array_sum(array_map(static fn (array $row): float => (float) $row['monthly_fot'], $active));
        $occupiedFot = array_sum(array_map(static fn (array $row): float => (float) $row['monthly_fot'], $occupied));
        $totalFte = array_sum(array_map(static fn (array $row): float => (float) $row['fte'], $active));
        $burden = $total * (float) $period['payroll_burden_pct'] / 100;
        $overhead = $total * (float) $period['overhead_pct'] / 100;
        $previous = $this->fetchOne("SELECT COALESCE(SUM(CASE WHEN r.status IN ('occupied','vacancy','hiring','transfer') THEN r.monthly_fot ELSE 0 END), 0) total
            FROM staffing_periods p LEFT JOIN staffing_plan_rows r ON r.period_id=p.id
            WHERE p.month_start < ? AND p.status='locked' GROUP BY p.id ORDER BY p.month_start DESC, p.revision DESC LIMIT 1", [$period['month_start']]);
        $previousTotal = (float) ($previous['total'] ?? 0);
        return [
            'period' => $period,
            'rows' => $rows,
            'groups' => $this->costGroups($periodId),
            'positions' => count($active),
            'total_fte' => $totalFte,
            'occupied' => count($occupied),
            'vacancies' => count($active) - count($occupied),
            'total_fot' => $total,
            'occupied_fot' => $occupiedFot,
            'vacancy_fot' => $total - $occupiedFot,
            'payroll_burden' => $burden,
            'overhead' => $overhead,
            'full_budget' => $total + $burden + $overhead,
            'average_fot' => $totalFte > 0 ? $total / $totalFte : 0,
            'delta' => $previousTotal > 0 ? $total - $previousTotal : null,
        ];
    }

    public function period(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM staffing_periods WHERE id = ?', [$id]);
    }

    public function rows(int $periodId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM staffing_plan_rows WHERE period_id = ? ORDER BY department_code, sort_order, employee_name');
        $stmt->execute([$periodId]);
        return $stmt->fetchAll();
    }

    public function costGroups(int $periodId): array
    {
        $period = $this->period($periodId);
        $stmt = $this->pdo->prepare("SELECT department_code, MAX(department_name) department_name,
                COUNT(*) positions_count, SUM(fte) total_fte, SUM(monthly_fot) total_fot
            FROM staffing_plan_rows WHERE period_id = ? AND status IN ('occupied','vacancy','hiring','transfer')
            GROUP BY department_code ORDER BY department_code");
        $stmt->execute([$periodId]);
        $hours = max(0.01, (float) ($period['working_hours'] ?? 168));
        $days = max(0.01, (float) ($period['working_days'] ?? 21));
        return array_map(static function (array $row) use ($hours, $days): array {
            $fte = max(0.01, (float) $row['total_fte']);
            $row['avg_monthly'] = round((float) $row['total_fot'] / $fte, 2);
            $row['avg_daily'] = round((float) $row['total_fot'] / ($days * $fte), 2);
            $row['avg_hourly'] = round((float) $row['total_fot'] / ($hours * $fte), 2);
            return $row;
        }, $stmt->fetchAll());
    }

    public function personalRate(int $userId, string $date): ?float
    {
        $row = $this->fetchOne("SELECT spr.hourly_rate FROM staffing_personal_rates spr
            JOIN staffing_periods sp ON sp.id=spr.period_id
            WHERE spr.user_id=? AND sp.status='locked' AND sp.month_start<=?
            ORDER BY sp.month_start DESC, sp.revision DESC LIMIT 1", [$userId, substr($date, 0, 7) . '-01']);
        if ($row) {
            return (float) $row['hourly_rate'];
        }
        $legacy = $this->fetchOne('SELECT hourly_rate FROM employee_rates WHERE user_id=?', [$userId]);
        return $legacy ? (float) $legacy['hourly_rate'] : null;
    }

    public function groupRate(string $departmentCode, string $date): ?float
    {
        $row = $this->fetchOne("SELECT sgr.hourly_rate FROM staffing_group_rates sgr
            JOIN staffing_periods sp ON sp.id=sgr.period_id
            WHERE sgr.department_code=? AND sp.status='locked' AND sp.month_start<=?
            ORDER BY sp.month_start DESC, sp.revision DESC LIMIT 1", [$departmentCode, substr($date, 0, 7) . '-01']);
        if ($row) {
            return (float) $row['hourly_rate'];
        }
        $legacy = $this->fetchOne('SELECT hourly_rate FROM cfo_rates WHERE dept_code=?', [$departmentCode]);
        return $legacy ? (float) $legacy['hourly_rate'] : null;
    }

    private function assertDraft(int $id): void
    {
        $period = $this->period($id);
        if (!$period) {
            throw new RuntimeException('Период не найден.');
        }
        if ($period['status'] !== 'draft') {
            throw new RuntimeException('Зафиксированную версию нельзя изменять. Создайте корректировку.');
        }
    }

    private function isIncluded(string $status): bool
    {
        return in_array($status, self::INCLUDED_STATUSES, true);
    }

    private function duplicateUser(int $periodId, int $userId, ?int $rowId): bool
    {
        $sql = 'SELECT id FROM staffing_plan_rows WHERE period_id=? AND user_id=?';
        $params = [$periodId, $userId];
        if ($rowId) {
            $sql .= ' AND id<>?';
            $params[] = $rowId;
        }
        return (bool) $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    private function upsertEmployeeRate(int $userId, float $rate, int $actorId): void
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO employee_rates (user_id,hourly_rate,updated_by) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET hourly_rate=excluded.hourly_rate, updated_by=excluded.updated_by, updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO employee_rates (user_id,hourly_rate,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hourly_rate=VALUES(hourly_rate), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([$userId, $rate, $actorId]);
    }

    private function upsertCostGroup(string $code, float $rate, string $label): void
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO cfo_rates (dept_code,hourly_rate,label) VALUES (?,?,?) ON CONFLICT(dept_code) DO UPDATE SET hourly_rate=excluded.hourly_rate, label=excluded.label, updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO cfo_rates (dept_code,hourly_rate,label) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hourly_rate=VALUES(hourly_rate), label=VALUES(label), updated_at=CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([$code, $rate, $label]);
    }

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private function normalizeMonth(string $month): string
    {
        if (!preg_match('/^\d{4}-\d{2}(?:-01)?$/', trim($month))) {
            throw new RuntimeException('Укажите месяц в формате ГГГГ-ММ.');
        }
        $value = substr(trim($month), 0, 7) . '-01';
        if (!checkdate((int) substr($value, 5, 2), 1, (int) substr($value, 0, 4))) {
            throw new RuntimeException('Некорректный месяц.');
        }
        return $value;
    }

    private function validateCalendar(float $days, float $hours): void
    {
        if ($days <= 0 || $days > 31 || $hours <= 0 || $hours > 744) {
            throw new RuntimeException('Проверьте рабочие дни и часы периода.');
        }
    }

    private function validatePercent(float $value): void
    {
        if ($value < 0 || $value > 500) {
            throw new RuntimeException('Процент должен быть от 0 до 500.');
        }
    }

    private function money(mixed $value): float
    {
        $value = $this->signedMoney($value);
        if ($value < 0) {
            throw new RuntimeException('ФОТ не может быть отрицательным.');
        }
        return $value;
    }

    private function signedMoney(mixed $value): float
    {
        return $this->number($value, 'Сумма');
    }

    private function number(mixed $value, string $label): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new RuntimeException($label . ' должна быть числом.');
        }
        return round((float) $normalized, 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }
}
