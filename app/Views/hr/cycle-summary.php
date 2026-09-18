<?php
$summary = $summary ?? ['cycle' => [], 'metrics' => [], 'participants' => [], 'competencies' => []];
$cycle = (array) $summary['cycle'];
$metrics = (array) $summary['metrics'];
$number = static fn (mixed $value): string => $value === null ? '—' : number_format((float) $value, 2, ',', ' ');
$deviationClass = static function (mixed $value): string {
    if (!is_numeric($value)) return '';
    $absolute = abs((float) $value);
    return $absolute >= 2 ? ' review-deviation--high' : ($absolute >= 1 ? ' review-deviation--medium' : ' review-deviation--aligned');
};
$targetGapClass = static function (mixed $value): string {
    if (!is_numeric($value)) return '';
    if ((float) $value >= 0) return ' review-deviation--aligned';
    return (float) $value <= -2 ? ' review-deviation--high' : ' review-deviation--medium';
};
?>

<section class="project-head project-head--tab performance-review-head">
    <div><span class="muted">HR / Performance Review / сводка</span><h2><?= e($cycle['title'] ?? '') ?></h2><small><?= e(\App\Services\PerformanceReviewService::CYCLE_KINDS[$cycle['cycle_kind'] ?? 'annual'] ?? '') ?> · <?= e((string) ($cycle['review_year'] ?? '')) ?></small></div>
    <div class="toolbar__actions project-tab-actions"><a class="btn" href="<?= url('/hr') ?>">Рабочий стол</a><a class="btn btn--red" href="<?= url('/hr/cycles/' . (int) ($cycle['id'] ?? 0) . '/export') ?>">Экспорт XLSX</a></div>
</section>

<section class="metric-row project-summary-metrics review-summary-metrics">
    <div class="metric"><span><?= (int) ($metrics['total'] ?? 0) ?></span><strong>Участников</strong></div>
    <div class="metric"><span><?= (int) ($metrics['launched'] ?? 0) ?></span><strong>Запущено</strong></div>
    <div class="metric"><span><?= (int) ($metrics['self_done'] ?? 0) ?></span><strong>Самооценок</strong></div>
    <div class="metric"><span><?= (int) ($metrics['manager_done'] ?? 0) ?></span><strong>Оценок руководителя</strong></div>
    <div class="metric"><span><?= (int) ($metrics['paired'] ?? 0) ?></span><strong>Пар для сравнения</strong></div>
    <div class="metric"><span><?= (int) ($metrics['closed'] ?? 0) ?></span><strong>Закрыто</strong></div>
</section>

<section class="panel sheet-panel">
    <div class="panel__head"><div><h2>Участники</h2><span class="muted">Результаты видны только там, где обе независимые оценки завершены.</span></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Сотрудник</th><th>Грейд</th><th>Статус</th><th>Самооценка</th><th>Руководитель</th><th>Цель</th><th>Расхождение</th><th>От цели</th><th data-no-column-filter>Результаты</th></tr></thead><tbody>
    <?php foreach ((array) $summary['participants'] as $row): $avg = (array) ($row['averages'] ?? []); ?>
        <tr><td><strong><?= e($row['employee_name'] ?? '') ?></strong><small><?= e($row['position_title_snapshot'] ?? 'должность не указана') ?> · <?= e($row['employee_department'] ?? 'без отдела') ?></small></td><td><?= e(($row['position_grade_snapshot'] ?? '') ?: '—') ?></td><td><?= e(\App\Services\PerformanceReviewService::REVIEW_STATUSES[$row['status'] ?? ''] ?? ($row['status'] ?? '')) ?></td><td><?= e($number($avg['self'] ?? null)) ?></td><td><?= e($number($avg['manager'] ?? null)) ?></td><td><?= e($number($avg['target'] ?? null)) ?></td><td><span class="review-deviation <?= e($deviationClass($avg['delta'] ?? null)) ?>"><?= e($number($avg['delta'] ?? null)) ?></span></td><td><span class="review-deviation <?= e($targetGapClass($avg['target_gap'] ?? null)) ?>"><?= e($number($avg['target_gap'] ?? null)) ?></span></td><td><a class="btn btn-sm btn-outline" href="<?= url('/performance-review/' . (int) ($row['id'] ?? 0)) ?>">Открыть карточку</a></td></tr>
    <?php endforeach; ?>
    <?php if (empty($summary['participants'])): ?><tr><td colspan="9" class="muted">Участников нет.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="panel sheet-panel">
    <div class="panel__head"><div><h2>Компетенции</h2><span class="muted">Сводные значения только по завершённым парам оценок.</span></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Компетенция</th><th>Пар</th><th>Самооценка</th><th>Руководитель</th><th>Цель</th><th>Расхождение</th><th>Ниже цели</th></tr></thead><tbody>
    <?php foreach ((array) $summary['competencies'] as $row): ?>
        <tr><td><strong><?= e($row['name'] ?? '') ?></strong></td><td><?= (int) ($row['paired_count'] ?? 0) ?></td><td><?= e($number($row['avg_self'] ?? null)) ?></td><td><?= e($number($row['avg_manager'] ?? null)) ?></td><td><?= e($number($row['avg_target'] ?? null)) ?></td><td><span class="review-deviation <?= e($deviationClass($row['delta'] ?? null)) ?>"><?= e($number($row['delta'] ?? null)) ?></span></td><td><?= (int) ($row['below_target'] ?? 0) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($summary['competencies'])): ?><tr><td colspan="7" class="muted">Сводные результаты появятся после завершения обеих оценок хотя бы у одного участника.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
