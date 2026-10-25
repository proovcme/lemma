<?php
/** The only input is an allowlisted payload from the authenticated demo route. */
$workspaceAssets = url('/assets/project-workspace');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#285bb5"><title><?= e($project['title']) ?> · Лемма</title><link rel="stylesheet" href="<?= e($workspaceAssets) ?>/style.css?v=20261025"></head>
<body>
<a href="#main" class="skip">К содержимому</a>
<div class="demo-bar"><span>Лемма · публичное демо · вымышленные данные</span><a href="<?= url('/login') ?>">Выбрать роль</a></div>
<div class="shell"><aside class="sidebar"><a class="brand" href="<?= url('/my-day') ?>">ЛЕММА<span>Рабочая среда</span></a>
<a class="search-open" href="<?= url('/search') ?>">Поиск в Лемме</a>
<nav aria-label="Основная навигация"><a href="<?= url('/my-day') ?>">Мой день</a><a href="<?= url('/tasks') ?>">Мои задачи</a><a href="<?= url('/notes') ?>">Заметки</a><a href="<?= url('/time') ?>">Время</a><?php if ($payload['canOpenProjects']): ?><a href="<?= url('/projects') ?>">Все проекты</a><?php endif; ?><div class="nav-label">ПРОЕКТ</div><a class="active" href="#project"><?= e($project['code']) ?><small><?= e($project['title']) ?></small></a></nav>
<div class="profile"><span class="avatar" aria-hidden="true"><?= e(mb_substr($user['name'], 0, 1)) ?></span><div><strong><?= e($user['name']) ?></strong><small>Демонстрационный доступ</small></div></div></aside>
<div class="work-area"><header class="utility"><button class="mobile-brand" data-action="menu" aria-label="Открыть меню">ЛЕММА <span>☰</span></button><div id="breadcrumbs"><a href="<?= url('/my-day') ?>">Мой день</a><span>/</span><span><?= e($project['code']) ?></span></div><a class="btn text" href="<?= url('/projects/' . (int) $project['id'] . '?classic=1') ?>">Полная карточка</a><form method="post" action="<?= url('/logout') ?>"><?= csrf_field() ?><button class="btn text" type="submit">Выйти</button></form></header>
<main id="main" tabindex="-1"></main></div></div>
<nav class="mobile-nav" aria-label="Личная работа"><a href="<?= url('/my-day') ?>">Сегодня</a><a href="<?= url('/tasks') ?>">Задачи</a><a href="<?= url('/notes') ?>">Заметки</a><a href="<?= url('/time') ?>">Время</a></nav>
<dialog id="modal" aria-labelledby="dialog-title"><div class="dialog-head"><h2 id="dialog-title"></h2><button data-action="close" aria-label="Закрыть окно">×</button></div><div id="dialog-body"></div></dialog><div id="toast" class="toast" role="status"></div>
<script type="application/json" id="workspace-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e($workspaceAssets) ?>/xlsx-reader.js?v=20261025"></script><script src="<?= e($workspaceAssets) ?>/workspace.js?v=20261025"></script><script src="<?= e($workspaceAssets) ?>/project-data.js?v=20261025"></script>
</body></html>
