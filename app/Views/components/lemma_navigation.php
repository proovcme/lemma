<?php
$navUser = current_user();
$navPath = request_path();
$navLayout = \App\Services\LayoutService::getLayoutData();
$navProjects = $navLayout['sidebarProjects'] ?? [];
$navGroups = ['Личная работа' => [['/my-day','Мой день','day'],['/tasks','Задачи','tasks'],['/notes','Заметки','notes'],['/time','Моё время','time']]];
if (\App\Services\PermissionService::canOpenProjects($navUser)) $navGroups['Проекты'] = [['/projects','Все проекты','projects']];
if (\App\Services\PermissionService::canOpenDpr($navUser)) $navGroups['Управление'][] = ['/shturman','Обзор работы','overview'];
if (\App\Services\PermissionService::canOpenReports($navUser)) $navGroups['Управление'][] = ['/reports','Отчёты','reports'];
if (\App\Services\PermissionService::canReviewTime($navUser)) $navGroups['Управление'][] = ['/time/approvals','Приёмка времени','approval'];
if (\App\Services\PermissionService::canOpenReports($navUser)) $navGroups['Управление'][] = ['/activity','История','history'];
$navGroups['Инструменты'] = [];
if (\App\Services\PermissionService::canOpenProjects($navUser)) $navGroups['Инструменты'][] = ['/tasks/bim-family','Заявки ТИМ','model'];
if (config('integrations.atlas_url','') !== '' && \App\Services\PermissionService::canOpenProjects($navUser)) $navGroups['Инструменты'][] = [atlas_url(),'Модели','model'];
if (!app_is_demo_mode()) {
 $navGroups['Инструменты'][] = ['/knowledge','База знаний','manual'];
 $navGroups['Инструменты'][] = ['/manual','Руководство','manual'];
 if (\App\Services\PermissionService::canManageDepartmentBudget($navUser)) { $navGroups['Директор'][] = ['/director/staffing','Штатное расписание','people']; $navGroups['Директор'][] = ['/director/budget','Бюджет','reports']; }
 if (\App\Services\PermissionService::canOpenCompetency($navUser)) $navGroups['Настройки'][] = ['/admin/competencies','Компетенции','people'];
 if (\App\Services\PermissionService::canManageSettings($navUser)) foreach (['/admin/access'=>'Доступ','/admin/counterparties'=>'Контрагенты','/admin/exchange-templates'=>'Шаблоны заданий','/admin/fields'=>'Поля','/admin/integrations'=>'Интеграции','/admin/database-export'=>'Выгрузка базы','/admin/notifications'=>'Уведомления','/admin/password-gen'=>'Пароли'] as $href=>$label) $navGroups['Настройки'][]=[$href,$label,'catalog'];
 if (\App\Services\PermissionService::canManagePayroll($navUser)) foreach (['/motivation'=>'Мотивация','/motivation/projects'=>'ФОТ проектов','/motivation/settings'=>'Настройки ФОТ','/admin/legal-entities'=>'Юридические лица','/admin/writeoff-articles'=>'Статьи списания','/admin/employee-entities'=>'Ставки сотрудников'] as $href=>$label) $navGroups['Финансы'][]=[$href,$label,'reports'];
 if (\App\Services\PermissionService::canBrowseEmployeeProfiles($navUser)) $navGroups['Управление'][] = ['/team','Команда','people'];
 if (\App\Services\PermissionService::canOpenHr($navUser)) $navGroups['Управление'][] = ['/hr','Оценка сотрудников','people'];
 if (\App\Services\PermissionService::canManageUsers($navUser)) $navGroups['Настройки'][] = ['/admin/users','Сотрудники','people'];
 if (\App\Services\PermissionService::canManageSettings($navUser)) $navGroups['Настройки'][] = ['/admin/dictionaries','Справочники','catalog'];
}
$navSymbols = ['day'=>'◉','tasks'=>'☷','notes'=>'▤','time'=>'◷','projects'=>'▦','overview'=>'▥','reports'=>'▧','approval'=>'✓','history'=>'↺','model'=>'◇','manual'=>'?','people'=>'◎','catalog'=>'≡'];
?>
<a class="lemma-brand" href="<?= url('/my-day') ?>"><span class="lemma-brand-mark" aria-hidden="true">л</span><span>лемма<small>Проектная работа</small></span></a>
<button type="button" class="lemma-launcher" data-lemma-launch><span>Поиск и переходы</span><kbd>⌘ K</kbd></button>
<nav class="lemma-nav" aria-label="Основная навигация">
<?php foreach ($navGroups as $group => $links): if (!$links) continue; ?><div class="lemma-nav-group"><span><?= e($group) ?></span><?php foreach ($links as [$href,$label,$icon]): $active = $href === '/tasks' ? in_array($navPath, ['/tasks','/tasks/all','/tasks/new'], true) || preg_match('#^/tasks/\d+#',$navPath) : ($href === '/time' ? $navPath === '/time' : ($navPath === $href || str_starts_with($navPath,$href.'/'))); ?><a href="<?= e(preg_match('#^https?://#i', $href) ? $href : url($href)) ?>"<?= $active ? ' class="is-active" aria-current="page"' : '' ?>><i aria-hidden="true"><?= $navSymbols[$icon] ?></i><span><?= e($label) ?></span></a><?php endforeach; ?></div><?php endforeach; ?>
<?php if ($navProjects): ?><details class="lemma-nav-projects" open><summary>Под рукой <span><?= count($navProjects) ?></span></summary><?php foreach (array_slice($navProjects,0,5) as $navProject): ?><a href="<?= url('/projects/'.(int)$navProject['id']) ?>"<?= preg_match('#^/projects/'.(int)$navProject['id'].'(?:/|$)#',$navPath) ? ' aria-current="page"' : '' ?>><span class="lemma-project-code"><?= e($navProject['code']) ?></span><span><?= e($navProject['title']) ?></span></a><?php endforeach; ?></details><?php endif; ?>
</nav>
<div class="lemma-account"><span class="lemma-avatar" aria-hidden="true"><?= e(initials($navUser['name'] ?? '')) ?></span><a href="<?= url('/profile') ?>" aria-label="Открыть мой профиль"><strong><?= e($navUser['name'] ?? '') ?></strong><small><?= e(role_label($navUser['role'] ?? '')) ?></small></a></div>

<form class="lemma-logout" method="post" action="<?= url('/logout') ?>"><?= csrf_field() ?><button type="submit">Выйти из Леммы</button></form>
