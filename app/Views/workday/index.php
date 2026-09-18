<?php
use App\Services\TimeService;

$model = $model ?? [];
$filters = $filters ?? [];
$categories = $categories ?? [];
$phases = $phases ?? [];
$hours = static fn (int|float $minutes): string => TimeService::minutesToHours((int) $minutes) ?: '0';
$formatNumber = static function (mixed $value, int $precision = 1): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$projectLabel = static function (array $task): string {
    $code = trim((string) ($task['project_code'] ?? ''));
    $title = trim((string) ($task['project_title'] ?? ''));
    if ($code !== '' && $title !== '') {
        return $code . ' · ' . $title;
    }

    return $code !== '' ? $code : ($title !== '' ? $title : 'Без проекта');
};
$taskCard = static function (array $task): string {
    $deadline = trim((string) ($task['date_end'] ?? ''));
    $deadlineText = $deadline !== '' ? format_date($deadline) : 'без срока';
    return '#' . (int) $task['id'] . ' · ' . $deadlineText;
};
$metricCards = [
    ['label' => 'Принять', 'value' => count($model['waiting'] ?? []), 'hint' => 'новые и возвращённые'],
    ['label' => 'В работе', 'value' => count($model['inWork'] ?? []), 'hint' => 'активные задачи'],
    ['label' => 'Проверить', 'value' => count($model['review'] ?? []), 'hint' => 'ждут решения'],
    ['label' => 'Сроки', 'value' => count($model['due'] ?? []), 'hint' => 'до 7 дней и просрочка'],
    ['label' => 'Сегодня', 'value' => $hours($model['timeToday']['totalMinutes'] ?? 0), 'hint' => 'списано часов'],
];
$absenceLabels = ['vacation', 'sick_leave', 'business_trip', 'learning', 'day_off'];
$vacations = $vacations ?? [];
$vacationSubstitutions = $vacationSubstitutions ?? [];
$vacationCandidates = $vacationCandidates ?? [];
$dayNotifications = $dayNotifications ?? [];
$performanceReviewActions = $performanceReviewActions ?? ['personal' => [], 'manager_ready' => []];
$managerMatrixActions = array_values(array_filter($performanceReviewActions['manager_ready'] ?? [], static fn (array $review): bool => ($review['manager_state'] ?? '') === 'ready'));
$managerMeetingActions = array_values(array_filter($performanceReviewActions['manager_ready'] ?? [], static fn (array $review): bool => ($review['manager_state'] ?? '') === 'meeting_ready'));
?>

<div class="workday-primary-actions">
    <a class="btn btn-outline" href="<?= url(app_task_hub_path()) ?>">Все мои задачи</a>
</div>

<?php if (!empty($performanceReviewActions['personal']) || !empty($performanceReviewActions['manager_ready'])): ?>
    <section class="workday-review-callouts" aria-label="Действия Performance Review">
        <?php foreach (($performanceReviewActions['personal'] ?? []) as $review): ?>
            <?php
            $reviewAction = empty($review['self_questionnaire_submitted_at'])
                ? 'Заполнить анкету'
                : (empty($review['self_matrix_submitted_at']) ? 'Оценить компетенции' : 'Открыть результаты');
            ?>
            <article class="panel workday-review-callout">
                <div class="workday-review-callout__mark" aria-hidden="true">PR</div>
                <div>
                    <span><?= ($review['cycle_kind'] ?? 'annual') === 'test' ? 'Тестовый Performance Review' : 'Performance Review открыт' ?></span>
                    <h2><?= e($review['cycle_title'] ?? '') ?></h2>
                    <p>Ваш этап уже запущен<?= !empty($review['response_deadline']) ? '. Заполните до ' . e(format_date((string) $review['response_deadline'])) : '' ?>.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>"><?= e($reviewAction) ?></a>
            </article>
        <?php endforeach; ?>
        <?php if ($managerMatrixActions !== []): ?>
            <article class="panel workday-review-callout workday-review-callout--manager">
                <div class="workday-review-callout__mark" aria-hidden="true"><?= count($managerMatrixActions) ?></div>
                <div>
                    <span>Оценки сотрудников</span>
                    <h2>Самооценка завершена</h2>
                    <p>Можно провести независимую оценку. Ответы сотрудников останутся скрыты до отправки вашей оценки.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/manager') ?>">Перейти к оценкам</a>
            </article>
        <?php endif; ?>
        <?php if ($managerMeetingActions !== []): ?>
            <article class="panel workday-review-callout workday-review-callout--manager">
                <div class="workday-review-callout__mark" aria-hidden="true"><?= count($managerMeetingActions) ?></div>
                <div>
                    <span>Performance Review сотрудников</span>
                    <h2>Зафиксировать итоговую встречу</h2>
                    <p>Обе независимые оценки завершены. Заполните итоги встречи и шаги сотрудника на следующий год.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/manager') ?>">Перейти к итогам</a>
            </article>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($dayNotifications): ?>
    <section class="panel notification-center workday-notifications">
        <div class="panel__head">
            <div>
                <h2>Уведомления</h2>
                <span class="muted">То, что требует вашего внимания сегодня</span>
            </div>
            <a class="btn btn-outline btn-sm" href="<?= url('/notifications') ?>">Все уведомления</a>
        </div>
        <div class="notification-list">
            <?php foreach ($dayNotifications as $notification): ?>
                <article class="notification-item is-unread">
                    <div class="notification-item__body">
                        <div class="notification-item__meta"><span><?= e(format_date((string) ($notification['created_at'] ?? ''))) ?></span></div>
                        <p><?= e($notification['body'] ?? '') ?></p>
                        <?php if (!empty($notification['target_url'])): ?><a href="<?= url((string) $notification['target_url']) ?>">Открыть</a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="metric-row workday-metrics">
    <?php foreach ($metricCards as $card): ?>
        <article class="metric">
            <span><?= e((string) $card['value']) ?></span>
            <strong><?= e($card['label']) ?></strong>
            <small><?= e($card['hint']) ?></small>
        </article>
    <?php endforeach; ?>
</section>

<details class="panel workday-vacation"<?= ($vacations || $vacationSubstitutions) ? ' open' : '' ?>>
    <summary class="panel__head">
        <span>Режим «Отпуск»</span>
        <small>Даты и сотрудник на замену · развернуть / свернуть</small>
    </summary>
    <div class="workday-vacation__grid">
        <form class="form-grid" method="post" action="<?= url('/my-day/vacations') ?>">
            <?= csrf_field() ?>
            <label><span>С даты</span><input type="date" name="date_from" min="<?= e(date('Y-m-d')) ?>" required></label>
            <label><span>По дату включительно</span><input type="date" name="date_to" min="<?= e(date('Y-m-d')) ?>" required></label>
            <label class="form-grid__full"><span>Кто заменяет</span><select name="substitute_user_id" required><option value="">Выберите сотрудника</option><?php foreach ($vacationCandidates as $candidate): if ((int) $candidate['id'] === (int) (current_user()['id'] ?? 0)) continue; ?><option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['name']) ?><?= !empty($candidate['department']) ? ' · ' . e($candidate['department']) : '' ?></option><?php endforeach; ?></select></label>
            <label class="form-grid__full"><span>Комментарий</span><input name="note" maxlength="500" placeholder="Что передать заместителю — необязательно"></label>
            <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit">Включить режим «Отпуск»</button></div>
        </form>
        <div class="workday-vacation__status">
            <?php foreach ($vacations as $vacation): ?>
                <?php $activeVacation = (string) $vacation['date_from'] <= date('Y-m-d') && (string) $vacation['date_to'] >= date('Y-m-d'); ?>
                <article class="vacation-card<?= $activeVacation ? ' is-active' : '' ?>">
                    <strong><?= $activeVacation ? 'Сейчас в отпуске' : 'Запланирован отпуск' ?></strong>
                    <span><?= e(format_date($vacation['date_from'])) ?>–<?= e(format_date($vacation['date_to'])) ?></span>
                    <small>Замена: <?= e($vacation['substitute_name']) ?><?= !empty($vacation['note']) ? ' · ' . e($vacation['note']) : '' ?></small>
                    <form method="post" action="<?= url('/my-day/vacations/' . (int) $vacation['id'] . '/cancel') ?>" onsubmit="return confirm('Отменить режим отпуска?')"><?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Отменить</button></form>
                </article>
            <?php endforeach; ?>
            <?php foreach ($vacationSubstitutions as $substitution): ?>
                <article class="vacation-card vacation-card--substitution">
                    <strong>Вы замещаете: <?= e($substitution['absent_name']) ?></strong>
                    <span><?= e(format_date($substitution['date_from'])) ?>–<?= e(format_date($substitution['date_to'])) ?></span>
                    <?php if (!empty($substitution['note'])): ?><small><?= e($substitution['note']) ?></small><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$vacations && !$vacationSubstitutions): ?><p class="muted">Отпуск не запланирован, замещений нет.</p><?php endif; ?>
        </div>
    </div>
</details>

<section class="workday-grid">
    <div class="panel workday-panel">
        <div class="panel__head">
            <h2>Ждёт ответа</h2>
            <span><?= count($model['waiting'] ?? []) ?></span>
        </div>
        <div class="workday-list">
            <?php foreach (($model['waiting'] ?? []) as $task): ?>
                <article class="workday-task">
                    <div>
                        <strong><a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link><?= e($task['title'] ?? '') ?></a></strong>
                        <span class="workday-task__project"><?= e($projectLabel($task)) ?></span>
                        <?php if (!empty($task['substitute_for_name'])): ?><span class="tag tag-assignment">Замещение: <?= e($task['substitute_for_name']) ?></span><?php endif; ?>
                        <small><?= e($taskCard($task)) ?></small>
                    </div>
                    <form method="post" action="<?= url('/tasks/' . (int) $task['id'] . '/assignment-response') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="accepted">
                        <input type="hidden" name="back" value="/my-day">
                        <button class="btn btn--red btn-sm" type="submit">Принять</button>
                    </form>
                    <form class="workday-reject" method="post" action="<?= url('/tasks/' . (int) $task['id'] . '/assignment-response') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="rejected">
                        <input type="hidden" name="back" value="/my-day">
                        <input name="comment" required placeholder="Причина отклонения">
                        <button class="btn btn-outline btn-sm" type="submit">Отклонить</button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (empty($model['waiting'])): ?><p class="muted">Нет задач, ожидающих принятия.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel workday-panel">
        <div class="panel__head">
            <h2>В работе</h2>
            <span><?= count($model['inWork'] ?? []) ?></span>
        </div>
        <div class="workday-list">
            <?php foreach (($model['inWork'] ?? []) as $task): ?>
                <article class="workday-task">
                    <div>
                        <strong><a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link><?= e($task['title'] ?? '') ?></a></strong>
                        <span class="workday-task__project"><?= e($projectLabel($task)) ?></span>
                        <?php if (!empty($task['substitute_for_name'])): ?><span class="tag tag-assignment">Замещение: <?= e($task['substitute_for_name']) ?></span><?php endif; ?>
                        <small><?= e($taskCard($task)) ?> · план <?= e($formatNumber($task['planned_hours'] ?? 0)) ?> ч · факт <?= e($formatNumber($task['actual_hours'] ?? 0)) ?> ч</small>
                    </div>
                    <form class="workday-time" method="post" action="<?= url('/time/task') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <input type="hidden" name="work_date" value="<?= e($model['today'] ?? date('Y-m-d')) ?>">
                        <input type="hidden" name="phase" value="execution">
                        <input type="hidden" name="back" value="/my-day">
                        <input type="number" min="0.25" max="24" step="0.25" name="hours" placeholder="ч" required>
                        <button class="btn btn-sm" type="submit">Списать</button>
                        <button class="btn btn-outline btn-sm" type="submit" name="quick" value="0.5" formnovalidate>+0.5 ч</button>
                        <button class="btn btn-outline btn-sm" type="submit" name="quick" value="1" formnovalidate>+1 ч</button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (empty($model['inWork'])): ?><p class="muted">Активных задач нет.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel workday-panel">
        <div class="panel__head">
            <h2>На проверке</h2>
            <span><?= count($model['review'] ?? []) ?></span>
        </div>
        <div class="workday-list">
            <?php foreach (($model['review'] ?? []) as $task): ?>
                <article class="workday-task">
                    <div>
                        <strong><a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link><?= e($task['title'] ?? '') ?></a></strong>
                        <span class="workday-task__project"><?= e($projectLabel($task)) ?></span>
                        <?php if (!empty($task['substitute_for_name'])): ?><span class="tag tag-assignment">Замещение: <?= e($task['substitute_for_name']) ?></span><?php endif; ?>
                        <small><?= e($taskCard($task)) ?> · исполнитель <?= e($task['assignee_name'] ?? 'не назначен') ?></small>
                    </div>
                    <form class="workday-time" method="post" action="<?= url('/time/task') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <input type="hidden" name="work_date" value="<?= e($model['today'] ?? date('Y-m-d')) ?>">
                        <input type="hidden" name="phase" value="review">
                        <input type="hidden" name="back" value="/my-day">
                        <input type="number" min="0.25" max="24" step="0.25" name="hours" placeholder="ч" required>
                        <button class="btn btn-sm" type="submit">Время</button>
                    </form>
                    <a class="btn btn-outline btn-sm" href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link>Решить</a>
                </article>
            <?php endforeach; ?>
            <?php if (empty($model['review'])): ?><p class="muted">Нет задач на проверке.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel workday-panel">
        <div class="panel__head">
            <h2>Сроки</h2>
            <span><?= count($model['due'] ?? []) ?></span>
        </div>
        <div class="workday-list">
            <?php foreach (($model['due'] ?? []) as $task): ?>
                <?php $deadlineClass = deadline_state_class($task['date_end'] ?? null, $model['today'] ?? null); ?>
                <article class="workday-task">
                    <div>
                        <strong><a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link><?= e($task['title'] ?? '') ?></a></strong>
                        <span class="workday-task__project"><?= e($projectLabel($task)) ?></span>
                        <small class="<?= e($deadlineClass) ?>"><?= e($taskCard($task)) ?> · <?= e(task_status_label($task['status'] ?? '')) ?></small>
                    </div>
                    <a class="btn btn-outline btn-sm" href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link>Открыть</a>
                </article>
            <?php endforeach; ?>
            <?php if (empty($model['due'])): ?><p class="muted">Ближайших сроков и просрочек нет.</p><?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($model['canSeeTeamLoad'])): ?>
    <?php $managementSummary = $model['managementSummary'] ?? []; ?>
    <section class="panel workday-management-brief">
        <div class="panel__head"><div><h2>Справка руководителя</h2><p class="muted">Люди и проекты, которым сейчас нужно внимание.</p></div><div class="form-actions"><a class="btn btn-outline" href="<?= url('/team') ?>">Команда</a><a class="btn btn-outline" href="<?= url('/projects') ?>">Проекты</a></div></div>
        <div class="metric-row project-summary-metrics">
            <article class="metric"><span><?= (int) ($managementSummary['employees'] ?? 0) ?></span><strong>Сотрудники</strong><small>в текущей зоне видимости</small></article>
            <article class="metric"><span><?= (int) ($managementSummary['overloaded'] ?? 0) ?></span><strong>Перегруз</strong><small>по плановым часам</small></article>
            <article class="metric"><span><?= (int) ($managementSummary['employee_overdue'] ?? 0) ?></span><strong>Просрочки</strong><small>у сотрудников</small></article>
            <article class="metric"><span><?= (int) ($managementSummary['projects_at_risk'] ?? 0) ?></span><strong>Проекты в риске</strong><small>есть просроченные задачи</small></article>
        </div>
        <div class="table-wrapper">
            <table class="data-table data-table--compact" data-no-column-filters><thead><tr><th>Проект</th><th>Открыто</th><th>Просрочено</th><th>Людей в работе</th></tr></thead><tbody>
            <?php foreach (($model['managementProjects'] ?? []) as $project): ?><tr><td><a href="<?= url('/projects/' . (int) $project['id']) ?>"><strong><?= e($project['code']) ?></strong> · <?= e($project['title']) ?></a></td><td><?= (int) $project['open_tasks'] ?></td><td class="<?= (int) $project['overdue_tasks'] > 0 ? 'text-danger' : '' ?>"><?= (int) $project['overdue_tasks'] ?></td><td><?= (int) $project['active_people'] ?></td></tr><?php endforeach; ?>
            <?php if (empty($model['managementProjects'])): ?><tr><td colspan="4">Активных проектов в текущей зоне видимости нет.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </section>

    <section class="panel workday-load">
        <div class="panel__head">
            <div>
                <h2>Загрузка команды</h2>
                <span class="muted">плановые часы активных задач против доступности периода</span>
            </div>
            <form class="filterbar" method="get" action="<?= url('/my-day') ?>">
                <select name="department" aria-label="Отдел">
                    <option value="">Все отделы</option>
                    <?php foreach (($model['departments'] ?? []) as $department): ?>
                        <option value="<?= e($department) ?>"<?= selected($filters['department'] ?? '', $department) ?>><?= e($department) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>
                    <span>Дата начала</span>
                    <input type="date" name="date_from" value="<?= e($model['dateFrom'] ?? '') ?>">
                </label>
                <label>
                    <span>Дата конца</span>
                    <input type="date" name="date_to" value="<?= e($model['dateTo'] ?? '') ?>">
                </label>
                <button class="btn btn-outline" type="submit">Показать</button>
            </form>
        </div>
        <div class="table-wrap">
            <table class="data-table analytics-table">
                <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>Статус</th>
                    <th>Загрузка</th>
                    <th>Доступно, ч</th>
                    <th>Отсутствия</th>
                    <th>План/факт, ч</th>
                    <th>Остаток, ч</th>
                    <th>Задачи</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($model['teamLoad'] ?? []) as $row): ?>
                    <?php
                    $loadClass = match ((string) ($row['load_status'] ?? '')) {
                        'overloaded' => 'cell-danger',
                        'busy', 'vacation' => 'cell-warning',
                        default => '',
                    };
                    ?>
                    <tr>
                        <td><strong><?= e($row['name'] ?? '') ?></strong><small><?= e(($row['department'] ?? '') ?: 'без отдела') ?> · <?= e(role_label($row['role'] ?? '')) ?></small></td>
                        <td class="<?= e($loadClass) ?>"><?= e($row['load_label'] ?? '') ?></td>
                        <td>
                            <div class="analytics-bar"><span style="width: <?= e(min(100, max(2, (int) ($row['load_percent'] ?? 0)))) ?>%"></span></div>
                            <small><?= e($formatNumber($row['load_percent'] ?? 0, 0)) ?> %</small>
                        </td>
                        <td><?= e($formatNumber($row['available_hours'] ?? 0)) ?><small>норма <?= e($formatNumber($row['capacity_hours'] ?? 0)) ?></small></td>
                        <td>
                            <?php foreach ($absenceLabels as $category): ?>
                                <?php if ((float) (($row['absence'][$category] ?? 0)) > 0): ?>
                                    <span class="tag"><?= e($categories[$category] ?? $category) ?> · <?= e($formatNumber($row['absence'][$category] ?? 0)) ?> ч</span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($row['absence'])): ?><span class="muted">нет</span><?php endif; ?>
                        </td>
                        <td><?= e($formatNumber($row['planned_open_hours'] ?? 0)) ?> / <?= e($formatNumber($row['actual_hours'] ?? 0)) ?></td>
                        <td><?= e($formatNumber($row['remaining_hours'] ?? 0)) ?></td>
                        <td><?= e((string) (int) ($row['open_tasks'] ?? 0)) ?><small>проср. <?= e((string) (int) ($row['overdue_tasks'] ?? 0)) ?> · скоро <?= e((string) (int) ($row['due_week_tasks'] ?? 0)) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($model['teamLoad'])): ?>
                    <tr><td colspan="8"><span class="muted">Нет данных по загрузке.</span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
