<?php
$projectId = (int) $project['id'];
$comments = (array) ($report['comments'] ?? []);
$comment = static fn (string $type, int $id): string => (string) ($comments[$type . ':' . $id] ?? '');
$statusLabels = ['new'=>'Новая','in_progress'=>'В работе','review'=>'На проверке','pending_close'=>'На проверке','correction'=>'Корректировка','blocked'=>'Заблокирована','overdue'=>'Просрочена','done'=>'Закрыта'];
$commentForm = static function (string $type, int $id, string $value) use ($projectId, $period, $canComment): void { if (!$canComment) { if ($value !== '') echo '<p class="project-health-comment"><strong>Комментарий:</strong> ' . e($value) . '</p>'; return; } ?>
    <form class="project-health-comment-form" method="post" action="<?= url('/projects/' . $projectId . '/health-report/comment') ?>"><?= csrf_field() ?><input type="hidden" name="date_from" value="<?= e($period['date_from']) ?>"><input type="hidden" name="date_to" value="<?= e($period['date_to']) ?>"><input type="hidden" name="entity_type" value="<?= e($type) ?>"><input type="hidden" name="entity_id" value="<?= $id ?>"><label><span>Комментарий ГИПа</span><textarea name="comment_text" rows="2" maxlength="4000" placeholder="Что происходит и что делаем дальше"><?= e($value) ?></textarea></label><button class="btn btn-outline btn-sm" type="submit">Сохранить комментарий</button></form>
<?php };
$taskRows = static function (array $tasks) use ($statusLabels, $comment, $commentForm): void { ?>
    <div class="table-wrap"><table class="data-table data-table--compact project-health-task-table"><thead><tr><th>Задача</th><th>Статус</th><th>Исполнитель</th><th>Срок</th><th>Проблема</th></tr></thead><tbody>
    <?php foreach ($tasks as $task): ?><tr class="<?= !empty($task['is_problem']) ? 'is-problem' : '' ?>"><td><a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link><strong>#<?= (int) $task['id'] ?> <?= e($task['title']) ?></strong></a><?php $commentForm('task', (int) $task['id'], $comment('task', (int) $task['id'])); ?></td><td><?= e($statusLabels[$task['status']] ?? $task['status']) ?></td><td><?= e($task['assignee_name'] ?: 'Не назначен') ?></td><td><?= e(format_date($task['date_end'] ?? '')) ?: '—' ?></td><td><?php if ($task['problems']): ?><?php foreach ($task['problems'] as $problem): ?><span class="badge badge--danger"><?= e($problem) ?></span><?php endforeach; ?><?php else: ?><span class="badge badge--soft">Без явных отклонений</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$tasks): ?><tr><td colspan="5" class="muted">За период и среди открытых задач строк нет.</td></tr><?php endif; ?>
    </tbody></table></div>
<?php };
?>
<div class="topbar"><div class="topbar__meta"><span><?= e($project['code']) ?></span><h1>Что у нас плохого</h1><p>Автоматический отчёт ГИПа по отклонениям, задачам и составу разделов.</p></div><div class="topbar__actions"><a class="btn btn-outline" href="<?= url('/projects/' . $projectId) ?>">К проекту</a><a class="btn btn-outline" href="<?= url('/projects/' . $projectId . '/structure') ?>">Структура</a></div></div>
<?php $projectNavActive = 'health'; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<section class="panel"><div class="panel__head"><div><h2>Период отчёта</h2><span>По умолчанию — текущая неделя.</span></div></div><form class="filterbar project-health-period" method="get"><label><span>С</span><input type="date" lang="ru-RU" name="date_from" value="<?= e($period['date_from']) ?>"></label><label><span>По</span><input type="date" lang="ru-RU" name="date_to" value="<?= e($period['date_to']) ?>"></label><button class="btn btn--red" type="submit">Сформировать</button></form></section>

<div class="metric-row project-summary-metrics">
    <div class="metric"><span><?= (int) $report['summary']['open'] ?></span><strong>открыто</strong></div><div class="metric"><span><?= (int) $report['summary']['problem'] ?></span><strong>проблемных</strong></div><div class="metric"><span><?= (int) $report['summary']['overdue'] ?></span><strong>просрочено</strong></div><div class="metric"><span><?= (int) $report['summary']['blocked'] ?></span><strong>блокеры</strong></div><div class="metric"><span><?= (int) $report['summary']['review'] ?></span><strong>проверка</strong></div><div class="metric"><span><?= (int) $report['summary']['done_period'] ?></span><strong>закрыто за период</strong></div>
</div>

<section class="panel project-health-overview"><div class="panel__head"><div><h2>Вывод по проекту</h2><span><?= format_date($period['date_from']) ?> — <?= format_date($period['date_to']) ?></span></div></div><?php $commentForm('project', 0, $comment('project', 0)); ?></section>

<section class="project-health-register" aria-label="Статистика по стадиям и разделам">
<?php foreach ($report['structure'] as $stage): ?>
    <?php $stageId = (int) ($stage['id'] ?? 0); $stageProblem = (int) ($stage['stats']['problem'] ?? 0); ?>
    <details class="panel project-health-stage<?= $stageProblem > 0 ? ' is-problem' : '' ?>"<?= $stageProblem > 0 ? ' open' : '' ?>><summary class="project-health-stage__summary"><span><strong><?= e($stage['code']) ?></strong> · <?= e($stage['title']) ?></span><span class="project-health-stage__stats"><?= (int) $stage['stats']['open'] ?> открыто · <?= $stageProblem ?> проблемных · <?= (int) $stage['stats']['done_period'] ?> закрыто</span><span class="details-toggle-label" aria-hidden="true"><span class="details-toggle-label__closed">Развернуть</span><span class="details-toggle-label__open">Свернуть</span></span></summary>
        <div class="project-health-stage__body"><?php if ($stageId > 0) $commentForm('stage', $stageId, $comment('stage', $stageId)); ?>
        <?php foreach ($stage['sections'] as $section): ?>
            <?php $sectionId = (int) $section['id']; $problem = (int) $section['stats']['problem']; ?>
            <details class="project-health-section<?= $problem > 0 ? ' is-problem' : '' ?>"<?= $problem > 0 ? ' open' : '' ?>><summary><span><strong><?= e($section['code']) ?></strong> · <?= e($section['title']) ?></span><span><?= (int) $section['stats']['open'] ?> открыто · <?= $problem ?> проблемных · <?= (int) $section['stats']['done_period'] ?> закрыто</span></summary><div class="project-health-section__body"><p class="muted"><b>Разрабатывают:</b> <?= e($section['executors'] ? implode(', ', array_column($section['executors'], 'name')) : 'не назначены') ?> · <b>Проверяют:</b> <?= e($section['reviewers'] ? implode(', ', array_column($section['reviewers'], 'name')) : 'не назначены') ?></p><?php $commentForm('section', $sectionId, $comment('section', $sectionId)); ?><?php $taskRows($section['tasks']); ?></div></details>
        <?php endforeach; ?>
        <?php if (!$stage['sections']): ?><div class="empty-state empty-state--compact">Строк структуры нет.</div><?php endif; ?></div>
    </details>
<?php endforeach; ?>
</section>

<?php if ($report['unlinked']): ?><details class="panel project-health-unlinked is-problem" open><summary class="project-health-stage__summary"><span><strong>Без привязки к структуре</strong></span><span><?= count($report['unlinked']) ?> задач — требуется разобрать</span></summary><div class="project-health-stage__body"><?php $taskRows($report['unlinked']); ?></div></details><?php endif; ?>
