<section class="toolbar">
    <div class="toolbar__title"><?= !empty($isArchive) ? 'Архив проектов' : 'Проекты' ?></div>
    <?php if ($canCreate): ?>
        <?php if (!empty($isArchive)): ?>
            <a class="btn btn-outline" href="<?= url('/projects') ?>">Активные проекты</a>
        <?php else: ?>
            <a class="btn btn-outline" href="<?= url('/projects/archived') ?>">Архив проектов</a>
            <a class="btn btn--red" href="<?= url('/projects/new') ?>">+ Проект</a>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="project-grid">
    <?php foreach ($projects as $project): ?>
        <?php
        $total = (int) ($project['tasks_total'] ?? 0);
        $done = (int) ($project['tasks_done'] ?? 0);
        $percent = $total > 0 ? (int) round($done * 100 / $total) : 0;
        $deadlineClass = deadline_state_class($project['nearest_deadline'] ?? null);
        $deadlineDisplay = (string) ($project['nearest_deadline'] ?? '') !== '' ? format_date($project['nearest_deadline']) : '—';
        ?>
        <a class="project-card" href="<?= url('/projects/' . $project['id']) ?>" style="--project-color: <?= e($project['color'] ?: '#cc1f1f') ?>">
            <div class="project-card__top">
                <strong><?= e($project['code']) ?></strong>
                <span><?= !empty($isArchive) ? 'Архив' : e($project['stage']) ?></span>
            </div>
            <h2><?= e($project['title']) ?></h2>
            <p><?= e($project['object']) ?></p>
            <?php if ($percent > 0): ?>
                <div class="progress"><span class="prog-fill <?= e(progress_fill_class($percent)) ?>" style="width: <?= $percent ?>%"></span></div>
            <?php else: ?>
                <div class="progress-placeholder">—</div>
            <?php endif; ?>
            <div class="project-card__meta">
                <span><?= $done ?>/<?= $total ?> закрыто</span>
                <span>Блоки: <?= (int) ($project['tasks_blocked'] ?? 0) ?></span>
                <?php if (!empty($isArchive)): ?>
                    <span><?= e(format_date($project['archived_at'] ?? '') ?: 'дата архива не указана') ?></span>
                <?php else: ?>
                    <span class="<?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></span>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
    <?php if (!$projects): ?>
        <div class="empty-state">
            <span class="empty-state__icon">—</span>
            <strong><?= !empty($isArchive) ? 'Архив пуст' : 'Проектов пока нет' ?></strong>
            <span><?= !empty($isArchive) ? 'Архивные проекты появятся здесь после перевода в архив.' : 'Создайте первый проект для работы.' ?></span>
        </div>
    <?php endif; ?>
</section>
