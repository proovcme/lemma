<?php
$filters = $filters ?? [];
$status = (string) ($filters['status'] ?? 'active');
$projectId = (string) ($filters['project_id'] ?? '');
$query = (string) ($filters['q'] ?? '');
?>
<section class="notes-screen">
    <form class="filterbar notes-filter" method="get" action="<?= url('/notes') ?>">
        <label>
            <span>Поиск</span>
            <input type="search" name="q" value="<?= e($query) ?>" placeholder="Поиск по заметкам">
        </label>
        <label>
            <span>Статус</span>
            <select name="status">
                <?php foreach (['active' => 'Активные', 'converted' => 'Стали задачами', 'archived' => 'Архив'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= selected($status, $key) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Проект</span>
            <select name="project_id">
                <option value="">Все проекты</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>"<?= selected($projectId, (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline" type="submit">Показать</button>
        <a class="btn btn-outline" href="<?= url('/notes') ?>">Сброс</a>
    </form>

    <?php if (!$notes): ?>
        <div class="empty-state">
            <strong>Заметок пока нет</strong>
            <p>Сохраняйте сюда рабочие мысли, списки и черновики. Когда мысль дозреет, превратите её в задачу.</p>
            <a class="btn btn-red" href="<?= url('/notes/new') ?>">Создать заметку</a>
        </div>
    <?php else: ?>
        <div class="notes-grid">
            <?php foreach ($notes as $note): ?>
                <article class="note-card note-card--<?= e($note['color'] ?: 'yellow') ?>">
                    <a class="note-card__main" href="<?= url('/notes/' . (int) $note['id']) ?>">
                        <div class="note-card__head">
                            <strong><?= e($note['title']) ?></strong>
                            <?php if ((int) ($note['pinned'] ?? 0) === 1): ?><span>Закреплена</span><?php endif; ?>
                        </div>
                        <p><?= e(mb_strimwidth((string) $note['body'], 0, 240, '...')) ?></p>
                    </a>
                    <footer class="note-card__foot">
                        <small>
                            <?= e($note['project_code'] ? $note['project_code'] . ' · ' : '') ?><?= e(format_date(substr((string) ($note['updated_at'] ?? $note['created_at'] ?? ''), 0, 10))) ?>
                        </small>
                        <?php if (($note['status'] ?? '') === 'converted' && (int) ($note['converted_task_id'] ?? 0) > 0): ?>
                            <a class="btn btn-small btn-outline" href="<?= url('/tasks/' . (int) $note['converted_task_id']) ?>">Задача #<?= (int) $note['converted_task_id'] ?></a>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
