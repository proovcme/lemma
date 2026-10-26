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

<?php if (!empty($model['canSeeTeamLoad'])): ?><details class="workspace-management"><summary>Проекты и загрузка команды</summary>
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
            <?php foreach (($model['managementProjects'] ?? []) as $managementProject): ?><tr><td><a href="<?= url('/projects/' . (int) $managementProject['id']) ?>"><strong><?= e($managementProject['code']) ?></strong> · <?= e($managementProject['title']) ?></a></td><td><?= (int) $managementProject['open_tasks'] ?></td><td class="<?= (int) $managementProject['overdue_tasks'] > 0 ? 'text-danger' : '' ?>"><?= (int) $managementProject['overdue_tasks'] ?></td><td><?= (int) $managementProject['active_people'] ?></td></tr><?php endforeach; ?>
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
</details><?php endif; ?>