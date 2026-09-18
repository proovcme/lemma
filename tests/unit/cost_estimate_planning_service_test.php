<?php

declare(strict_types=1);

use App\Services\CostEstimatePlanningService;
use App\Services\RoleService;

test('CostEstimatePlanningService считает план, сроки и сверку с СБЦ', function (): void {
    $service = new CostEstimatePlanningService();
    $sections = [
        ['id' => 1, 'date_start' => '2026-07-01', 'date_end' => '2026-07-10', 'sbc_reference_cost' => 12.0],
        ['id' => 2, 'date_start' => '2026-07-05', 'date_end' => '2026-07-20', 'sbc_reference_cost' => 0.0],
    ];
    $rows = $service->enrichRows([
        [
            'status' => 'draft',
            'model_suggested_hours' => 10,
            'executor_hours' => 0,
            'hourly_rate' => 2000,
            'sbc_reference_cost' => 30,
        ],
        [
            'status' => 'gip_adjusted',
            'executor_hours' => 16,
            'gip_hours' => 20,
            'hourly_rate' => 2500,
            'sbc_reference_cost' => 50,
        ],
        [
            'status' => 'director_approved',
            'executor_hours' => 16,
            'gip_hours' => 22,
            'director_hours' => 24,
            'director_cost_thousand' => 60,
            'hourly_rate' => 2500,
            'sbc_reference_cost' => 88,
        ],
    ]);

    assert_same(10.0, $rows[0]['planning_hours']);
    assert_same('model', $rows[0]['planning_source']);
    assert_same(20.0, $rows[1]['planning_hours']);
    assert_same('gip', $rows[1]['planning_source']);
    assert_same(24.0, $rows[2]['planning_hours']);
    assert_same(60.0, $rows[2]['planning_money_thousand']);

    $summary = $service->summary(['start_date' => '2026-06-01', 'finish_date' => '2026-06-30'], $sections, $rows);
    assert_same(3, $summary['labor_rows']);
    assert_same(1, $summary['approved_rows']);
    assert_same(54.0, $summary['hours']);
    assert_same(6.75, $summary['days']);
    assert_same('2026-07-01', $summary['date_start']);
    assert_same('2026-07-20', $summary['date_end']);
    assert_same(20, $summary['calendar_days']);
    assert_same('по датам разделов', $summary['date_source']);
    assert_same(130.0, $summary['money']);
    assert_same(180.0, $summary['sbc']);
    assert_same(-50.0, $summary['delta']);
    assert_same(-27.8, $summary['delta_percent']);
    assert_same(100.0, $summary['sbc_coverage_percent']);
    assert_same(0, $summary['sbc_missing_rows']);
    assert_same('risk', $summary['health']);
});

test('CostEstimatePlanningService считает строку без СБЦ как непокрытую', function (): void {
    $service = new CostEstimatePlanningService();
    $rows = $service->enrichRows([
        [
            'status' => 'department_submitted',
            'executor_hours' => 8,
            'hourly_rate' => 1500,
            'sbc_reference_cost' => 0,
        ],
    ]);

    $summary = $service->summary([], [], $rows);
    assert_same(8.0, $summary['hours']);
    assert_same(12.0, $summary['money']);
    assert_same(0.0, $summary['sbc']);
    assert_same(0.0, $summary['sbc_coverage_percent']);
    assert_same(1, $summary['sbc_missing_rows']);
    assert_same('attention', $summary['health']);
    assert_same(null, $summary['calendar_days']);
    assert_same('не задан', $summary['date_source']);
});

test('CostEstimatePlanningService не удваивает СБЦ раздела со строкой оценки', function (): void {
    $service = new CostEstimatePlanningService();
    $sections = [
        ['id' => 10, 'date_start' => null, 'date_end' => null, 'sbc_reference_cost' => 120.0],
        ['id' => 11, 'date_start' => null, 'date_end' => null, 'sbc_reference_cost' => 30.0],
    ];
    $rows = $service->enrichRows([
        [
            'section_id' => 10,
            'status' => 'draft',
            'executor_hours' => 16,
            'hourly_rate' => 2000,
            'sbc_reference_cost' => 120,
        ],
    ]);

    $summary = $service->summary([], $sections, $rows);
    assert_same(150.0, $summary['sbc']);
    assert_same(32.0, $summary['money']);
    assert_same(-118.0, $summary['delta']);
});

test('CostEstimatePlanningService готовит быстрые строки планирования по разделам', function (): void {
    $service = new CostEstimatePlanningService();
    $sections = [
        [
            'id' => 1,
            'code' => 'ОВ',
            'title' => 'Отопление и вентиляция',
            'sbc_reference_cost' => 50.0,
            'sbc_default_labor_hours' => 0,
        ],
        [
            'id' => 2,
            'code' => 'ВК',
            'title' => 'Водоснабжение',
            'sbc_reference_cost' => 0.0,
            'sbc_default_labor_hours' => 24,
        ],
    ];
    $laborRows = $service->enrichRows([
        [
            'section_id' => 1,
            'status' => 'department_submitted',
            'executor_hours' => 12,
            'hourly_rate' => 1000,
            'sbc_reference_cost' => 50,
        ],
    ]);
    $taskStats = [
        'overall' => [],
        'by_discipline' => [
            [
                'label' => 'ОВ',
                'total' => 3,
                'avg_planned_hours' => 10.0,
                'avg_actual_hours' => 14.0,
                'avg_cycle_days' => 2.5,
                'over_plan_percent' => 33.3,
            ],
        ],
        'by_type' => [],
    ];

    $rows = $service->sectionPlanningRows($sections, $laborRows, $taskStats);
    assert_same(2, count($rows));
    assert_same(1, $rows[0]['labor_rows']);
    assert_same(12.0, $rows[0]['labor_hours']);
    assert_same(14.0, $rows[0]['suggested_hours']);
    assert_same(true, $rows[0]['has_sbc']);
    assert_same(0, $rows[1]['labor_rows']);
    assert_same(24.0, $rows[1]['suggested_hours']);
    assert_same(false, $rows[1]['has_sbc']);
});

test('CostEstimatePlanningService считает управленческую статистику закрытых задач', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department TEXT)');
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY,
        parent_id INTEGER,
        assignee_id INTEGER,
        task_type TEXT,
        discipline TEXT,
        status TEXT,
        planned_hours REAL,
        actual_hours REAL,
        date_start TEXT,
        date_end TEXT,
        closed_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec("INSERT INTO users (id, department) VALUES (1, 'ОВ'), (2, 'ВК')");
    $pdo->exec("INSERT INTO tasks (id, assignee_id, task_type, discipline, status, planned_hours, actual_hours, date_start, date_end, closed_at, updated_at) VALUES
        (10, 1, 'work', 'ОВ', 'done', 8, 10, '2026-06-01', '2026-06-03', '2026-06-04 10:00:00', '2026-06-04'),
        (11, 1, 'assignment', 'ОВ', 'done', 16, 12, '2026-06-02', '2026-06-05', '2026-06-05 18:00:00', '2026-06-05'),
        (12, 2, 'work', 'ВК', 'done', 20, 30, '2026-06-01', '2026-06-10', '2026-06-10 12:00:00', '2026-06-10'),
        (13, 1, 'review', 'ОВ', 'done', 1, 1, '2026-06-01', '2026-06-01', '2026-06-01 12:00:00', '2026-06-01'),
        (14, 1, 'work', 'ОВ', 'in_progress', 40, 0, '2026-06-01', '2026-06-30', NULL, '2026-06-10')");

    $service = new CostEstimatePlanningService();
    $all = $service->taskStatistics($pdo, ['role' => RoleService::DIRECTOR]);
    assert_same(3, $all['overall']['total']);
    assert_same(14.67, $all['overall']['avg_planned_hours']);
    assert_same(17.33, $all['overall']['avg_actual_hours']);
    assert_same(6.0, $all['overall']['avg_cycle_days']);
    assert_same(66.7, $all['overall']['over_plan_percent']);
    assert_same('ОВ', $all['by_discipline'][0]['label']);
    assert_same(2, $all['by_discipline'][0]['total']);

    $department = $service->taskStatistics($pdo, [
        'role' => RoleService::DEPARTMENT_HEAD,
        'department' => 'ОВ',
    ]);
    assert_same(2, $department['overall']['total']);
    assert_same(12.0, $department['overall']['avg_planned_hours']);
    assert_same(11.0, $department['overall']['avg_actual_hours']);
});
