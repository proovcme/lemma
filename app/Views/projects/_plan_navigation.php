<?php $projectPlanActive = (string) ($projectPlanActive ?? 'gantt'); ?>
<nav class="tabs project-plan-tabs" aria-label="Представления плана проекта">
    <span class="project-plan-tabs__label">План проекта</span>
    <a class="<?= $projectPlanActive === 'gantt' ? 'is-active' : '' ?>"<?= $projectPlanActive === 'gantt' ? ' aria-current="page"' : '' ?> href="<?= url('/projects/' . (int) $project['id'] . '/gantt') ?>">Гант</a>
    <a class="<?= $projectPlanActive === 'schedule' ? 'is-active' : '' ?>"<?= $projectPlanActive === 'schedule' ? ' aria-current="page"' : '' ?> href="<?= url('/projects/' . (int) $project['id'] . '/schedule') ?>">График РД</a>
    <a class="<?= $projectPlanActive === 'sections' ? 'is-active' : '' ?>"<?= $projectPlanActive === 'sections' ? ' aria-current="page"' : '' ?> href="<?= url('/projects/' . (int) $project['id'] . '/sections') ?>">Разделы</a>
</nav>
