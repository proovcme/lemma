<?php
$today = new DateTimeImmutable('today');
$dayWidth = 30;
$rowHeight = 54;
$minDate = null;
$maxDate = null;

$tasks = array_map(static function (array $task) use ($today): array {
    $start = new DateTimeImmutable((string) ($task['start'] ?: $today->format('Y-m-d')));
    $end = new DateTimeImmutable((string) ($task['end'] ?: $start->format('Y-m-d')));
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }
    $task['start_date'] = $start;
    $task['end_date'] = $end;
    return $task;
}, $ganttTasks);

foreach ($tasks as $task) {
    $minDate = $minDate === null || $task['start_date'] < $minDate ? $task['start_date'] : $minDate;
    $maxDate = $maxDate === null || $task['end_date'] > $maxDate ? $task['end_date'] : $maxDate;
}

$timelineStart = ($minDate ?: $today)->modify('-2 days');
$timelineEnd = ($maxDate ?: $today->modify('+30 days'))->modify('+7 days');
if ($timelineEnd < $today->modify('+14 days')) {
    $timelineEnd = $today->modify('+14 days');
}

$totalDays = max(1, (int) $timelineStart->diff($timelineEnd)->days + 1);
$timelineWidth = max(980, $totalDays * $dayWidth);
$todayOffset = max(0, min($totalDays, (int) $timelineStart->diff($today)->format('%r%a'))) * $dayWidth;

$months = [];
$weeks = [];
for ($cursor = $timelineStart; $cursor <= $timelineEnd; $cursor = $cursor->modify('+1 day')) {
    $monthKey = $cursor->format('Y-m');
    if (!isset($months[$monthKey])) {
        $months[$monthKey] = [
            'label' => $cursor->format('m.Y'),
            'days' => 0,
        ];
    }
    $months[$monthKey]['days']++;

    if ($cursor == $timelineStart || $cursor->format('N') === '1') {
        $weeks[] = [
            'label' => $cursor->format('d.m'),
            'offset' => (int) $timelineStart->diff($cursor)->days * $dayWidth,
        ];
    }
}

$taskLayouts = [];
$taskIndexByKey = [];
foreach ($tasks as $index => $task) {
    $offsetDays = (int) $timelineStart->diff($task['start_date'])->format('%r%a');
    $durationDays = max(1, (int) $task['start_date']->diff($task['end_date'])->days + 1);
    $left = max(0, $offsetDays * $dayWidth);
    $width = max(28, $durationDays * $dayWidth);
    $taskLayouts[$index] = [
        'left' => $left,
        'width' => $width,
        'center_y' => (int) (($index * $rowHeight) + ($rowHeight / 2)),
        'late' => $task['status'] !== 'done' && $task['end_date'] < $today,
    ];

    $taskKey = trim((string) ($task['id'] ?? ''));
    if ($taskKey !== '') {
        $taskIndexByKey[$taskKey] = $index;
    }
    $taskIndexByKey['db:' . (int) $task['db_id']] = $index;
}

$dependencyLines = [];
foreach ($tasks as $targetIndex => $task) {
    $dependencies = array_unique(array_filter(array_map('trim', explode(',', (string) ($task['dependencies'] ?? '')))));
    foreach ($dependencies as $dependency) {
        $sourceIndex = $taskIndexByKey[$dependency] ?? $taskIndexByKey['db:' . $dependency] ?? null;
        if ($sourceIndex === null || $sourceIndex === $targetIndex || !isset($taskLayouts[$sourceIndex], $taskLayouts[$targetIndex])) {
            continue;
        }

        $source = $taskLayouts[$sourceIndex];
        $target = $taskLayouts[$targetIndex];
        $startX = (int) ($source['left'] + $source['width'] + 2);
        $endX = max(0, (int) $target['left'] - 2);
        $startY = (int) $source['center_y'];
        $endY = (int) $target['center_y'];
        $bendX = $endX > $startX + 28
            ? (int) round($startX + (($endX - $startX) / 2))
            : max($startX + 18, $endX + 18);
        $dependencyLines[] = sprintf('M %d %d H %d V %d H %d', $startX, $startY, $bendX, $endY, $endX);
    }
}
$timelineHeight = max(1, count($tasks) * $rowHeight);
$arrowId = 'gantt-link-arrow-' . (int) $project['id'];
$canViewProjectFinance = (bool) ($canViewProjectFinance ?? false);
?>
<section class="project-head">
    <div>
        <span class="muted"><?= e($project['code']) ?> · <?= e($project['title']) ?></span>
        <h2>План проекта</h2>
    </div>
</section>

<?php $projectNavActive = 'plan'; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>
<?php $projectPlanActive = 'gantt'; require BASE_PATH . '/app/Views/projects/_plan_navigation.php'; ?>

<section class="gantt-workspace gantt-plan">
    <div class="gantt-toolbar">
        <div class="gantt-toolbar__title">
            <strong>План задач</strong>
            <span><?= count($tasks) ?> задач · <?= count($dependencyLines) ?> связей · шаг шкалы <?= $dayWidth ?> px/день</span>
        </div>
        <a class="btn btn-outline" href="<?= url('/projects/' . $project['id'] . '/tasks') ?>">Открыть задачи</a>
    </div>

    <?php if (!$tasks): ?>
        <div class="empty-state">
            <strong>Нет задач для план-графика</strong>
            <span>Создайте задачи проекта или импортируйте их из MSP.</span>
        </div>
    <?php else: ?>
        <div class="gantt-plan__scroll">
            <div class="gantt-plan__grid" style="--timeline-width: <?= (int) $timelineWidth ?>px; --row-height: <?= (int) $rowHeight ?>px;">
                <div class="gantt-plan__left">
                    <div class="gantt-plan__left-head">
                        <span>Задача</span>
                        <span>Раздел</span>
                        <span>Срок</span>
                        <span>%</span>
                    </div>
                    <?php foreach ($tasks as $task): ?>
                        <?php $outlineLevel = max(0, (int) ($task['outline_level'] ?? 1) - 1); ?>
                        <a class="gantt-plan__left-row" href="<?= url('/tasks/' . (int) $task['db_id']) ?>" data-task-drawer-link style="--task-outline-level: <?= (int) $outlineLevel ?>;">
                            <strong>#<?= (int) $task['db_id'] ?> <?= e($task['name']) ?></strong>
                            <span><?= e($task['section'] ?: $task['discipline'] ?: $task['volume']) ?></span>
                            <span class="<?= $task['status'] === 'overdue' ? 'text-red' : '' ?>"><?= e(format_date($task['end_date']->format('Y-m-d'))) ?></span>
                            <span><?= (int) $task['progress'] ?>%</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="gantt-plan__timeline" style="width: <?= (int) $timelineWidth ?>px;">
                    <div class="gantt-plan__scale" style="width: <?= (int) $timelineWidth ?>px;">
                        <div class="gantt-plan__months">
                            <?php foreach ($months as $month): ?>
                                <span style="width: <?= (int) ($month['days'] * $dayWidth) ?>px;"><?= e($month['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="gantt-plan__weeks">
                            <?php foreach ($weeks as $week): ?>
                                <span style="left: <?= (int) $week['offset'] ?>px;"><?= e($week['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="gantt-plan__rows" style="width: <?= (int) $timelineWidth ?>px;">
                        <span class="gantt-plan__today" style="left: <?= (int) $todayOffset ?>px;"></span>
                        <?php foreach ($tasks as $index => $task): ?>
                            <?php
                            $taskLayout = $taskLayouts[$index];
                            $left = $taskLayout['left'];
                            $width = $taskLayout['width'];
                            $late = $taskLayout['late'];
                            ?>
                            <div class="gantt-plan__row">
                                <a
                                    class="gantt-plan__bar<?= $late ? ' gantt-plan__bar--late' : '' ?>"
                                    href="<?= url('/tasks/' . (int) $task['db_id']) ?>"
                                    data-task-drawer-link
                                    style="left: <?= (int) $left ?>px; width: <?= (int) $width ?>px; --progress: <?= (int) $task['progress'] ?>%;"
                                    title="#<?= (int) $task['db_id'] ?> <?= e($task['name']) ?>"
                                >
                                    <span><?= e($task['section'] ?: $task['discipline'] ?: ('#' . $task['db_id'])) ?></span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($dependencyLines): ?>
                            <svg class="gantt-plan__links" width="<?= (int) $timelineWidth ?>" height="<?= (int) $timelineHeight ?>" viewBox="0 0 <?= (int) $timelineWidth ?> <?= (int) $timelineHeight ?>" aria-hidden="true" focusable="false">
                                <defs>
                                    <marker id="<?= e($arrowId) ?>" class="gantt-plan__link-arrow" markerWidth="8" markerHeight="8" refX="7" refY="0" orient="auto" markerUnits="strokeWidth">
                                        <path d="M 0 -4 L 8 0 L 0 4 z"></path>
                                    </marker>
                                </defs>
                                <?php foreach ($dependencyLines as $linePath): ?>
                                    <path class="gantt-plan__link" d="<?= e($linePath) ?>" marker-end="url(#<?= e($arrowId) ?>)"></path>
                                <?php endforeach; ?>
                            </svg>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
