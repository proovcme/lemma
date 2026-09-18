<?php
require __DIR__ . '/_tabs.php';
$metrics = (array) ($portfolio['metrics'] ?? []);
$rows = (array) ($portfolio['rows'] ?? []);
$money = static fn (mixed $value): string => number_format((float) $value, 0, ',', ' ');
?>

<section class="metric-row project-summary-metrics" aria-label="Сводка портфеля">
    <div class="metric"><span><?= (int) ($metrics['expected_count'] ?? 0) ?></span><strong>Ожидаемые</strong></div>
    <div class="metric"><span><?= e($money($metrics['expected_amount'] ?? 0)) ?></span><strong>Расчётная сумма, тыс. ₽</strong></div>
    <div class="metric"><span><?= (int) ($metrics['live_count'] ?? 0) ?></span><strong>Живые проекты</strong></div>
    <div class="metric"><span><?= e($money($metrics['live_budget'] ?? 0)) ?></span><strong>Бюджет живых, тыс. ₽</strong></div>
    <div class="metric"><span><?= (int) ($metrics['overdue'] ?? 0) ?></span><strong>Просроченные задачи</strong></div>
</section>

<section class="panel">
    <div class="panel__head"><div><h2>Воронка проектов</h2><p class="muted">Ожидаемые сохраняются из калькулятора и предпроектных расчётов; живые — из проектов с обязательным бюджетом.</p></div></div>
    <div class="table-wrapper"><table class="data-table portfolio-table"><thead><tr><th>Тип</th><th>Проект</th><th>Стадия</th><th>ГИП / РП</th><th>Сроки</th><th>Сумма, тыс. ₽</th><th>Задачи</th><th>Статус</th></tr></thead><tbody>
    <?php foreach ($rows as $row):
        $source = (string) ($row['source'] ?? 'project');
        $href = $source === 'calculator' ? '/calculator' : ($source === 'preproject' ? '/cost-estimates/' . (int) $row['id'] : '/projects/' . (int) $row['id']);
        $total = (int) ($row['tasks_total'] ?? 0); $done = (int) ($row['tasks_done'] ?? 0);
    ?>
        <tr>
            <td><span class="status-badge <?= !empty($row['is_expected']) ? 'status-badge--muted' : 'status-badge--ok' ?>"><?= $source === 'calculator' ? 'Расчёт' : (!empty($row['is_expected']) ? 'Предпроект' : 'Живой') ?></span></td>
            <td><a href="<?= url($href) ?>"><strong><?= e($row['code']) ?></strong><small><?= e($row['title']) ?></small></a></td>
            <td><?= e($row['stage'] ?? '—') ?></td>
            <td><?= e($source === 'calculator' ? 'Автор: ' . ($row['gip_name'] ?? '—') : ($row['gip_name'] ?? 'Не назначен')) ?><small><?= e($source === 'calculator' ? (($row['area_m2'] ?? null) ? $money($row['area_m2']) . ' м²' : 'Площадь не указана') : ($row['rp_name'] ?? 'РП не назначен')) ?></small></td>
            <td><?= e(format_date($row['start_date'] ?? '') ?: '—') ?><small><?= e(format_date($row['finish_date'] ?? '') ?: '—') ?></small></td>
            <td><strong><?= e($money($row['amount_thousand'] ?? 0)) ?></strong></td>
            <td><?= $done ?> / <?= $total ?><small><?= (int) ($row['tasks_overdue'] ?? 0) ?> просрочено</small></td>
            <td><?= e($source === 'calculator' ? 'Ожидается' : ($row['status'] === 'active' ? 'Активен' : 'Архив')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8"><div class="empty-state"><strong>Портфель пока пуст</strong><p>Создайте ожидаемый расчёт или живой проект.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
