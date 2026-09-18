<?php
$filters = $filters ?? [];
$filterValue = static fn (string $key): string => (string) ($filters[$key] ?? '');
?>

<section class="panel">
    <form class="filterbar" method="get" action="<?= url('/activity') ?>">
        <input type="search" name="q" value="<?= e($filterValue('q')) ?>" placeholder="Поиск по событию">
        <select name="project_id" aria-label="Проект">
            <option value="">Все проекты</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"<?= selected($filterValue('project_id'), $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action" aria-label="Тип события">
            <option value="">Все события</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>"<?= selected($filterValue('action'), $action) ?>><?= e($action) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" min="1" name="task_id" value="<?= e($filterValue('task_id')) ?>" placeholder="ID задачи">
        <input type="date" name="date_from" value="<?= e($filterValue('date_from')) ?>" aria-label="Дата от">
        <input type="date" name="date_to" value="<?= e($filterValue('date_to')) ?>" aria-label="Дата до">
        <button class="btn btn-outline" type="submit">Показать</button>
        <?php if (array_filter([$filterValue('q'), $filterValue('project_id'), $filterValue('action'), $filterValue('task_id'), $filterValue('date_from'), $filterValue('date_to')])): ?>
            <a class="btn" href="<?= url('/activity') ?>">Сбросить</a>
        <?php endif; ?>
    </form>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>Журнал событий</h2>
        <span><?= count($rows) ?></span>
    </div>
    <?php
    $compact = false;
    $emptyText = 'Ничего не найдено.';
    require BASE_PATH . '/app/Views/activity/_list.php';
    ?>
</section>
