<?php

declare(strict_types=1);

/**
 * Demo data seed for the public Locia demo.
 *
 * Runs AFTER scripts/sqlite_setup.php on a fresh SQLite database. sqlite_setup
 * already creates the schema, 35 anonymized users (@example.local), positions,
 * role permissions and ЦФО fallback rates. This script adds the *moving parts*
 * that make /my-day, /shturman, /reports and the ФОТ module look alive:
 * neutral legal entities, fictional projects, sections, tasks (incl. overdue),
 * time entries for the current month, and payroll bindings.
 *
 * Everything here is invented — no organisation specifics.
 */

use App\Core\Database;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (config('db.connection') !== 'sqlite') {
    fwrite(STDERR, "DB_CONNECTION must be sqlite for the demo seed.\n");
    exit(1);
}

$pdo = Database::pdo();
$pdo->exec('PRAGMA foreign_keys = ON');

$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
};

$baseProjectDeleted = (int) $pdo->exec(
    "DELETE FROM projects WHERE code = 'D-BASE' OR title = 'Базовый демо-проект'"
);
$pdo->exec(
    "DELETE FROM dictionary_items
     WHERE project_id IS NOT NULL
       AND (value LIKE 'D-BASE/%' OR label LIKE '%Базовый демо-проект%' OR label LIKE '%D-BASE%')"
);

// Fresh public demo data must never inherit contacts, uploaded files, public
// links, Revit tokens or model publication rows from the generic SQLite seed.
// Delete children before parents so the cleanup remains valid with FK checks on.
foreach ([
    'project_task_exchange',
    'project_model_versions',
    'project_model_series',
    'revit_api_tokens',
    'revit_uploads',
    'attachments',
    'public_links',
    'project_contacts',
    'counterparties',
] as $table) {
    if ($tableExists($table)) {
        $pdo->exec('DELETE FROM ' . $table);
    }
}

$today = new DateTimeImmutable('today');
$d = static fn (string $modify): string => $today->modify($modify)->format('Y-m-d');

/** @return array<string,int> email => id */
$userId = [];
foreach ($pdo->query('SELECT id, email FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $userId[(string) $row['email']] = (int) $row['id'];
}
$uid = static function (string $email) use ($userId): ?int {
    return $userId[$email] ?? null;
};

// Replace even fictional person-like names with explicit role labels. This
// makes the public data contract unambiguous: no row presents itself as a real
// employee identity, and all addresses remain under the reserved local domain.
$roleLabels = [
    'admin' => 'Администратор',
    'director' => 'Директор',
    'deputy_director' => 'Заместитель директора',
    'adjacent_director' => 'Директор смежников',
    'gip' => 'ГИП',
    'project_manager' => 'Руководитель проекта',
    'department_head' => 'Начальник отдела',
    'group_lead' => 'Руководитель группы',
    'chief_specialist' => 'Главный специалист',
    'engineer' => 'Инженер',
    'hr' => 'HR',
];
$renameUser = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
foreach ($pdo->query('SELECT id, tab_number, role, department FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $role = (string) ($row['role'] ?? 'engineer');
    $department = trim((string) ($row['department'] ?? ''));
    $tab = trim((string) ($row['tab_number'] ?? ''));
    $label = $roleLabels[$role] ?? 'Участник проекта';
    $suffix = $department !== '' ? ' · ' . $department : '';
    if (in_array($role, ['engineer', 'chief_specialist', 'group_lead'], true) && $tab !== '') {
        $suffix .= ' · ' . $tab;
    }
    $renameUser->execute(['Демо · ' . $label . $suffix, (int) $row['id']]);
}

$userColumns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['phone', 'mobile_phone', 'telegram', 'messenger', 'personal_email'] as $column) {
    if (in_array($column, $userColumns, true)) {
        $pdo->exec('UPDATE users SET "' . $column . '" = NULL');
    }
}

$pdo->prepare(
    "UPDATE users
     SET is_active = 0, name = 'Демо · Сервисная учётка', department = 'DEMO', role = 'engineer'
     WHERE email = ?"
)->execute(['admin@example.local']);

echo "Seeding demo data (today = {$today->format('Y-m-d')})...\n";
echo "  stripped base sqlite sample projects: {$baseProjectDeleted}\n";

// ---------------------------------------------------------------------------
// 1. Legal entities (neutral) + payroll bindings
// ---------------------------------------------------------------------------
$entities = [
    ['LE1', 'Проектное бюро 1', 'ООО «Проектное бюро 1»', '7700000001', 1],
    ['LE2', 'Проектное бюро 2', 'ООО «Проектное бюро 2»', '7700000002', 2],
    ['LE3', 'Инжиниринг-центр', 'ООО «Инжиниринг-центр»', '7700000003', 3],
];
$insLE = $pdo->prepare(
    'INSERT OR IGNORE INTO legal_entities (code, name, full_name, inn, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);
foreach ($entities as $e) {
    $insLE->execute($e);
}
$leId = [];
foreach ($pdo->query('SELECT id, code FROM legal_entities')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $leId[(string) $row['code']] = (int) $row['id'];
}

// Bind every real worker to a legal entity with an invented payroll profile.
$deptCostGroup = [
    'ОВ' => 'ОВ', 'ЭОМ' => 'ЭОМ', 'СС' => 'СС', 'ГИП' => 'ГИП', 'BIM' => 'BIM',
    'АСУ' => 'АСУ', 'ВК' => 'ВК', 'КР' => 'КР', 'АР' => 'АР', 'ДПР' => 'ГИП',
];
$insELE = $pdo->prepare(
    'INSERT OR IGNORE INTO employee_legal_entities
        (user_id, legal_entity_id, is_primary, daily_hours, position, cost_group,
         base_oklad, base_nadbavka, premium, project_nadbavka, is_active, updated_at)
     VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, 0, 1, CURRENT_TIMESTAMP)'
);
$workers = $pdo->query(
    "SELECT id, department, role FROM users WHERE email LIKE '%@example.local' AND is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);
$i = 0;
foreach ($workers as $w) {
    $dept = (string) ($w['department'] ?? 'ОВ');
    $cg = $deptCostGroup[$dept] ?? 'ГИП';
    $le = $leId['LE' . (($i % 2) + 1)] ?? array_values($leId)[0];
    $role = (string) $w['role'];
    // Invented salaries by seniority.
    [$oklad, $nadb, $prem] = match (true) {
        str_contains($role, 'director') => [120000, 80000, 40000],
        $role === 'gip', $role === 'department_head' => [90000, 60000, 30000],
        $role === 'group_lead', $role === 'chief_specialist' => [80000, 45000, 20000],
        default => [60000, 40000, 15000],
    };
    $insELE->execute([(int) $w['id'], $le, 8.0, $cg, $cg, $oklad, $nadb, $prem]);
    $i++;
}
echo "  legal entities: " . count($leId) . ", payroll profiles: {$i}\n";

// ---------------------------------------------------------------------------
// 2. Fictional projects
// ---------------------------------------------------------------------------
$gipHead = $uid('head.gip@example.local');
$gip1 = $uid('gip.coordinator1@example.local');
$gip2 = $uid('gip.coordinator2@example.local');

$projects = [
    ['D-101', 'БЦ «Меридиан»', 'Бизнес-центр класса А', 'г. N, пр. Центральный, 1', 'Общественное', 28500.0, $gip1],
    ['D-102', 'ЖК «Северный парк»', 'Жилой комплекс, 3 очередь', 'г. N, ул. Парковая, 14', 'Жилое', 64200.0, $gip2],
    ['D-103', 'ЛК «Восток»', 'Логистический комплекс', 'обл. N, Восточное шоссе, 7', 'Промышленное', 41000.0, $gip1],
    ['D-104', 'Школа на 1100 мест', 'Образовательное учреждение', 'г. N, мкр. Южный', 'Социальное', 19800.0, $gip2],
];
$insP = $pdo->prepare(
    "INSERT OR IGNORE INTO projects
        (code, title, object, address, object_type, area_m2, stage, status, gip_user_id, file_folder_url, model_folder_url)
     VALUES (?, ?, ?, ?, ?, ?, 'РД', 'active', ?, '', '')"
);
foreach ($projects as $p) {
    $insP->execute($p);
}
$projId = [];
foreach ($pdo->query('SELECT id, code FROM projects')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $projId[(string) $row['code']] = (int) $row['id'];
}
echo "  projects: " . count($projId) . "\n";

// Project sections (disciplines) per project
$insSec = $pdo->prepare(
    "INSERT INTO project_sections (project_id, code, title, status) VALUES (?, ?, ?, 'in_progress')"
);
$disciplines = ['ОВ', 'ВК', 'ЭОМ', 'СС', 'АР', 'КР'];
foreach (['D-101', 'D-102', 'D-103', 'D-104'] as $code) {
    $pid = $projId[$code] ?? null;
    if ($pid === null) {
        continue;
    }
    foreach ($disciplines as $disc) {
        try {
            $insSec->execute([$pid, $disc, "Раздел {$disc}"]);
        } catch (PDOException) {
            // tolerate schema differences in project_sections
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Tasks — varied statuses, some overdue, across projects + engineers
// ---------------------------------------------------------------------------
$engByDept = [
    'ОВ' => ['ov.engineer1@example.local', 'ov.engineer2@example.local'],
    'ВК' => ['vk.engineer1@example.local', 'vk.engineer2@example.local'],
    'ЭОМ' => ['eom.engineer1@example.local', 'eom.engineer2@example.local'],
    'СС' => ['ss.engineer1@example.local', 'ss.engineer2@example.local'],
    'АР' => ['ar.engineer1@example.local', 'ar.engineer2@example.local'],
    'КР' => ['kr.engineer1@example.local', 'kr.engineer2@example.local'],
];
$titlesByDisc = [
    'ОВ' => ['Раздел ОВ1: отопление', 'Раздел ОВ2: вентиляция', 'Расчёт теплопотерь', 'Аксонометрия систем В'],
    'ВК' => ['Раздел ВК: водопровод', 'Раздел ВК: канализация', 'Расчёт расходов воды', 'Узлы ввода'],
    'ЭОМ' => ['Раздел ЭОМ: силовое', 'Раздел ЭО: освещение', 'Расчёт нагрузок', 'Схема ВРУ'],
    'СС' => ['Раздел СС: СКС', 'Раздел СС: СКУД', 'Структурная схема', 'Спецификация оборудования'],
    'АР' => ['Раздел АР: планы', 'Раздел АР: фасады', 'Узлы и разрезы', 'Ведомость отделки'],
    'КР' => ['Раздел КР: каркас', 'Раздел КР: фундаменты', 'Расчётная схема', 'Узлы металлоконструкций'],
];
// status, approval_stage, date_end offset (days from today), progress
$taskShapes = [
    ['done', 'issued', '-20 days', 100],
    ['in_progress', 'draft', '+9 days', 45],
    ['in_progress', 'review_lead', '+4 days', 70],
    ['overdue', 'draft', '-3 days', 30],          // overdue → red highlight
    ['review', 'review_gip', '+1 day', 85],
    ['new', 'draft', '+15 days', 0],
    ['blocked', 'draft', '-6 days', 20],           // overdue + blocked
];
$insT = $pdo->prepare(
    'INSERT INTO tasks
        (title, task_type, project_id, assignee_id, author_id, reviewer_id, discipline, section,
         status, approval_stage, priority, urgency, date_start, date_end, planned_hours, actual_hours, progress)
     VALUES (?, "work", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$taskCount = 0;
$createdTasks = []; // [task_id, project_id, assignee_id]
$projCodes = ['D-101', 'D-102', 'D-103', 'D-104'];
foreach ($projCodes as $pIndex => $code) {
    $pid = $projId[$code] ?? null;
    if ($pid === null) {
        continue;
    }
    foreach ($engByDept as $disc => $emails) {
        $titles = $titlesByDisc[$disc];
        foreach ($emails as $eIdx => $email) {
            $assignee = $uid($email);
            if ($assignee === null) {
                continue;
            }
            // each engineer gets 2 tasks per project they touch (rotate shapes)
            $picks = ($pIndex + $eIdx) % 2 === 0 ? [0, 1, 3] : [2, 4, 5, 6];
            foreach ($picks as $k => $shapeIdx) {
                $shape = $taskShapes[$shapeIdx];
                $title = $titles[($k + $eIdx) % count($titles)];
                $prio = ['low', 'mid', 'high'][($taskCount) % 3];
                $planned = [16, 24, 40, 32][$taskCount % 4];
                $actual = (int) round($planned * ($shape[3] / 100));
                $insT->execute([
                    $title, $pid, $assignee, $gipHead, $uid('head.' . strtolower($disc === 'ОВ' ? 'ov' : ($disc === 'ВК' ? 'vk' : ($disc === 'ЭОМ' ? 'eom' : ($disc === 'СС' ? 'ss' : ($disc === 'АР' ? 'ar' : 'kr'))))) . '@example.local'),
                    $disc, $disc, $shape[0], $shape[1], $prio, $prio,
                    $d('-25 days'), $d($shape[2]), (float) $planned, (float) $actual, $shape[3],
                ]);
                $createdTasks[] = [(int) $pdo->lastInsertId(), $pid, $assignee, $disc];
                $taskCount++;
            }
        }
    }
}
echo "  tasks: {$taskCount}\n";

// ---------------------------------------------------------------------------
// 4. Time entries for the current month (passive discrete logging)
// ---------------------------------------------------------------------------
$insTE = $pdo->prepare(
    'INSERT INTO time_entries
        (user_id, project_id, task_id, work_date, minutes, category, phase, comment, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
// Map assignee → their tasks for realistic logging
$tasksByUser = [];
foreach ($createdTasks as [$tid, $pid, $aid, $disc]) {
    $tasksByUser[$aid][] = [$tid, $pid];
}
$monthStart = $today->modify('first day of this month');
$teCount = 0;
foreach ($tasksByUser as $aid => $list) {
    $cursor = $monthStart;
    while ($cursor <= $today) {
        $dow = (int) $cursor->format('N');
        if ($dow <= 5) { // weekdays only
            $pick = $list[($teCount + $aid) % count($list)];
            $isPast = $cursor < $today->modify('-3 days');
            // 7h on a task + 1h meeting/admin
            $insTE->execute([
                $aid, $pick[1], $pick[0], $cursor->format('Y-m-d'), 420,
                'task', 'execution', 'Работа по разделу', $isPast ? 'approved' : 'submitted',
            ]);
            $insTE->execute([
                $aid, $pick[1], null, $cursor->format('Y-m-d'), 60,
                ($teCount % 2 === 0 ? 'meeting' : 'admin'), 'management', 'Совещание / координация',
                $isPast ? 'approved' : 'submitted',
            ]);
            $teCount += 2;
        }
        $cursor = $cursor->modify('+1 day');
    }
}
echo "  time entries: {$teCount}\n";

// ---------------------------------------------------------------------------
// 5. Frictionless demo login — no forced password change
// ---------------------------------------------------------------------------
$pdo->exec('UPDATE users SET must_change_password = 0');

echo "Demo seed complete.\n";
echo "  Logins: head.gip@example.local / dpr12345 (ГИП, Штурман)\n";
echo "          ov.engineer1@example.local / dpr12345 (инженер, Мой день)\n";
echo "          director@example.local / director123 (директор)\n";
