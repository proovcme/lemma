<?php
$dashboard = $dashboard ?? ['counts' => [], 'reviews' => [], 'cycles' => []];
$cycles = (array) ($dashboard['cycles'] ?? []);
$cycleKinds = \App\Services\PerformanceReviewService::CYCLE_KINDS;
$cycleStatuses = \App\Services\PerformanceReviewService::CYCLE_STATUSES;
?>

<section class="project-head project-head--tab performance-review-head">
    <div>
        <span class="muted">HR</span>
        <h2>Performance Review</h2>
        <p class="muted">Черновики, действующие циклы, партии запуска и завершённая история — в одном месте.</p>
    </div>
    <?php if ($canManage): ?>
        <div class="toolbar__actions project-tab-actions">
            <a class="btn btn-outline" href="<?= url('/hr/templates') ?>">Настройки анкеты</a>
            <a class="btn btn--red" href="<?= url('/hr/cycles/new') ?>">Начать Performance Review</a>
        </div>
    <?php endif; ?>
</section>

<section class="review-cycle-guide" aria-label="Как проходит запуск">
    <article><span>1</span><div><strong>Создайте черновик</strong><small>Выберите участников и при необходимости отметьте тестовый прогон.</small></div></article>
    <article><span>2</span><div><strong>Проверьте состав</strong><small>Черновик остаётся в этом списке и доступен для редактирования.</small></div></article>
    <article><span>3</span><div><strong>Запускайте партиями</strong><small>Следующую группу можно открыть позже, не меняя уже начатые ревью.</small></div></article>
</section>

<section class="panel sheet-panel review-cycle-register">
    <div class="panel__head">
        <div><h2>Все циклы</h2><span class="muted">Тестовые и официальные циклы хранятся вместе, но всегда явно помечены.</span></div>
        <span><?= count($cycles) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table review-cycle-table">
            <thead><tr><th>Цикл</th><th>Тип</th><th>Состояние</th><th>Участники</th><th>Партии</th><th>Период</th><th>Следующий шаг</th></tr></thead>
            <tbody>
            <?php foreach ($cycles as $cycle): ?>
                <?php
                $status = (string) ($cycle['status'] ?? 'draft');
                $kind = (string) ($cycle['cycle_kind'] ?? 'annual');
                $pendingCount = (int) ($cycle['pending_count'] ?? 0);
                $reviewCount = (int) ($cycle['review_count'] ?? 0);
                $launchedCount = (int) ($cycle['launched_count'] ?? 0);
                $actionLabel = match ($status) {
                    'draft' => 'Продолжить настройку',
                    'active' => $pendingCount > 0 ? 'Запустить партию' : 'Открыть цикл',
                    default => 'Открыть результаты',
                };
                ?>
                <tr>
                    <td><strong><?= e($cycle['title']) ?></strong><small><?= (int) ($cycle['review_year'] ?? 0) ?> год<?= !empty($cycle['response_deadline']) ? ' · заполнить до ' . e(format_date((string) $cycle['response_deadline'])) : '' ?></small></td>
                    <td><span class="review-cycle-badge <?= $kind === 'test' ? 'is-test' : 'is-official' ?>"><?= e($cycleKinds[$kind] ?? $kind) ?></span></td>
                    <td><span class="review-cycle-badge is-<?= e($status) ?>"><?= e($cycleStatuses[$status] ?? $status) ?></span></td>
                    <td><strong><?= $launchedCount ?> / <?= $reviewCount ?></strong><small><?= $pendingCount > 0 ? 'ожидают запуска: ' . $pendingCount : 'все запущены' ?></small></td>
                    <td><?= (int) ($cycle['batch_count'] ?? 0) ?></td>
                    <td><?= e(format_date((string) ($cycle['period_start'] ?? ''))) ?> — <?= e(format_date((string) ($cycle['period_end'] ?? ''))) ?></td>
                    <td><a class="btn <?= $status === 'draft' || ($status === 'active' && $pendingCount > 0) ? 'btn--red' : 'btn-outline' ?> btn-sm" href="<?= url('/hr/cycles/' . (int) $cycle['id']) ?>"><?= e($actionLabel) ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cycles): ?><tr><td colspan="7" class="muted">Циклов пока нет. Нажмите «Начать Performance Review», чтобы создать первый черновик.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
