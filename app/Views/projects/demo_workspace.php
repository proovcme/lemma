<?php
/** The only input is an allowlisted payload from the authenticated demo route. */
$workspaceAssets = url('/assets/project-workspace');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#285bb5"><title><?= e($project['title']) ?> · Лемма</title><link rel="stylesheet" href="<?= e($workspaceAssets) ?>/style.css?v=20261025"><link rel="stylesheet" href="<?= asset('lemma/design.css') ?>"></head>
<body class="lemma-app workspace-modern" data-lemma-user="<?= (int) $user['id'] ?>">
<a href="#main" class="skip">К содержимому</a>
<div class="demo-bar"><span>Лемма · публичное демо · вымышленные данные</span><a href="<?= url('/login') ?>">Выбрать роль</a></div>
<div class="shell"><aside class="sidebar lemma-sidebar" id="lemma-sidebar"><?php require BASE_PATH . '/app/Views/components/lemma_navigation.php'; ?></aside>
<div class="work-area"><header class="utility"><button class="btn lemma-mobile-only" type="button" data-lemma-launch>Поиск</button><button class="mobile-brand" data-lemma-menu aria-controls="lemma-sidebar" aria-expanded="false" aria-label="Открыть меню">ЛЕММА <span>☰</span></button><div id="breadcrumbs"><a href="<?= url('/my-day') ?>">Мой день</a><span>/</span><span><?= e($project['code']) ?></span></div><a class="btn text" href="<?= url('/projects/' . (int) $project['id'] . '?classic=1') ?>">Полная карточка</a></header>
<main id="main" tabindex="-1"></main></div></div>
<nav class="mobile-nav" aria-label="Личная работа"><a href="<?= url('/my-day') ?>">Сегодня</a><a href="<?= url('/tasks') ?>">Задачи</a><a href="<?= url('/notes') ?>">Заметки</a><a href="<?= url('/time') ?>">Время</a></nav>
<dialog id="modal" aria-labelledby="dialog-title"><div class="dialog-head"><h2 id="dialog-title"></h2><button data-action="close" aria-label="Закрыть окно">×</button></div><div id="dialog-body"></div></dialog><div id="toast" class="toast" role="status"></div>
<script type="application/json" id="workspace-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e($workspaceAssets) ?>/xlsx-reader.js?v=20261025"></script><script src="<?= e($workspaceAssets) ?>/workspace.js?v=20261025"></script><script src="<?= e($workspaceAssets) ?>/project-data.js?v=20261025"></script>
<?php require BASE_PATH . '/app/Views/components/lemma_tools.php'; ?></body></html>
