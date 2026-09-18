<?php

declare(strict_types=1);

use App\Services\TaskCostGroupService;

test('код раздела задачи берётся из последнего утверждённого ШР', function (): void {
    $pdo = task_cost_group_pdo();
    $pdo->exec("INSERT INTO users (id, department) VALUES (7, 'ОВ')");
    $pdo->exec("INSERT INTO employee_legal_entities (id, user_id, is_primary, cost_group, is_active) VALUES (1, 7, 1, 'ВК', 1)");
    $pdo->exec("INSERT INTO staffing_periods (id, month_start, revision, status) VALUES
        (1, '2026-06-01', 1, 'locked'),
        (2, '2026-07-01', 1, 'locked')");
    $pdo->exec("INSERT INTO staffing_plan_rows (period_id, user_id, department_code, status) VALUES
        (1, 7, 'АР', 'occupied'),
        (2, 7, 'КР', 'occupied')");

    assert_same('КР', TaskCostGroupService::codeForUser(7, $pdo));
});

test('код раздела использует персональную группу и затем отдел как резерв', function (): void {
    $pdo = task_cost_group_pdo();
    $pdo->exec("INSERT INTO users (id, department) VALUES (7, 'ОВ'), (8, 'ЭОМ')");
    $pdo->exec("INSERT INTO employee_legal_entities (id, user_id, is_primary, cost_group, is_active) VALUES (1, 7, 1, 'ВК', 1)");

    assert_same('ВК', TaskCostGroupService::codeForUser(7, $pdo));
    assert_same('ЭОМ', TaskCostGroupService::codeForUser(8, $pdo));

    $users = TaskCostGroupService::attachCodes([['id' => 7], ['id' => 8]], $pdo);
    assert_same('ВК', $users[0]['cost_group_code']);
    assert_same('ЭОМ', $users[1]['cost_group_code']);
});

function task_cost_group_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department TEXT)');
    $pdo->exec('CREATE TABLE employee_legal_entities (id INTEGER PRIMARY KEY, user_id INTEGER, is_primary INTEGER, cost_group TEXT, is_active INTEGER)');
    $pdo->exec('CREATE TABLE staffing_periods (id INTEGER PRIMARY KEY, month_start TEXT, revision INTEGER, status TEXT)');
    $pdo->exec('CREATE TABLE staffing_plan_rows (id INTEGER PRIMARY KEY, period_id INTEGER, user_id INTEGER, department_code TEXT, status TEXT)');

    return $pdo;
}
