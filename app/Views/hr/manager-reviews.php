<?php
$statuses = \App\Services\PerformanceReviewService::REVIEW_STATUSES;
$ready = array_values(array_filter($reviews, static fn (array $review): bool => ($review['manager_state'] ?? '') === 'ready'));
$meetingReady = array_values(array_filter($reviews, static fn (array $review): bool => ($review['manager_state'] ?? '') === 'meeting_ready'));
$waiting = array_values(array_filter($reviews, static fn (array $review): bool => ($review['manager_state'] ?? '') === 'waiting'));
$done = array_values(array_filter($reviews, static fn (array $review): bool => ($review['manager_state'] ?? '') === 'done'));
$actionCount = count($ready) + count($meetingReady);
?>

<section class="project-head project-head--tab performance-review-head">
    <div>
        <span class="muted">Личная очередь руководителя</span>
        <h2>Моя очередь</h2>
        <p>Здесь только сотрудники, для которых вы назначены оценивающим руководителем.</p>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/profile#performance-review') ?>">Мой профиль</a>
        <a class="btn" href="<?= url('/performance-review/manager/export') ?>">Выгрузить сводный XLSX</a>
    </div>
</section>

<section class="review-inbox-summary" aria-label="Сводка очереди оценок">
    <article class="review-inbox-summary__focus"><span>Требуют действия</span><strong><?= $actionCount ?></strong><small>Независимая оценка или фиксация итоговой встречи.</small></article>
    <article><span>Ожидают сотрудника</span><strong><?= count($waiting) ?></strong><small>У вас пока нет действия.</small></article>
    <article><span>Завершены</span><strong><?= count($done) ?></strong><small>Итоги встречи зафиксированы.</small></article>
</section>

<section class="panel review-inbox">
    <div class="panel__head"><div><h2>Требуют вашего действия</h2><span class="muted">Оцените сотрудника, затем зафиксируйте итоги встречи</span></div><span class="pill"><?= $actionCount ?></span></div>
    <div class="review-inbox-list">
        <?php foreach ($ready as $review): ?>
            <article class="review-inbox-card review-inbox-card--ready">
                <div><span class="review-inbox-state">Можно оценивать</span><h3><?= e($review['employee_name']) ?></h3><p><?= e($review['employee_department'] ?: 'без отдела') ?> · <?= e($review['cycle_title']) ?></p></div>
                <div class="review-inbox-card__meta"><span>Срок</span><strong><?= e(!empty($review['response_deadline']) ? format_date((string) $review['response_deadline']) : 'не указан') ?></strong></div>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Оценить сотрудника</a>
            </article>
        <?php endforeach; ?>
        <?php foreach ($meetingReady as $review): ?>
            <article class="review-inbox-card review-inbox-card--ready">
                <div><span class="review-inbox-state">Нужно завершить</span><h3><?= e($review['employee_name']) ?></h3><p><?= e($review['employee_department'] ?: 'без отдела') ?> · <?= e($review['cycle_title']) ?></p></div>
                <div class="review-inbox-card__meta"><span>Этап</span><strong>Итоговая встреча</strong></div>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Зафиксировать итоги</a>
            </article>
        <?php endforeach; ?>
        <?php if ($ready === [] && $meetingReady === []): ?><p class="review-inbox-empty">Сейчас нет ревью, требующих вашего действия.</p><?php endif; ?>
    </div>
</section>

<?php if ($waiting !== []): ?>
<details class="panel review-inbox-fold">
    <summary><strong>Ожидают сотрудника</strong><span><?= count($waiting) ?></span></summary>
    <div class="review-inbox-list">
        <?php foreach ($waiting as $review): ?>
            <article class="review-inbox-card">
                <div><span class="review-inbox-state review-inbox-state--waiting">Действий пока нет</span><h3><?= e($review['employee_name']) ?></h3><p><?= e($review['cycle_title']) ?></p></div>
                <a class="btn btn-outline" href="<?= url('/profiles/' . (int) $review['user_id'] . '#performance-review') ?>">Профиль сотрудника</a>
            </article>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<?php if ($done !== []): ?>
<details class="panel review-inbox-fold">
    <summary><strong>Завершённые ревью</strong><span><?= count($done) ?></span></summary>
    <div class="review-inbox-list">
        <?php foreach ($done as $review): ?>
            <article class="review-inbox-card">
                <div><span class="review-inbox-state review-inbox-state--done">Ревью завершено</span><h3><?= e($review['employee_name']) ?></h3><p><?= e($statuses[$review['status']] ?? $review['status']) ?> · <?= e($review['cycle_title']) ?></p></div>
                <a class="btn btn-outline" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Открыть</a>
            </article>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<section class="panel sheet-panel">
    <div class="panel__head">
        <div><h2>Все мои оценки</h2><span class="muted">Текущие и завершённые ревью, где вы назначены руководителем.</span></div>
        <span><?= (int) ($report['metrics']['total'] ?? 0) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Сотрудник</th><th>Отдел</th><th>Цикл</th><th>Статус</th><th>Состояние</th><th></th></tr></thead>
            <tbody>
            <?php foreach ((array) ($report['reviews'] ?? []) as $review): ?>
                <tr>
                    <td><strong><?= e($review['employee_name'] ?? '') ?></strong></td>
                    <td><?= e(($review['employee_department'] ?? '') ?: '—') ?></td>
                    <td><?= e($review['cycle_title'] ?? '') ?><small><?= e(format_date((string) ($review['period_start'] ?? ''))) ?> — <?= e(format_date((string) ($review['period_end'] ?? ''))) ?></small></td>
                    <td><?= e($statuses[$review['status'] ?? ''] ?? ($review['status'] ?? '')) ?></td>
                    <td><?= e(['waiting' => 'Ожидает сотрудника', 'ready' => 'Можно оценивать', 'meeting_ready' => 'Нужна итоговая встреча', 'done' => 'Завершено'][$review['manager_state'] ?? 'waiting']) ?></td>
                    <td><a class="btn btn-sm btn-outline" href="<?= url('/performance-review/' . (int) ($review['id'] ?? 0)) ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($report['reviews'])): ?><tr><td colspan="6" class="muted">Вам ещё не назначены Performance Review.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
