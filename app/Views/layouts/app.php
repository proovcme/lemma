<?php
$user = current_user();
$userRole = (string) ($user['role'] ?? '');
$homePath = role_home_path($user);
$currentPath = request_path();
$adminSettingsActive = active_link_any(['/admin/access', '/admin/dictionaries', '/admin/counterparties', '/admin/exchange-templates', '/admin/fields', '/admin/integrations', '/admin/database-export', '/admin/notifications', '/admin/password-gen']) !== '';
$canOpenProjects = $user ? \App\Services\PermissionService::canOpenProjects($user) : false;
$canOpenDpr = $user ? \App\Services\PermissionService::canOpenDpr($user) : false;
$canOpenReports = $user ? \App\Services\PermissionService::canOpenReports($user) : false;
$canBrowseProfiles = $user ? \App\Services\PermissionService::canBrowseEmployeeProfiles($user) : false;
$canOpenIntegrations = $user ? \App\Services\PermissionService::canOpenIntegrations($user) : false;
$canManageUsers = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canManageUsers($user) : false;
$canOpenCompetency = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canOpenCompetency($user) : false;
$canManageSettings = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canManageSettings($user) : false;
$canManagePayroll = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canManagePayroll($user) : false;
$canManageDepartmentBudget = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canManageDepartmentBudget($user) : false;
$canOpenHr = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canOpenHr($user) : false;
$canManagePerformanceReviews = $user && !app_is_demo_mode() ? \App\Services\PermissionService::canManagePerformanceReviews($user) : false;
$canReviewTime = $user ? \App\Services\PermissionService::canReviewTime($user) : false;
$canOpenCalculator = is_file((string) config('app.calculator_bundle_path') . '/index.html') && $user ? \App\Controllers\CalculatorController::canAccessRole($user['role'] ?? null) : false;
$isEngineer = $user && \App\Services\RoleService::isAny($user['role'] ?? null, [\App\Services\RoleService::ENGINEER]);
$canCreateGlobalTask = $user && !$isEngineer && \App\Services\PermissionService::canCreateTasks($user);
if (!empty($headerActions) || preg_match('#^/(tasks|projects)(/|$)#', $currentPath)) $canCreateGlobalTask = false;
$isDrawerPage = ($_GET['drawer'] ?? '') === '1';
$globalSearchQuery = $currentPath === '/search' ? trim((string) ($_GET['q'] ?? '')) : '';
$globalTaskHref = '/tasks/new';
$contextHelpHref = '/manual';
if (preg_match('#^/projects/(\d+)(?:/|$)#', $currentPath, $projectPathMatch)) {
    $globalTaskHref .= '?project_id=' . (int) $projectPathMatch[1];
    $contextHelpHref = '/projects/' . (int) $projectPathMatch[1] . '/assistant';
}
$bodyClasses = [];
if ($isDrawerPage) {
    $bodyClasses[] = 'is-drawer-page';
}
if (in_array($currentPath, ['/locia', '/work', '/tasks', '/tasks/all'], true)) {
    $bodyClasses[] = 'is-task-hub-page';
}
$bodyClasses[] = 'is-demo-theme lemma-app';
$layoutData = \App\Services\LayoutService::getLayoutData();
$notificationCount = $layoutData['notificationCount'];
$reviewCount = $layoutData['reviewCount'];
$performanceReviewAvailable = (bool) ($layoutData['performanceReviewAvailable'] ?? false);
$managerReviewCount = (int) ($layoutData['managerReviewCount'] ?? 0);
$managerReviewReadyCount = (int) ($layoutData['managerReviewReadyCount'] ?? 0);
$sidebarProjects = $layoutData['sidebarProjects'];
$versionInfo = app_version_info();
?>
<!doctype html>
<html lang="ru-RU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="<?= e(app_theme_color()) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Лемма">
    <title><?= e($title ?? app_title_default()) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= url('/favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= url('/pwa/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= url('/manifest.webmanifest') ?>">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="stylesheet" href="<?= asset('lemma/design.css') ?>"><link rel="stylesheet" href="<?= asset('lemma/inputs.css') ?>"></head>
<body class="<?= e(implode(' ', $bodyClasses)) ?>" data-unread-notifications="<?= $notificationCount ?>" data-lemma-user="<?= (int) $user['id'] ?>">
<a class="skip-link" href="#main-content">К содержимому</a>
<div class="lemma-mobile-head"><strong>лемма</strong><button class="btn" type="button" data-lemma-menu aria-controls="lemma-sidebar" aria-expanded="false">Меню</button><button class="btn" type="button" data-lemma-launch>Поиск</button></div><div class="app-shell">
    <aside class="sidebar lemma-sidebar" id="lemma-sidebar"><?php require BASE_PATH . '/app/Views/components/lemma_navigation.php'; ?></aside>

    <main class="main" id="main-content">
        <header class="topbar">
            <div class="topbar__title">
                <h1><?= e($title ?? '') ?></h1>
                <?php if (!empty($subtitle)): ?>
                    <p class="topbar__subtitle"><?= e($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if (app_is_demo_mode() || $canCreateGlobalTask || !empty($headerActions) || !$isDrawerPage): ?>
                <div class="topbar__actions">
                    <?php if (app_is_demo_mode()): ?>
                        <a class="btn" href="<?= url('/login') ?>">Смена роли</a>
                    <?php endif; ?>
                    <?php if ($canCreateGlobalTask): ?>
                        <a class="btn btn--red topbar-task-button" href="<?= url($globalTaskHref) ?>">+ Задача</a>
                    <?php endif; ?>
                    <?php if (!$isDrawerPage && !app_is_demo_mode()): ?>
                        <a class="btn btn-outline topbar-help-button" href="<?= url($contextHelpHref) ?>">Справка</a>
                    <?php endif; ?>
                    <?php foreach (($headerActions ?? []) as $action): if (($action['form'] ?? '') === 'task-form') continue; ?>
                        <?php if (($action['type'] ?? 'link') === 'button'): ?>
                            <button class="btn <?= e($action['class'] ?? '') ?>" type="<?= e($action['buttonType'] ?? 'button') ?>"<?= !empty($action['form']) ? ' form="' . e($action['form']) . '" data-submit-form="' . e($action['form']) . '"' : '' ?>><?= e($action['label']) ?></button>
                        <?php elseif (($action['type'] ?? 'link') === 'form'): ?>
                            <?php
                            $formAction = (string) ($action['action'] ?? '#');
                            $formAction = preg_match('#^[a-z][a-z0-9+.-]*:#i', $formAction) ? $formAction : url($formAction);
                            ?>
                            <form method="post" action="<?= e($formAction) ?>"<?= !empty($action['confirm']) ? ' onsubmit="return confirm(' . e(json_encode((string) $action['confirm'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) . ')"' : '' ?>>
                                <?= csrf_field() ?>
                                <button class="btn <?= e($action['class'] ?? '') ?>" type="submit"><?= e($action['label']) ?></button>
                            </form>
                        <?php else: ?>
                            <?php
                            $href = (string) ($action['href'] ?? '#');
                            $href = preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) ? $href : url($href);
                            ?>
                            <a class="btn <?= e($action['class'] ?? '') ?>" href="<?= e($href) ?>"<?= !empty($action['target']) ? ' target="' . e($action['target']) . '"' : '' ?><?= !empty($action['rel']) ? ' rel="' . e($action['rel']) . '"' : '' ?><?= !empty($action['confirm']) ? ' onclick="return confirm(' . e(json_encode((string) $action['confirm'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) . ')"' : '' ?>><?= e($action['label']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="topbar__meta">
                <?php if ($reviewCount > 0): ?>
                    <a class="counter" href="<?= url('/tasks') ?>">Проверка: <?= $reviewCount ?></a>
                <?php endif; ?>
                <a class="counter<?= $notificationCount > 0 ? ' counter--hot' : '' ?>" href="<?= url('/notifications') ?>">Уведомления: <?= $notificationCount ?></a>
            </div>
        </header>

        <?php if ($message = flash('success')): ?>
            <div class="alert alert--success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="alert alert--error"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="content">
            <?= $content ?>
        </div>
    </main>
</div>
<?php if (!$isDrawerPage): ?>
    <div class="task-drawer" data-task-drawer aria-hidden="true" inert>
        <div class="task-drawer__backdrop" data-task-drawer-close></div>
        <aside class="task-drawer__panel" role="dialog" aria-modal="true" aria-label="Просмотр задачи">
            <header class="task-drawer__head">
                <div>
                    <span>Задача</span>
                    <strong data-task-drawer-title>Просмотр</strong>
                </div>
                <div class="task-drawer__actions">
                    <a class="btn btn-outline btn-sm" href="#" data-task-drawer-open target="_top">Открыть полностью</a>
                    <button class="btn btn-outline btn-sm" type="button" data-task-drawer-close>Закрыть</button>
                </div>
            </header>
            <iframe class="task-drawer__frame" data-task-drawer-frame title="Задача"></iframe>
        </aside>
    </div>
<?php endif; ?>
<?php if (!app_is_demo_mode()): ?>
    <div class="release-notes" data-release-notes aria-hidden="true">
        <div class="release-notes__backdrop" data-release-notes-close></div>
        <section class="release-notes__panel" role="dialog" aria-modal="true" aria-label="Что нового">
            <header class="release-notes__head">
                <div>
                    <span>Версия <?= e($versionInfo['version']) ?><?= $versionInfo['date'] !== '' ? ' · ' . e(format_date($versionInfo['date'])) : '' ?></span>
                    <h2><?= e($versionInfo['title'] ?: 'Что нового') ?></h2>
                </div>
                <button class="btn btn-outline btn-sm" type="button" data-release-notes-close>Закрыть</button>
            </header>
            <?php if ($versionInfo['changes']): ?>
                <ul class="release-notes__list">
                    <?php foreach ($versionInfo['changes'] as $change): ?>
                        <li><?= e($change) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">Для этой сборки список изменений не заполнен.</p>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php if (app_is_demo_mode()): ?>
<script>window.APP_BASE = <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>; window.APP_TASK_HUB_TITLE = <?= json_encode(app_task_hub_title(), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php else: ?>
<script>window.APP_BASE = window.LOCIA_BASE = <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>; window.APP_TASK_HUB_TITLE = <?= json_encode(app_task_hub_title(), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<script src="<?= asset('app.js') ?>"></script>
<script src="<?= asset('pwa.js') ?>" defer></script>
<?php require BASE_PATH . '/app/Views/components/lemma_tools.php'; ?></body>
</html>
