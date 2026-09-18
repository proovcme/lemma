<?php
$period = $dashboard['period'];
$money = static fn ($value): string => number_format((float) $value, 2, ',', ' ');
?>
<div class="staffing-print-actions"><button class="btn btn-red" type="button" onclick="window.print()">Печать</button></div>
<article class="staffing-print-sheet">
    <header><span>Лемма · Управление командой</span><h1>Штатное расписание</h1><p><?= e(date('m.Y', strtotime($period['month_start']))) ?> · версия <?= (int) $period['revision'] ?> · <?= e($period['status']) ?></p></header>
    <section class="staffing-print-summary"><div><span>Прямой ФОТ</span><strong><?= $money($dashboard['total_fot']) ?> ₽</strong></div><div><span>Полный бюджет</span><strong><?= $money($dashboard['full_budget']) ?> ₽</strong></div><div><span>Штатных единиц</span><strong><?= $money($dashboard['total_fte']) ?></strong></div></section>
    <table><thead><tr><th>Раздел</th><th>Подразделение</th><th>Должность</th><th>ФИО / позиция</th><th>Таб. №</th><th>Ставок</th><th>ФОТ, ₽/мес</th><th>₽/день</th><th>₽/час</th><th>Статус</th></tr></thead><tbody>
    <?php foreach ($dashboard['rows'] as $row): $fte = max(0.01, (float) $row['fte']); ?><tr><td><?= e($row['department_code']) ?></td><td><?= e($row['group_name'] ?: $row['department_name']) ?></td><td><?= e($row['position_title']) ?></td><td><?= e($row['employee_name']) ?></td><td><?= e($row['tab_number']) ?></td><td><?= $money($row['fte']) ?></td><td><?= $money($row['monthly_fot']) ?></td><td><?= $money((float) $row['monthly_fot'] / ((float) $period['working_days'] * $fte)) ?></td><td><?= $money((float) $row['monthly_fot'] / ((float) $period['working_hours'] * $fte)) ?></td><td><?= e($row['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <footer><p>Нагрузка на ФОТ: <?= $money($period['payroll_burden_pct']) ?>%. Накладные: <?= $money($period['overhead_pct']) ?>%.</p><p>Сформировано <?= e(date('d.m.Y H:i')) ?></p></footer>
</article>
