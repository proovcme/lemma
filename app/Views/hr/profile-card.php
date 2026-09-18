<?php
$statuses = \App\Services\PerformanceReviewService::REVIEW_STATUSES;
$profileReviewMode = (string) ($profileReviewMode ?? 'self');
$managerQueue = $managerQueue ?? ['total' => 0, 'ready' => 0];
?>

<?php if ($profileReviews !== [] || ($profileReviewMode === 'self' && (int) ($managerQueue['total'] ?? 0) > 0)): ?>
<section class="profile-review-card" id="performance-review">
    <div class="profile-review-card__head">
        <div>
            <span><?= $profileReviewMode === 'self' ? 'Личная оценка' : ($profileReviewMode === 'manager' ? 'Руководительская оценка' : 'История HR') ?></span>
            <h2><?= $profileReviewMode === 'self' ? 'Performance Review' : 'Performance Review сотрудника' ?></h2>
        </div>
        <?php if ($profileReviewMode === 'self' && (int) ($managerQueue['total'] ?? 0) > 0): ?>
            <a class="btn<?= (int) ($managerQueue['ready'] ?? 0) > 0 ? ' btn--red' : ' btn-outline' ?>" href="<?= url('/performance-review/manager') ?>">Оценки сотрудников<?= (int) ($managerQueue['ready'] ?? 0) > 0 ? ' · ' . (int) $managerQueue['ready'] : '' ?></a>
        <?php endif; ?>
    </div>

    <?php foreach ($profileReviews as $review): ?>
        <?php
        $isReadyForManager = !empty($review['self_matrix_submitted_at']) && empty($review['manager_matrix_submitted_at']);
        $selfAction = empty($review['self_questionnaire_submitted_at'])
            ? 'Заполнить анкету'
            : (empty($review['self_matrix_submitted_at']) ? 'Оценить компетенции' : 'Открыть ревью');
        ?>
        <article class="profile-review-card__row">
            <div class="profile-review-card__progress" aria-hidden="true">
                <span class="<?= !empty($review['self_questionnaire_submitted_at']) ? 'is-done' : '' ?>"></span><span class="<?= !empty($review['self_matrix_submitted_at']) ? 'is-done' : '' ?>"></span><span class="<?= !empty($review['meeting_completed_at']) ? 'is-done' : '' ?>"></span>
            </div>
            <div>
                <strong><?= e($review['cycle_title']) ?></strong>
                <small><?= e($statuses[$review['status']] ?? $review['status']) ?><?= !empty($review['response_deadline']) ? ' · до ' . e(format_date((string) $review['response_deadline'])) : '' ?></small>
                <?php if ($profileReviewMode === 'manager'): ?><p><?= $isReadyForManager ? 'Самооценка завершена. Можно провести независимую оценку.' : (!empty($review['manager_matrix_submitted_at']) ? 'Ваша оценка отправлена.' : 'Ждём завершения самооценки сотрудника.') ?></p><?php endif; ?>
            </div>
            <?php if ($profileReviewMode === 'self'): ?>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>"><?= e($selfAction) ?></a>
            <?php elseif ($profileReviewMode === 'manager' && $isReadyForManager): ?>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Оценить сотрудника</a>
            <?php elseif ($profileReviewMode === 'hr' || !empty($review['manager_matrix_submitted_at'])): ?>
                <a class="btn btn-outline" href="<?= url('/performance-review/' . (int) $review['id']) ?>">Открыть</a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <?php if ($profileReviews === []): ?><p class="profile-review-card__empty">Личного активного ревью сейчас нет.</p><?php endif; ?>
    <p class="profile-review-card__privacy">Оценки сотрудника и руководителя заполняются независимо. Баллы другой стороны и целевой уровень скрыты до отправки обеих оценок.</p>
</section>
<?php endif; ?>
