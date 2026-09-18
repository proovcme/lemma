<?php
$buckets = $buckets ?? [];
$rows = $rows ?? [];
$filters = $filters ?? [];
$departments = $departments ?? [];
$managers = $managers ?? [];
$projects = $projects ?? [];
$canSeeTeam = (bool) ($canSeeTeam ?? false);
$presetLabels = [
    'week' => 'Неделя',
    'month' => 'Месяц',
    'quarter' => 'Квартал',
    'year' => 'Год',
];
$fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, '.', ' '), '0'), '.');
?>

<section class="panel resource-filter-panel">
    <form class="admin-user-filterbar" method="get" action="<?= url('/resources') ?>">
        <label>
            <span>Горизонт</span>
            <select name="preset">
                <?php foreach ($presetLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= selected((string) ($filters['preset'] ?? 'month'), $key) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($canSeeTeam): ?>
            <label>
                <span>Отдел</span>
                <select name="department">
                    <option value="">Все</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['code']) ?>"<?= selected((string) ($filters['department'] ?? ''), (string) $department['code']) ?>><?= e($department['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Руководитель</span>
                <select name="manager_id">
                    <option value="">Все</option>
                    <?php foreach ($managers as $manager): ?>
                        <option value="<?= (int) $manager['id'] ?>"<?= selected((string) ($filters['manager_id'] ?? ''), (string) $manager['id']) ?>><?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>
            <span>Проект</span>
            <select name="project_id">
                <option value="">Все</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"<?= selected((string) ($filters['project_id'] ?? ''), (string) $project['id']) ?>><?= e($project['code']) ?> · <?= e($project['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn--red" type="submit">Показать</button>
        <a class="btn btn-outline" href="<?= url('/resources') ?>">Сбросить</a>
    </form>
</section>

<section class="resource-legend">
    <span class="resource-dot resource-dot--idle"></span> нет спроса
    <span class="resource-dot resource-dot--free"></span> резерв
    <span class="resource-dot resource-dot--ok"></span> норма
    <span class="resource-dot resource-dot--over"></span> перегруз
</section>

<section class="panel resource-matrix-panel">
    <div class="panel__head">
        <h2>Матрица загрузки</h2>
        <span><?= count($rows) ?> строк</span>
    </div>
    <div class="table-wrap resource-table-wrap">
        <table class="data-table resource-table">
            <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Итого</th>
                <?php foreach ($buckets as $bucket): ?>
                    <th><?= e($bucket['label']) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="resource-person">
                        <strong><?= e($row['name'] ?? '') ?></strong>
                        <small>
                            <?= e(($row['position_title'] ?? '') !== '' ? $row['position_title'] : role_label($row['role'] ?? '')) ?>
                            <?= ($row['department'] ?? '') !== '' ? ' · ' . e($row['department']) : '' ?>
                        </small>
                        <?php if (($row['manager_name'] ?? '') !== ''): ?><small>рук. <?= e($row['manager_name']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <strong><?= (int) ($row['total_pct'] ?? 0) ?>%</strong>
                        <small><?= e($fmt((float) ($row['total_demand'] ?? 0))) ?> / <?= e($fmt((float) ($row['total_capacity'] ?? 0))) ?> ч</small>
                        <?php if ((int) ($row['unplanned'] ?? 0) > 0): ?><small class="status status--correction">без оценки: <?= (int) $row['unplanned'] ?></small><?php endif; ?>
                    </td>
                    <?php foreach (($row['cells'] ?? []) as $cell): ?>
                        <td class="resource-cell resource-cell--<?= e($cell['zone']) ?>">
                            <details>
                                <summary>
                                    <strong><?= (int) $cell['pct'] ?>%</strong>
                                    <span><?= e($fmt((float) $cell['demand'])) ?> / <?= e($fmt((float) $cell['capacity'])) ?> ч</span>
                                </summary>
                                <?php if (!empty($cell['tasks'])): ?>
                                    <ul class="resource-task-list">
                                        <?php foreach ($cell['tasks'] as $task): ?>
                                            <li><a href="<?= url('/tasks/' . (int) $task['id']) ?>">#<?= (int) $task['id'] ?> <?= e($task['title']) ?></a><span><?= e($fmt((float) $task['hours'])) ?> ч</span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="muted">Активного спроса нет.</p>
                                <?php endif; ?>
                            </details>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= 2 + count($buckets) ?>"><div class="empty">Сотрудники не найдены.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
