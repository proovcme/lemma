<?php $statuses = \App\Services\PerformanceReviewService::REVIEW_STATUSES; ?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Ежегодная оценка</span>
        <h2>Performance Review</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <?php if ($canManage): ?><a class="btn" href="<?= url('/hr') ?>">Управление циклами</a><?php endif; ?>
        <?php if ($canManage): ?><a class="btn btn-outline" href="<?= url('/hr/templates') ?>">Шаблоны</a><?php endif; ?>
    </div>
</section>

<section class="panel">
    <div class="panel__head"><h2>Три этапа</h2></div>
    <ol class="review-steps review-steps--intro">
        <li><strong>Анкета самооценки</strong><span>Ключевые результаты, трудности и зоны роста.</span></li>
        <li><strong>Матрица компетенций</strong><span>Оценка сотрудника и независимая оценка руководителя по шкале 1–5.</span></li>
        <li><strong>Итоговая встреча</strong><span>Назначенный руководитель фиксирует результаты и шаги сотрудника на следующий год.</span></li>
    </ol>
</section>

<section class="panel sheet-panel">
    <div class="panel__head">
        <h2>Performance review</h2>
        <span class="muted"><?= count($reviews) ?> строк</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Сотрудник</th><th>Отдел</th><th>Цикл</th><th>Период</th><th>Руководитель</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reviews as $review): ?>
                <tr>
                    <td><strong><?= e($review['employee_name']) ?></strong></td>
                    <td><?= e($review['employee_department'] ?: '—') ?></td>
                    <td><?= e($review['cycle_title']) ?></td>
                    <td><?= e(format_date((string) ($review['period_start'] ?? ''))) ?> — <?= e(format_date((string) ($review['period_end'] ?? ''))) ?></td>
                    <td><?= e($review['manager_name'] ?: 'не назначен') ?></td>
                    <td><?= e($statuses[$review['status']] ?? $review['status']) ?></td>
                    <td><a class="btn btn-sm" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reviews): ?><tr><td colspan="7" class="muted">Ревью пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
