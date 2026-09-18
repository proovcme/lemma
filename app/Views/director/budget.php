<?php
require __DIR__ . '/_tabs.php';
$metrics = (array) ($dashboard['metrics'] ?? []);
$projects = (array) ($dashboard['projects'] ?? []);
$payments = (array) ($dashboard['payments'] ?? []);
$cashflow = (array) ($dashboard['cashflow'] ?? []);
$year = (int) ($dashboard['year'] ?? date('Y'));
$money = static fn (mixed $value): string => number_format((float) $value, 0, ',', ' ');
$monthNames = [1 => 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
$statusLabels = ['planned' => 'Запланирован', 'invoiced' => 'Счёт выставлен', 'received' => 'Получен', 'cancelled' => 'Отменён'];
?>

<section class="metric-row project-summary-metrics budget-kpis" aria-label="Сводка бюджета">
    <div class="metric"><span><?= e($money($metrics['portfolio_budget'] ?? 0)) ?></span><strong>Бюджет портфеля, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['planned_cost'] ?? 0)) ?></span><strong>Затраты, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['planned_profit'] ?? 0)) ?></span><strong>Прибыль, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['planned_bonus'] ?? 0)) ?></span><strong>Премиальная часть, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['received_payments'] ?? 0)) ?></span><strong>Получено, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['receivable'] ?? 0)) ?></span><strong>Осталось получить, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['actual_cost'] ?? 0)) ?></span><strong>Фактические затраты, ₽</strong></div>
    <div class="metric"><span><?= e($money($metrics['budget_margin'] ?? 0)) ?></span><strong>Бюджетный остаток, ₽</strong></div>
</section>

<section class="panel">
    <div class="panel__head">
        <div><h2>Денежный поток</h2><p class="muted">Поступления из графиков платежей; расходы — ФОТ, нагрузка и накладные из закрытого ШР.</p></div>
        <form class="budget-year-filter" method="get" action="<?= url('/director/budget') ?>"><label><span>Год</span><input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></label><button class="btn btn-outline" type="submit">Показать</button></form>
    </div>
    <div class="budget-cashflow-chart" role="img" tabindex="0" aria-label="Плановые и фактические поступления и расходы по месяцам">
        <?php foreach ($cashflow as $row): $month = (int) substr((string) $row['month'], 5, 2); ?>
            <div class="budget-chart-month" title="<?= e($monthNames[$month] . ': план ' . $money($row['planned_income']) . ', факт ' . $money($row['actual_income']) . ', расходы ' . $money($row['staffing_cost'])) ?>">
                <div class="budget-chart-bars"><i class="is-plan" style="--bar-height:<?= e($row['planned_percent']) ?>%"></i><i class="is-actual" style="--bar-height:<?= e($row['actual_percent']) ?>%"></i><i class="is-cost" style="--bar-height:<?= e($row['cost_percent']) ?>%"></i></div>
                <strong><?= e($monthNames[$month]) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="budget-chart-legend"><span class="is-plan">План поступлений</span><span class="is-actual">Получено</span><span class="is-cost">ШР и накладные</span></div>
    <div class="table-wrapper"><table class="data-table budget-cashflow-table"><thead><tr><th>Месяц</th><th>План поступлений</th><th>Получено</th><th>ШР + нагрузка</th><th>Плановый поток</th><th>Фактический поток</th><th>Накопительно</th></tr></thead><tbody>
        <?php foreach ($cashflow as $row): $month = (int) substr((string) $row['month'], 5, 2); ?><tr><td><?= e($monthNames[$month]) ?></td><td><?= e($money($row['planned_income'])) ?></td><td><?= e($money($row['actual_income'])) ?></td><td><?= e($money($row['staffing_cost'])) ?></td><td class="<?= (float) $row['plan_net'] < 0 ? 'text-danger' : '' ?>"><?= e($money($row['plan_net'])) ?></td><td class="<?= (float) $row['actual_net'] < 0 ? 'text-danger' : '' ?>"><?= e($money($row['actual_net'])) ?></td><td class="<?= (float) $row['cumulative'] < 0 ? 'text-danger' : '' ?>"><?= e($money($row['cumulative'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel">
    <div class="panel__head"><div><h2>Бюджеты проектов</h2><p class="muted">Договорный бюджет редактируется здесь; факт включает труд по ставкам и УТС.</p></div></div>
    <div class="table-wrapper"><table class="data-table budget-project-table"><thead><tr><th>Проект</th><th>Срок</th><th>Структура бюджета, тыс. ₽</th><th>Итого</th><th>График</th><th>Получено</th><th>Факт затрат</th><th>Остаток</th><th></th></tr></thead><tbody>
        <?php foreach ($projects as $project): $formId = 'project-budget-' . (int) $project['id']; ?>
            <tr>
                <td><a href="<?= url('/projects/' . (int) $project['id']) ?>"><strong><?= e($project['code']) ?></strong><small><?= e($project['title']) ?></small></a></td>
                <td><?= e(format_date($project['start_date'] ?? '') ?: '—') ?><small><?= e(format_date($project['finish_date'] ?? '') ?: '—') ?></small></td>
                <td><form id="<?= $formId ?>" class="budget-split-fields" method="post" action="<?= url('/director/budget/projects/' . (int) $project['id']) ?>"><?= csrf_field() ?><label><small>Итого</small><input type="number" name="budget_total_thousand" min="0" step="0.01" value="<?= e($project['budget_manual_thousand'] ?? '0') ?>"></label><label><small>Затраты</small><input type="number" name="budget_cost_thousand" min="0" step="0.01" value="<?= e($project['budget_cost_thousand'] ?? '0') ?>"></label><label><small>Прибыль</small><input type="number" name="budget_profit_thousand" min="0" step="0.01" value="<?= e($project['budget_profit_thousand'] ?? '0') ?>"></label><label><small>Премия</small><input type="number" name="budget_bonus_thousand" min="0" step="0.01" value="<?= e($project['budget_bonus_thousand'] ?? '0') ?>"></label><input type="hidden" name="budget_comment" value="<?= e($project['budget_comment'] ?? '') ?>"></form></td>
                <td><strong><?= e($money((float) ($project['budget_manual_thousand'] ?? 0))) ?></strong><small>тыс. ₽</small></td>
                <td><?= e($money($project['planned_payments'])) ?></td><td><?= e($money($project['received_payments'])) ?></td><td><?= e($money($project['actual_cost'])) ?></td><td class="<?= (float) $project['budget_remaining'] < 0 ? 'text-danger' : '' ?>"><?= e($money($project['budget_remaining'])) ?></td>
                <td class="budget-row-actions"><button class="btn btn-sm btn-outline" type="submit" form="<?= $formId ?>">Сохранить</button><a class="btn btn-sm btn-outline" href="<?= url('/director/budget?project_id=' . (int) $project['id']) ?>#payment-schedule">Платежи</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel" id="payment-schedule">
    <div class="panel__head">
        <div><h2>График платежей</h2><p class="muted">План, выставление счёта и фактическое поступление фиксируются отдельно.</p></div>
        <form class="budget-payment-filter" method="get" action="<?= url('/director/budget') ?>"><input type="hidden" name="year" value="<?= $year ?>"><label><span>Проект</span><select name="project_id"><option value="">Все проекты</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"<?= selected((string) $selectedProjectId, (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option><?php endforeach; ?></select></label><button class="btn btn-outline" type="submit">Фильтр</button></form>
    </div>
    <details class="budget-payment-create">
        <summary><span>Добавить этап платежа</span><small>Развернуть / свернуть</small></summary>
        <form class="form-grid" method="post" action="<?= url('/director/budget/payments') ?>">
            <?= csrf_field() ?>
            <label><span>Проект</span><select name="project_id" required><option value="">Выберите проект</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"<?= selected((string) $selectedProjectId, (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option><?php endforeach; ?></select></label>
            <label><span>Этап</span><input name="payment_name" required placeholder="Аванс, ПД, РД, финальный расчёт"></label>
            <label><span>Плановая дата</span><input type="date" name="planned_date" required></label>
            <label><span>Плановая сумма, ₽</span><input type="number" name="planned_amount" min="0.01" step="0.01" required></label>
            <input type="hidden" name="status" value="planned"><input type="hidden" name="actual_amount" value="0">
            <label class="form-grid__full"><span>Комментарий</span><input name="comment" placeholder="Условие договора или основание"></label>
            <div class="form-grid__full"><button class="btn btn--red" type="submit">Добавить платёж</button></div>
        </form>
    </details>
    <div class="budget-payment-list">
        <?php foreach ($payments as $payment): $overdue = in_array($payment['status'], ['planned', 'invoiced'], true) && (string) $payment['planned_date'] < date('Y-m-d'); ?>
            <details class="budget-payment-row<?= $overdue ? ' is-overdue' : '' ?>">
                <summary><span><strong><?= e($payment['project_code'] . ' · ' . $payment['payment_name']) ?></strong><small><?= e(format_date($payment['planned_date']) . ' · ' . $money($payment['planned_amount']) . ' ₽') ?></small></span><span class="status-badge <?= $payment['status'] === 'received' ? 'status-badge--ok' : ($overdue ? 'status-badge--danger' : 'status-badge--muted') ?>"><?= e($statusLabels[$payment['status']] ?? $payment['status']) ?></span></summary>
                <form class="form-grid" method="post" action="<?= url('/director/budget/payments/' . (int) $payment['id']) ?>">
                    <?= csrf_field() ?>
                    <label><span>Проект</span><select name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"<?= selected((string) $payment['project_id'], (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option><?php endforeach; ?></select></label>
                    <label><span>Этап</span><input name="payment_name" required value="<?= e($payment['payment_name']) ?>"></label>
                    <label><span>Плановая дата</span><input type="date" name="planned_date" required value="<?= e($payment['planned_date']) ?>"></label>
                    <label><span>Плановая сумма, ₽</span><input type="number" name="planned_amount" min="0.01" step="0.01" required value="<?= e($payment['planned_amount']) ?>"></label>
                    <label><span>Статус</span><select name="status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>"<?= selected((string) $payment['status'], $key) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label><span>Дата счёта</span><input type="date" name="invoice_date" value="<?= e($payment['invoice_date'] ?? '') ?>"></label>
                    <label><span>Дата получения</span><input type="date" name="actual_date" value="<?= e($payment['actual_date'] ?? '') ?>"></label>
                    <label><span>Получено, ₽</span><input type="number" name="actual_amount" min="0" step="0.01" value="<?= e($payment['actual_amount']) ?>"></label>
                    <label class="form-grid__full"><span>Комментарий</span><input name="comment" value="<?= e($payment['comment'] ?? '') ?>"></label>
                    <div class="form-grid__full budget-payment-actions"><button class="btn btn--red" type="submit">Сохранить платёж</button></div>
                </form>
                <form class="budget-payment-delete" method="post" action="<?= url('/director/budget/payments/' . (int) $payment['id'] . '/delete') ?>" onsubmit="return confirm('Удалить этот платёж из графика?')"><?= csrf_field() ?><button class="btn btn-sm btn-outline" type="submit">Удалить платёж</button></form>
            </details>
        <?php endforeach; ?>
        <?php if (!$payments): ?><div class="empty-state"><strong>График платежей пока пуст</strong><p>Добавьте первый этап или снимите фильтр проекта.</p></div><?php endif; ?>
    </div>
</section>
