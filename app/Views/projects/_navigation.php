<?php
$projectNavActive = (string) ($projectNavActive ?? '');
$projectNavId = (int) ($project['id'] ?? 0);
$projectNavItemClass = static fn (string $key): string => $projectNavActive === $key ? ' class="is-active" aria-current="page"' : '';
?>
<nav class="tabs project-tabs project-workspace-tabs" aria-label="Рабочие разделы проекта">
    <a<?= $projectNavItemClass('summary') ?> href="<?= url('/projects/' . $projectNavId) ?>">Сводка</a>
    <a<?= $projectNavItemClass('tasks') ?> href="<?= url('/projects/' . $projectNavId . '/tasks') ?>">Задачи</a>
    <a<?= $projectNavItemClass('plan') ?> href="<?= url('/projects/' . $projectNavId . '/gantt') ?>">План проекта</a>
    <a<?= $projectNavItemClass('structure') ?> href="<?= url('/projects/' . $projectNavId . '/structure') ?>">Структура и команда</a>
    <a<?= $projectNavItemClass('health') ?> href="<?= url('/projects/' . $projectNavId . '/health-report') ?>">Что у нас плохого</a>
    <a<?= $projectNavItemClass('issues') ?> href="<?= url('/projects/' . $projectNavId . '/issues') ?>">Вопросы</a>
    <a<?= $projectNavItemClass('data') ?> href="<?= url('/projects/' . $projectNavId . '/data') ?>">Исходные данные</a>
    <a<?= $projectNavItemClass('exchange') ?> href="<?= url('/projects/' . $projectNavId . '/exchange') ?>">Обмен заданиями</a>
</nav>
