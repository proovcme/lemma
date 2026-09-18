<?php
$cycle = $cycle ?? [];
$participants = (array) ($cycle['participants'] ?? []);
$pending = (array) ($cycle['pending_participants'] ?? []);
$batches = (array) ($cycle['batches'] ?? []);
$cycleStatus = (string) ($cycle['status'] ?? 'draft');
$kindLabel = \App\Services\PerformanceReviewService::CYCLE_KINDS[$cycle['cycle_kind'] ?? 'annual'] ?? 'Официальный';
$statusLabel = \App\Services\PerformanceReviewService::CYCLE_STATUSES[$cycleStatus] ?? $cycleStatus;
$reviewStatuses = \App\Services\PerformanceReviewService::REVIEW_STATUSES;
$launchParticipants = array_map(static fn (array $participant): array => [
    'id' => (int) $participant['user_id'],
    'primary' => (string) $participant['name'],
    'secondary' => (string) ($participant['email'] ?? ''),
    'cells' => [
        'department' => (string) ($participant['department'] ?: '—'),
        'manager' => (string) ($participant['manager_name'] ?: 'не назначен'),
    ],
], $pending);
?>

<section class="project-head project-head--tab performance-review-head">
    <div>
        <span class="muted">HR / Performance Review</span>
        <h2><?= e($cycle['title'] ?? '') ?></h2>
        <div class="review-cycle-badges">
            <span class="review-cycle-badge <?= ($cycle['cycle_kind'] ?? 'annual') === 'test' ? 'is-test' : 'is-official' ?>"><?= e($kindLabel) ?></span>
            <span class="review-cycle-badge is-<?= e($cycleStatus) ?>"><?= e($statusLabel) ?></span>
            <span class="review-cycle-badge"><?= (int) ($cycle['review_year'] ?? 0) ?> год</span>
        </div>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/hr') ?>">Все циклы</a>
        <?php if ($cycleStatus === 'draft'): ?><a class="btn btn-outline" href="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/edit') ?>">Редактировать черновик</a><?php endif; ?>
        <a class="btn btn-outline" href="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/summary') ?>">Сводка</a>
    </div>
</section>

<section class="review-cycle-steps" aria-label="Этапы запуска">
    <article class="is-done"><span>1</span><div><strong>Черновик сохранён</strong><small><?= count($participants) ?> участников выбрано</small></div></article>
    <article class="<?= $cycleStatus === 'draft' ? 'is-current' : 'is-done' ?>"><span>2</span><div><strong>Состав проверен</strong><small><?= $pending ? 'Можно выбрать первую партию' : 'Все выбранные участники запущены' ?></small></div></article>
    <article class="<?= $cycleStatus === 'active' ? 'is-current' : ($cycleStatus === 'closed' ? 'is-done' : '') ?>"><span>3</span><div><strong>Запуск партиями</strong><small><?= count($batches) ?> <?= count($batches) === 1 ? 'партия' : 'партий' ?> уже запущено</small></div></article>
</section>

<?php if (in_array($cycleStatus, ['draft', 'active'], true) && $pending !== []): ?>
    <section class="panel sheet-panel review-cycle-launch">
        <div class="panel__head">
            <div>
                <h2><?= $batches === [] ? 'Запустить первую партию' : 'Запустить следующую партию' ?></h2>
                <span class="muted">Уведомление и большая карточка в «Моём дне» появятся только у выбранных сейчас сотрудников.</span>
            </div>
            <span class="review-cycle-badge is-draft">Ожидают запуска: <?= count($pending) ?></span>
        </div>
        <form class="form-stack" method="post" action="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/open') ?>" onsubmit="return confirm('Запустить выбранную партию? Участники увидят Performance Review в «Моём дне».')">
            <?= csrf_field() ?>
            <?php view('components/bulk_checklist', [
                'items' => $launchParticipants,
                'columns' => ['department' => 'Отдел', 'manager' => 'Руководитель'],
                'searchPlaceholder' => 'Найти по имени, отделу или руководителю',
                'checkboxAriaPrefix' => 'Запустить для',
            ], ''); ?>
            <div class="form-actions"><button class="btn btn--red" type="submit">Запустить выбранную партию</button></div>
        </form>
    </section>
<?php elseif ($cycleStatus === 'draft'): ?>
    <section class="panel review-cycle-empty"><h2>В черновике нет участников</h2><p>Вернитесь к редактированию и добавьте хотя бы одного сотрудника.</p></section>
<?php endif; ?>

<section class="panel sheet-panel">
    <div class="panel__head"><div><h2>История партий</h2><span class="muted">Уже запущенные ревью не меняются при запуске следующей группы.</span></div><span><?= count($batches) ?></span></div>
    <?php if ($batches): ?>
        <div class="review-batch-list">
            <?php foreach ($batches as $batch): ?>
                <details class="review-batch"<?= (int) $batch['number'] === count($batches) ? ' open' : '' ?>>
                    <summary><strong>Партия №<?= (int) $batch['number'] ?></strong><span><?= e(format_date((string) ($batch['launched_at'] ?? ''))) ?> · <?= count($batch['participants'] ?? []) ?> участников</span></summary>
                    <div class="table-wrap"><table class="data-table data-table--compact" data-no-column-filters><thead><tr><th>Сотрудник</th><th>Отдел</th><th>Руководитель</th><th>Состояние</th><th></th></tr></thead><tbody>
                    <?php foreach (($batch['participants'] ?? []) as $participant): ?><tr><td><strong><?= e($participant['name']) ?></strong></td><td><?= e($participant['department'] ?: '—') ?></td><td><?= e($participant['manager_name'] ?: 'не назначен') ?></td><td><?= e($reviewStatuses[$participant['status']] ?? $participant['status']) ?></td><td><a class="btn btn-outline btn-sm" href="<?= url('/performance-review/' . (int) $participant['id']) ?>">Открыть</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php else: ?><p class="review-cycle-empty">Пока не запущено ни одной партии. Черновик виден только HR и директору.</p><?php endif; ?>
</section>

<?php if (($cycle['cycle_kind'] ?? 'annual') === 'test' || in_array($cycleStatus, ['active', 'closed'], true)): ?>
    <section class="panel review-cycle-control">
        <div class="panel__head">
            <div><h2>Управление циклом</h2><span class="muted">Тип цикла и доступ участников меняются без удаления ответов и результатов.</span></div>
        </div>
        <div class="review-cycle-control__actions">
            <?php if (($cycle['cycle_kind'] ?? 'annual') === 'test'): ?>
                <form method="post" action="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/make-official') ?>" onsubmit="return confirm('Сделать этот тестовый цикл официальным за <?= (int) ($cycle['review_year'] ?? 0) ?> год? Отменить действие можно только через администратора.')">
                    <?= csrf_field() ?>
                    <strong>Сделать официальным</strong>
                    <span>Состав, ответы, оценки и история партий сохранятся.</span>
                    <button class="btn btn--red" type="submit">Перевести в официальный</button>
                </form>
            <?php endif; ?>
            <?php if ($cycleStatus === 'active'): ?>
                <form method="post" action="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/close') ?>" onsubmit="return confirm('Закрыть заполнение? Участники временно потеряют доступ, но все введённые данные сохранятся.')">
                    <?= csrf_field() ?>
                    <strong>Заполнение открыто</strong>
                    <span>Участники и руководители могут продолжать работу.</span>
                    <button class="btn btn-outline" type="submit">Закрыть заполнение</button>
                </form>
            <?php elseif ($cycleStatus === 'closed'): ?>
                <form method="post" action="<?= url('/hr/cycles/' . (int) $cycle['id'] . '/reopen') ?>">
                    <?= csrf_field() ?>
                    <strong>Заполнение закрыто</strong>
                    <span>Ответы сохранены. После открытия участники продолжат с прежнего места.</span>
                    <button class="btn btn--red" type="submit">Открыть заполнение</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
