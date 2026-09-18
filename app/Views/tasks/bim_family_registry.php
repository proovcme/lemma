<?php
$filters = $filters ?? [];
$statuses = ['new', 'in_progress', 'review', 'correction', 'pending_close', 'done', 'blocked', 'overdue'];
$filterValue = static fn (string $key): string => (string) ($filters[$key] ?? '');
$imageLink = static function (?string $value): string {
    $entries = custom_link_entries($value);
    if (!$entries) {
        return '—';
    }

    $entry = $entries[0];
    $label = $entry['label'] !== '' ? $entry['label'] : 'Открыть';
    $href = file_link_href($entry['url']);

    return '<a href="' . e($href) . '">' . e($label) . '</a>';
};
?>

<section class="panel">
    <form class="filterbar" method="get" action="<?= url('/tasks/bim-family') ?>">
        <select name="project_id" aria-label="Проект">
            <option value="">Все проекты</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"<?= selected($filterValue('project_id'), $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assignee_id" aria-label="Ответственный BIM">
            <option value="">Все ответственные</option>
            <?php foreach ($users as $taskUser): ?>
                <option value="<?= (int) $taskUser['id'] ?>"<?= selected($filterValue('assignee_id'), $taskUser['id']) ?>><?= e($taskUser['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="Статус">
            <option value="">Все статусы</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>"<?= selected($filterValue('status'), $status) ?>><?= e(task_status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($filterValue('date_from')) ?>" aria-label="Срок от">
        <input type="date" name="date_to" value="<?= e($filterValue('date_to')) ?>" aria-label="Срок до">
        <button class="btn btn-outline" type="submit">Показать</button>
        <?php if (array_filter([$filterValue('project_id'), $filterValue('assignee_id'), $filterValue('status'), $filterValue('date_from'), $filterValue('date_to')])): ?>
            <a class="btn" href="<?= url('/tasks/bim-family') ?>">Сбросить</a>
        <?php endif; ?>
    </form>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>Реестр заявок</h2>
        <span><?= count($rows) ?></span>
    </div>
    <div class="table-wrap table-wrap--wide" tabindex="0" aria-label="Таблица заявок на семейства ТИМ">
        <table class="data-table data-table--compact">
            <thead>
            <tr>
                <th>Проект</th>
                <th>Модель</th>
                <th>Раздел</th>
                <th>Исполнитель</th>
                <th>Изображение</th>
                <th>Комментарий / описание задачи</th>
                <th>Ответ BIM отдела</th>
                <th>Ответственный от BIM отдела</th>
                <th>Электрические коннекторы</th>
                <th>Статус</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $descriptionParts = array_filter([
                    trim((string) ($row['what'] ?? '')),
                    trim((string) ($row['why'] ?? '')),
                ]);
                ?>
                <tr class="clickable" data-href="<?= url('/tasks/' . (int) $row['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . (int) $row['id']) ?>">
                    <td><?= e($row['project_code'] ?: '—') ?></td>
                    <td><?= e($row['bim_model'] ?: '—') ?></td>
                    <td><?= e(($row['discipline'] ?: $row['section']) ?: '—') ?></td>
                    <td><?= e($row['author_name'] ?: '—') ?></td>
                    <td><?= $imageLink($row['bim_image'] ?? '') ?></td>
                    <td>
                        <strong>#<?= (int) $row['id'] ?> · <?= e($row['title']) ?></strong>
                        <?php if ($descriptionParts): ?><br><span><?= e(implode("\n", $descriptionParts)) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($row['bim_response'] ?: '—') ?></td>
                    <td><?= e($row['assignee_name'] ?: '—') ?></td>
                    <td><?= e($row['bim_electrical_connectors'] ?: '—') ?></td>
                    <td><?= e(task_status_label($row['status'] ?? 'new')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="10"><span class="muted">Заявок по выбранным условиям нет.</span></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
