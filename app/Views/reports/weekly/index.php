<?php
$reports = $reports ?? [];
$projects = $projects ?? [];
$statusLabels = $statusLabels ?? [];
$periodTypeLabels = $periodTypeLabels ?? [];
$defaultPeriod = $defaultPeriod ?? ['period_type' => 'week', 'date_from' => date('Y-m-d', strtotime('monday this week')), 'date_to' => date('Y-m-d', strtotime('sunday this week'))];
$canEditWeeklyReports = (bool) ($canEditWeeklyReports ?? false);
$statusText = static fn (?string $status): string => $statusLabels[$status ?? ''] ?? (string) $status;
$periodText = static fn (?string $periodType): string => $periodTypeLabels[$periodType ?? ''] ?? (string) $periodType;
?>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Отчёты</span>
            <h2>Периодические отчёты</h2>
        </div>
        <div class="toolbar__actions">
            <a class="btn btn-outline" href="<?= url('/reports') ?>">Обычные отчёты</a>
        </div>
    </div>
</section>

<?php if ($canEditWeeklyReports): ?>
    <form class="panel form-grid" method="post" action="<?= url('/reports/periodic') ?>">
        <?= csrf_field() ?>
        <div class="panel__head form-grid__full">
            <h2>Собрать черновик</h2>
            <button class="btn" type="submit">Создать</button>
        </div>
        <label>
            <span>Тип периода</span>
            <select name="period_type">
                <?php foreach ($periodTypeLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($defaultPeriod['period_type'] ?? 'week', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Дата начала / опорная дата</span><input type="date" name="date_from" value="<?= e($defaultPeriod['date_from'] ?? date('Y-m-d')) ?>" required></label>
        <label><span>Дата конца</span><input type="date" name="date_to" value="<?= e($defaultPeriod['date_to'] ?? date('Y-m-d')) ?>" required></label>
        <label class="form-grid__full"><span>Адресат</span><input type="text" name="recipient" value="Куратор проектов / Заказчик"></label>
        <div class="form-grid__full checkbox-grid">
            <?php foreach ($projects as $project): ?>
                <label>
                    <input type="checkbox" name="project_ids[]" value="<?= (int) $project['id'] ?>">
                    <?= e($project['code'] . ' · ' . $project['title']) ?>
                </label>
            <?php endforeach; ?>
            <?php if (!$projects): ?><p class="muted">Нет доступных активных проектов.</p><?php endif; ?>
        </div>
    </form>
<?php else: ?>
    <section class="panel">
        <p class="muted">Создание черновиков доступно ГИПу, руководителю проекта, зам. директора, директору и администратору.</p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel__head">
        <h2>Журнал отчётов</h2>
        <span class="muted"><?= count($reports) ?> записей</span>
    </div>
    <div class="table-wrap">
        <table class="data-table analytics-table">
            <thead>
            <tr>
                <th>Период</th><th>Тип</th><th>Проекты</th><th>Статус</th><th>Состояние</th><th>Автор</th><th>Обновлён</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $report): ?>
                <tr class="clickable" data-href="<?= url('/reports/periodic/' . (int) $report['id']) ?>">
                    <td><strong><?= e(format_date($report['date_from'] ?? '')) ?> - <?= e(format_date($report['date_to'] ?? '')) ?></strong><small><?= e($report['recipient'] ?? '') ?></small></td>
                    <td><?= e($periodText($report['period_type'] ?? 'week')) ?></td>
                    <td><?= e($report['project_codes'] ?? '') ?></td>
                    <td><?= e($statusText($report['portfolio_status'] ?? '')) ?></td>
                    <td><?= (string) ($report['state'] ?? '') === 'locked' ? 'Зафиксирован' : 'Черновик' ?></td>
                    <td><?= e($report['author_name'] ?? '') ?></td>
                    <td><?= e(format_date($report['updated_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?>
                <tr><td colspan="7"><span class="muted">Отчётов пока нет.</span></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
