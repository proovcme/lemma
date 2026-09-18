<?php
use App\Services\TimeApprovalService;
use App\Services\TimeService;

$dates = $model['dates'] ?? [];
$rows = $model['rows'] ?? [];
$tasks = $model['tasks'] ?? [];
$breakdown = $model['breakdown'] ?? [];
$totals = $model['totals'] ?? [];
$target = $model['target'] ?? [];
$weekMinutes = (int) ($model['weekMinutes'] ?? 0);
$weekTarget = (int) ($model['weekTargetMinutes'] ?? 0);
$remainingWeek = $weekTarget - $weekMinutes;
$selectedDate = (string) ($selectedDate ?? date('Y-m-d'));
$weekStart = (string) ($model['weekStart'] ?? TimeService::weekStart($selectedDate));
$weekEnd = (string) ($model['weekEnd'] ?? $weekStart);
$prevWeek = (string) ($model['prevWeek'] ?? $weekStart);
$nextWeek = (string) ($model['nextWeek'] ?? $weekStart);
$today = date('Y-m-d');
$todayWeek = TimeService::weekStart($today);
$selectedRemaining = max(0, (int) ($target[$selectedDate] ?? 0) - (int) ($totals[$selectedDate] ?? 0));
$quickDefaultHours = TimeService::minutesToHours($selectedRemaining);
$selectedTaskId = (int) ($_GET['task_id'] ?? 0);
$monthStart = (string) ($monthStart ?? date('Y-m-01'));
$monthEnd = (string) ($monthEnd ?? date('Y-m-t'));
$monthReview = is_array($monthReview ?? null) ? $monthReview : null;
$timeCategories = $timeCategories ?? ($categories ?? []);
$timePhases = $timePhases ?? ($phases ?? []);
$monthLockedForEdit = (bool) ($monthLockedForEdit ?? false);
$weekday = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
$hours = static fn (int $minutes): string => TimeService::minutesToHours($minutes);
$hoursText = static function (int $minutes): string {
    $value = TimeService::minutesToHours(abs($minutes));
    return ($minutes < 0 ? '+' : '') . ($value === '' ? '0' : $value) . ' ч';
};
$dayTone = static function (int $minutes, int $targetMinutes): string {
    if ($targetMinutes === 0) {
        return $minutes > 0 ? 'time-day--overtime' : '';
    }
    if ($minutes === $targetMinutes) {
        return 'time-day--ok';
    }
    if ($minutes > $targetMinutes) {
        return 'time-day--overtime';
    }

    return 'time-day--under';
};
?>

<div class="time-metrics">
    <article class="metric">
        <span><?= e($hours($weekMinutes) ?: '0') ?></span>
        <strong>Списано за неделю</strong>
    </article>
    <article class="metric">
        <span><?= e($hours($weekTarget) ?: '0') ?></span>
        <strong>Норма</strong>
    </article>
    <article class="metric">
        <span><?= e($hoursText($remainingWeek)) ?></span>
        <strong><?= $remainingWeek >= 0 ? 'Осталось' : 'Сверх нормы' ?></strong>
    </article>
</div>

<section class="panel">
    <div class="panel__head">
        <h2>Месяц <?= e(format_date($monthStart)) ?> — <?= e(format_date($monthEnd)) ?></h2>
        <span class="pill"><?= e(TimeApprovalService::reviewStatusLabel($monthReview)) ?></span>
    </div>
    <?php if ($monthReview && !empty($monthReview['return_comment'])): ?>
        <p class="muted">Возврат: <?= e((string) $monthReview['return_comment']) ?></p>
    <?php elseif ($monthLockedForEdit): ?>
        <p class="muted">Месяц закрыт руководителем. Эти часы зафиксированы для отчётов и больше не редактируются.</p>
    <?php else: ?>
        <p class="muted">Месяц открыт: можно спокойно списывать и править время. Руководитель в конце месяца снимет срез и закроет факт.</p>
    <?php endif; ?>
</section>

<section class="panel time-days">
    <div class="panel__head">
        <div>
            <h2>Неделя <?= e(format_date($weekStart)) ?> — <?= e(format_date($weekEnd)) ?></h2>
            <span class="muted">Выберите день в календаре или перейдите на соседнюю неделю.</span>
        </div>
        <div class="time-week-nav" aria-label="Навигация по неделям">
            <a class="btn btn-outline btn-sm" href="<?= url('/time?week=' . $prevWeek . '&date=' . $prevWeek) ?>" aria-label="Предыдущая неделя">&lt; Неделя</a>
            <form class="time-calendar-form" action="<?= url('/time') ?>" method="get" data-time-calendar-form>
                <label>
                    <span>Календарь</span>
                    <input type="date" name="date" value="<?= e($selectedDate) ?>" data-time-calendar-input>
                </label>
                <button class="btn btn-outline btn-sm" type="submit">Открыть</button>
            </form>
            <a class="btn btn-outline btn-sm" href="<?= url('/time?week=' . $todayWeek . '&date=' . $today) ?>">Сегодня</a>
            <a class="btn btn-outline btn-sm" href="<?= url('/time?week=' . $nextWeek . '&date=' . $nextWeek) ?>" aria-label="Следующая неделя">Неделя &gt;</a>
            <span class="pill"><?= e($hours($weekTarget) ?: '0') ?> ч</span>
        </div>
    </div>
    <div class="time-day-grid">
        <?php foreach ($dates as $index => $date): ?>
            <?php
            $dayMinutes = (int) ($totals[$date] ?? 0);
            $targetMinutes = (int) ($target[$date] ?? 0);
            ?>
            <a class="time-day <?= e($dayTone($dayMinutes, $targetMinutes)) ?><?= $date === $selectedDate ? ' is-active' : '' ?>" href="<?= url('/time?week=' . $weekStart . '&date=' . $date) ?>">
                <strong><?= e($weekday[$index] ?? '') ?> · <?= e(format_day_month($date)) ?></strong>
                <span><?= e($hours($dayMinutes) ?: '0') ?> / <?= e($hours($targetMinutes) ?: '0') ?> ч</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel time-breakdown">
    <div class="panel__head">
        <h2>Куда ушло время за неделю</h2>
        <span class="muted">по вашим строкам табеля</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Проект</th>
                <th>Работа</th>
                <th>ПП / БТП</th>
                <th>Тип</th>
                <th>Дней</th>
                <th>Часы</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($breakdown as $row): ?>
                <?php
                $taskId = (int) ($row['task_id'] ?? 0);
                $category = (string) ($row['category'] ?? '');
                $phase = (string) ($row['phase'] ?? '');
                $ppLabel = trim((string) (($row['pp_code'] ?? '') . (($row['pp_title'] ?? '') !== '' ? ' · ' . ($row['pp_title'] ?? '') : '')));
                $btpLabel = trim((string) (($row['btp_code'] ?? '') . (($row['btp_title'] ?? '') !== '' ? ' · ' . ($row['btp_title'] ?? '') : '')));
                ?>
                <tr>
                    <td>
                        <strong><?= e(($row['project_code'] ?? '') ?: 'Без проекта') ?></strong>
                        <small><?= e($row['project_title'] ?? '') ?></small>
                    </td>
                    <td>
                        <?php if ($taskId > 0): ?>
                            <a href="<?= url('/tasks/' . $taskId) ?>"><strong>#<?= $taskId ?> <?= e($row['task_title'] ?? '') ?></strong></a>
                        <?php else: ?>
                            <strong><?= e($timeCategories[$category] ?? 'Непроектная строка') ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($ppLabel ?: '-') ?></strong><small><?= e($btpLabel ?: '-') ?></small></td>
                    <td><strong><?= e($timeCategories[$category] ?? 'Задача') ?></strong><small><?= e($timePhases[$phase] ?? $phase ?: '-') ?></small></td>
                    <td><?= (int) ($row['days_count'] ?? 0) ?></td>
                    <td><strong><?= e($hours((int) ($row['minutes'] ?? 0)) ?: '0') ?> ч</strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$breakdown): ?>
                <tr><td colspan="6"><span class="muted">За неделю пока нет списаний.</span></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>Пакетное списание</h2>
        <span><?= e(format_date($selectedDate)) ?></span>
    </div>
    <form class="time-batch-form" method="post" action="<?= url('/time/distribute') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="work_date" value="<?= e($selectedDate) ?>">
        <label>
            <span>Часы</span>
            <input type="number" min="0" max="24" step="0.25" name="total_hours" value="<?= e($quickDefaultHours) ?>"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
        </label>
        <label>
            <span>Способ</span>
            <select name="method"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
                <option value="even">Равномерно</option>
                <option value="planned">По плану/остатку</option>
            </select>
        </label>
        <label>
            <span>Фаза</span>
            <select name="phase"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
                <option value="auto">По статусу задачи</option>
                <?php foreach ($phases as $phase => $label): ?>
                    <option value="<?= e($phase) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="time-task-pick">
            <?php foreach (array_slice($tasks, 0, 36) as $task): ?>
                <label>
                    <input type="checkbox" name="task_ids[]" value="<?= (int) $task['id'] ?>"<?= checked((int) $task['id'] === $selectedTaskId) ?><?= $monthLockedForEdit ? ' disabled' : '' ?>>
                    <span>
                        <strong>#<?= (int) $task['id'] ?> · <?= e($task['title']) ?></strong>
                        <small><?= e(($task['project_code'] ?? '') . ' · ' . task_status_label((string) ($task['status'] ?? 'new'))) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
            <?php if (!$tasks): ?>
                <p class="muted">Нет активных задач для списания.</p>
            <?php endif; ?>
        </div>
        <div class="time-batch-actions">
            <button class="btn btn--red" type="submit" name="action" value="distribute"<?= $monthLockedForEdit ? ' disabled' : '' ?>>Распределить</button>
            <button class="btn" type="submit" name="action" value="repeat_previous"<?= $monthLockedForEdit ? ' disabled' : '' ?>>Повторить вчера</button>
        </div>
    </form>
</section>

<form id="time-week-form" class="panel" method="post" action="<?= url('/time/week') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="week" value="<?= e($model['weekStart'] ?? '') ?>">
    <div class="panel__head">
        <h2>Табель недели</h2>
        <button class="btn btn--red" type="submit"<?= $monthLockedForEdit ? ' disabled' : '' ?>>Сохранить</button>
    </div>
    <div class="table-wrap">
        <table class="data-table time-table">
            <thead>
            <tr>
                <th>Работа</th>
                <th>Фаза</th>
                <?php foreach ($dates as $index => $date): ?>
                    <th><?= e($weekday[$index] ?? '') ?><small><?= e(format_day_month($date)) ?></small></th>
                <?php endforeach; ?>
                <th>Итого</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $rowTotal = array_sum(array_map('intval', $row['minutes'] ?? [])); ?>
                <tr class="<?= ($row['type'] ?? '') === 'category' ? 'time-row--category' : '' ?>">
                    <td>
                        <?php if (($row['type'] ?? '') === 'task'): ?>
                            <a href="<?= url('/tasks/' . (int) $row['task_id']) ?>"><strong><?= e($row['label']) ?></strong></a>
                        <?php else: ?>
                            <strong><?= e($row['label']) ?></strong>
                        <?php endif; ?>
                        <small><?= e($row['meta'] ?? '') ?></small>
                    </td>
                    <td>
                        <?php if (($row['type'] ?? '') === 'task'): ?>
                            <select name="phase[<?= e($row['key']) ?>]"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
                                <?php foreach ($phases as $phase => $label): ?>
                                    <option value="<?= e($phase) ?>"<?= selected($row['phase'] ?? '', $phase) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <span class="muted"><?= e($phases[$row['phase'] ?? 'other'] ?? 'Другое') ?></span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($dates as $date): ?>
                        <?php $value = $hours((int) (($row['minutes'] ?? [])[$date] ?? 0)); ?>
                        <td>
                            <input type="number" min="0" max="24" step="0.25" name="hours[<?= e($row['key']) ?>][<?= e($date) ?>]" value="<?= e($value) ?>" aria-label="<?= e(($row['label'] ?? '') . ' ' . $date) ?>"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
                        </td>
                    <?php endforeach; ?>
                    <td><strong><?= e($hours($rowTotal) ?: '0') ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2">Итого</th>
                <?php foreach ($dates as $date): ?>
                    <th><?= e($hours((int) ($totals[$date] ?? 0)) ?: '0') ?></th>
                <?php endforeach; ?>
                <th><?= e($hours($weekMinutes) ?: '0') ?></th>
            </tr>
            </tfoot>
        </table>
    </div>
    <div class="time-week-comment">
        <label>
            <span>Комментарий к пачке</span>
            <input name="comment" maxlength="500" placeholder="Опционально"<?= $monthLockedForEdit ? ' disabled' : '' ?>>
        </label>
    </div>
</form>
