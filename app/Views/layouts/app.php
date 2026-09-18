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
$bodyClasses[] = 'is-demo-theme';
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
</head>
<body class="<?= e(implode(' ', $bodyClasses)) ?>" data-unread-notifications="<?= $notificationCount ?>">
<a class="skip-link" href="#main-content">К содержимому</a>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= url($homePath) ?>">
            <span class="brand__mark"><?= e(app_brand_mark()) ?></span>
            <span class="brand__sub" title="<?= e(role_sidebar_label($userRole)) ?>"><?= e(role_sidebar_label($userRole)) ?></span>
        </a>
        <form class="global-search" method="get" action="<?= url('/search') ?>">
            <input type="search" name="q" value="<?= e($globalSearchQuery) ?>" placeholder="Поиск в системе" aria-label="Поиск в системе">
            <button type="submit" aria-label="Найти"><span aria-hidden="true">⌕</span><span class="sr-only">Найти</span></button>
        </form>
        <nav class="nav" aria-label="Основная навигация">
            <?php if (!app_is_demo_mode()): ?>
                <a class="nav__link<?= active_link('/knowledge') ?>" href="<?= url('/knowledge') ?>">База знаний</a>
            <?php endif; ?>
            <div class="nav__group">Работа</div>
            <a class="nav__link<?= active_link('/my-day') ?>" href="<?= url('/my-day') ?>">Мой день</a>
            <a class="nav__link<?= active_link('/notes') ?>" href="<?= url('/notes') ?>">Заметки</a>
            <a class="nav__link<?= active_link('/time') ?>" href="<?= url('/time') ?>">Время</a>
            <?php if (!app_is_demo_mode() && $managerReviewCount > 0): ?>
                <a class="nav__link<?= active_link('/performance-review/manager') ?>" href="<?= url('/performance-review/manager') ?>">Оценки сотрудников<?php if ($managerReviewReadyCount > 0): ?><span class="nav-badge"><?= $managerReviewReadyCount ?></span><?php endif; ?></a>
            <?php endif; ?>
            <?php if ($canOpenProjects): ?>
                <a class="nav__link<?= active_link('/projects') ?>" href="<?= url('/projects') ?>">Проекты</a>
                <?php if ($sidebarProjects): ?>
                    <div class="nav-projects">
                        <?php foreach ($sidebarProjects as $sidebarProject): ?>
                            <a class="nav-project<?= str_starts_with(request_path(), '/projects/' . $sidebarProject['id']) ? ' is-active' : '' ?>" href="<?= url('/projects/' . $sidebarProject['id']) ?>">
                                <span><?= e($sidebarProject['code']) ?></span>
                                <small><?= e($sidebarProject['title']) ?></small>
                                <?php if ((int) $sidebarProject['open_tasks'] > 0): ?><b><?= (int) $sidebarProject['open_tasks'] ?></b><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($canOpenProjects): ?>
                <div class="nav__group">ТИМ</div>
                <?php if (config('integrations.atlas_url', '') !== ''): ?><a class="nav__link<?= active_link('/locia-atlas') ?>" href="<?= atlas_url() ?>"<?= config('integrations.atlas_url', '') !== '' ? ' target="_blank" rel="noreferrer"' : '' ?>>Атлас</a><?php endif; ?>
                <a class="nav__link<?= active_link('/tasks/bim-family') ?>" href="<?= url('/tasks/bim-family') ?>">Заявки</a>
            <?php endif; ?>

            <?php if (!app_is_demo_mode() && ($canBrowseProfiles || $canManageUsers || $canOpenHr)): ?>
                <?php if ($canBrowseProfiles || $canManageUsers): ?>
                    <div class="nav__group">Команда</div>
                    <a class="nav__link<?= active_link('/team') ?>" href="<?= url('/team') ?>">Управление командой</a>
                <?php endif; ?>
                <?php if ($canOpenHr): ?>
                    <div class="nav__group">HR</div>
                    <a class="nav__link<?= active_link('/hr') ?>" href="<?= url('/hr') ?>">Performance Review</a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($canManageDepartmentBudget): ?>
                <div class="nav__group">Директор</div>
                <a class="nav__link<?= active_link('/director/portfolio') ?>" href="<?= url('/director/portfolio') ?>">Портфель проектов</a>
                <a class="nav__link<?= active_link('/director/staffing') ?>" href="<?= url('/director/staffing') ?>">Штатное расписание</a>
                <a class="nav__link<?= active_link('/director/budget') ?>" href="<?= url('/director/budget') ?>">Бюджет</a>
            <?php endif; ?>

            <?php if ($canOpenDpr || $canOpenReports || $canReviewTime || $canOpenCalculator): ?>
                <div class="nav__group">Управление</div>
                <?php if ($canOpenDpr): ?>
                    <a class="nav__link<?= active_link_any(['/shturman', '/golem', '/dpr']) ?>" href="<?= url('/shturman') ?>">Дашборд</a>
                <?php endif; ?>
                <?php if ($canOpenReports): ?>
                    <a class="nav__link<?= active_link('/reports') ?>" href="<?= url('/reports') ?>">Отчёты</a>
                <?php endif; ?>
                <?php if ($canReviewTime): ?><a class="nav__link<?= active_link('/time/approvals') ?>" href="<?= url('/time/approvals') ?>">Приёмка времени</a><?php endif; ?>
                <?php if ($canOpenReports): ?><a class="nav__link<?= active_link('/activity') ?>" href="<?= url('/activity') ?>">История</a><?php endif; ?>
                <?php if ($canOpenCalculator): ?><a class="nav__link<?= active_link('/calculator') ?>" href="<?= url('/calculator') ?>">Калькулятор</a><?php endif; ?>
            <?php endif; ?>
            <?php if (!app_is_demo_mode() && ($canManageUsers || $canManageSettings)): ?>
                <div class="nav__group">Администрирование</div>
                <?php if ($canManageUsers): ?>
                    <a class="nav__link<?= active_link('/admin/users') ?>" href="<?= url('/admin/users') ?>">Пользователи</a>
                <?php endif; ?>
                <?php if ($canOpenCompetency): ?>
                    <a class="nav__link<?= active_link('/admin/competencies') ?>" href="<?= url('/admin/competencies') ?>">Компетенции</a>
                <?php endif; ?>
                <?php if ($canManageSettings): ?>
                    <details class="nav-menu<?= $adminSettingsActive ? ' is-active' : '' ?>"<?= $adminSettingsActive ? ' open' : '' ?>>
                        <summary class="nav-menu__summary">Настройки</summary>
                        <div class="nav-menu__items">
                            <a class="nav__link<?= active_link('/admin/access') ?>" href="<?= url('/admin/access') ?>">Доступы</a>
                            <a class="nav__link<?= active_link('/admin/dictionaries') ?>" href="<?= url('/admin/dictionaries') ?>">Справочники</a>
                            <a class="nav__link<?= active_link('/admin/counterparties') ?>" href="<?= url('/admin/counterparties') ?>">Контрагенты</a>
                            <a class="nav__link<?= active_link('/admin/exchange-templates') ?>" href="<?= url('/admin/exchange-templates') ?>">Матрицы заданий</a>
                            <a class="nav__link<?= active_link('/admin/fields') ?>" href="<?= url('/admin/fields') ?>">Кастом-поля</a>
                            <a class="nav__link<?= active_link('/admin/integrations') ?>" href="<?= url('/admin/integrations') ?>">Интеграции</a>
                            <a class="nav__link<?= active_link('/admin/database-export') ?>" href="<?= url('/admin/database-export') ?>">Выгрузка базы</a>
                            <a class="nav__link<?= active_link('/admin/notifications') ?>" href="<?= url('/admin/notifications') ?>">Шаблоны писем</a>
                            <a class="nav__link<?= active_link('/admin/password-gen') ?>" href="<?= url('/admin/password-gen') ?>">Пароли</a>
                        </div>
                    </details>
                <?php endif; ?>
                <?php if ($canManagePayroll): ?>
                    <?php $payrollActive = active_link_any(['/admin/legal-entities', '/admin/writeoff-articles', '/admin/employee-entities', '/motivation', '/motivation/projects', '/motivation/settings']) !== ''; ?>
                    <details class="nav-menu<?= $payrollActive ? ' is-active' : '' ?>"<?= $payrollActive ? ' open' : '' ?>>
                        <summary class="nav-menu__summary">ФОТ</summary>
                        <div class="nav-menu__items">
                            <a class="nav__link<?= active_link('/motivation') ?>" href="<?= url('/motivation') ?>">Мотивация</a>
                            <a class="nav__link<?= active_link('/motivation/projects') ?>" href="<?= url('/motivation/projects') ?>">Фонды проектов</a>
                            <a class="nav__link<?= active_link('/motivation/settings') ?>" href="<?= url('/motivation/settings') ?>">Настройки мотивации</a>
                            <a class="nav__link<?= active_link('/admin/legal-entities') ?>" href="<?= url('/admin/legal-entities') ?>">Юрлица</a>
                            <a class="nav__link<?= active_link('/admin/writeoff-articles') ?>" href="<?= url('/admin/writeoff-articles') ?>">Статьи списания</a>
                            <a class="nav__link<?= active_link('/admin/employee-entities') ?>" href="<?= url('/admin/employee-entities') ?>">Сотрудники и ставки</a>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar__footer">
            <a class="user-mini user-mini--link" href="<?= url('/profile') ?>" aria-label="Открыть мой профиль">
                <div class="avatar"><?= e(initials($user['name'] ?? '')) ?></div>
                <div>
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <span><?= e(role_label($user['role'] ?? '')) ?></span>
                </div>
            </a>
            <button class="tour-start" type="button" data-tour-start>Учебник</button>
            <?php if (!app_is_demo_mode()): ?>
                <button class="version-badge" type="button" data-release-notes-open title="Что нового">v<?= e($versionInfo['version']) ?></button>
            <?php endif; ?>
            <form class="logout-form" method="post" action="<?= url('/logout') ?>">
                <?= csrf_field() ?>
                <button class="link-muted" type="submit">Выйти</button>
            </form>
        </div>
    </aside>

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
                    <?php if (!$isDrawerPage): ?>
                        <a class="btn btn-outline topbar-help-button" href="<?= url($contextHelpHref) ?>">Справка</a>
                    <?php endif; ?>
                    <?php foreach (($headerActions ?? []) as $action): ?>
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
</body>
</html>
