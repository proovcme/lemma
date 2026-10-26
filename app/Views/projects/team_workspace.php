<?php
$projectId = (int) $project['id'];
$sectionRows = [];
$stages = [];
foreach ($structure as $group) {
    if ((int) $group['id'] > 0) $stages[] = $group;
    foreach ($group['sections'] as $row) $sectionRows[] = $row;
}
$peopleText = static fn (array $people): string => $people ? implode(', ', array_column($people, 'name')) : 'Не назначены';
$stageSelect = static function (array $stages): void { ?>
<label>Стадия<select name="stage_id"><option value="">Без стадии</option><?php foreach ($stages as $stage): ?><option value="<?= (int) $stage['id'] ?>"><?= e($stage['code'] . ' · ' . $stage['title']) ?></option><?php endforeach; ?></select></label>
<?php };
?>
<div class="page-heading"><div><p class="eyebrow"><?= e($project['code']) ?> / <?= e($project['title']) ?></p><h1>Разделы и команда</h1><p>Состав этого проекта и люди, которые над ним работают.</p></div></div>
<nav class="tabs" aria-label="Разделы проекта"><a href="<?= url('/projects/' . $projectId) ?>">Обзор</a><a href="<?= url('/projects/' . $projectId . '#project/work') ?>">Задачи проекта</a><a class="active" aria-current="page" href="<?= url('/projects/' . $projectId . '/structure') ?>">Разделы и команда</a></nav>
<?php if ($project['status'] === 'archived'): ?><div class="workspace-alert">Проект в архиве. Состав и назначения доступны для просмотра.</div><?php endif; ?>
<?php if ($canEdit): ?>
<details class="section-add" id="add-section"><summary class="btn primary">+ Добавить раздел</summary><div class="section-add-body">
<h2>Выбрать из справочника</h2><p class="muted">Добавится только в этот проект. Команду можно назначить после добавления.</p>
<form class="section-form" method="post" action="<?= url('/projects/' . $projectId . '/team/sections') ?>"><?= csrf_field() ?>
<label>Раздел<select name="catalog_code" required><option value="">Выберите раздел</option><?php foreach ($catalogOptions as $code => $label): ?><option value="<?= e($code) ?>"><?= e($code . ' · ' . $label) ?></option><?php endforeach; ?></select></label><?php $stageSelect($stages); ?>
<div><button class="btn primary" type="submit">Добавить в проект</button></div></form>
<details class="section-custom"><summary>Нет нужного раздела в списке? Создать новый</summary><p class="muted">По умолчанию новый раздел появится только в этом проекте.</p>
<form class="section-form" method="post" action="<?= url('/projects/' . $projectId . '/team/sections') ?>"><?= csrf_field() ?><label>Код раздела<input name="code" maxlength="120" required placeholder="Например, АКУ"></label><label>Название<input name="title" maxlength="255" required placeholder="Например, Акустические решения"></label><?php $stageSelect($stages); ?>
<?php if ($canManageCatalog): ?><label class="check-line"><input type="checkbox" name="save_to_catalog" value="1">Также добавить в общий справочник для других проектов</label><?php else: ?><p class="muted">Для добавления в общий справочник обратитесь к руководителю. В этом проекте раздел доступен сразу.</p><?php endif; ?><div><button class="btn primary" type="submit">Создать и добавить в проект</button></div></form></details>
</div></details>
<?php endif; ?>
<?php if ($canEdit): require BASE_PATH . '/app/Views/projects/_composition_templates.php'; endif; ?>
<div class="section-heading"><h2>В проекте <span class="muted">· <?= count($sectionRows) ?></span></h2><?php if ($canManageCatalog && !app_is_demo_mode()): ?><a class="btn text" href="<?= url('/team/sections') ?>">Общий справочник ↗</a><?php endif; ?></div>
<div class="section-register" id="project-team-table">
<?php if (!$sectionRows): ?><div class="empty"><h2>Состав проекта ещё не задан</h2><p><?= $canEdit ? 'Нажмите «Добавить раздел»: выберите из справочника или создайте свой.' : 'ГИП или РП добавит разделы и назначит команду проекта.' ?></p></div><?php endif; ?>
<?php foreach ($sectionRows as $row): $sectionId = (int) $row['id']; ?>
<article class="section-entry" id="work-item-<?= $sectionId ?>"><div class="section-overview"><div class="section-name"><strong><?= e($row['code']) ?></strong><span><?= e($row['title']) ?></span><small><?= e($row['stage_code'] ?: 'Без стадии') ?><?= $row['work_kind'] === 'activity' ? ' · Общая активность' : '' ?></small></div><div class="section-people"><small>Исполнители</small><span><?= e($peopleText($row['executors'])) ?></span></div><div class="section-people"><small>Проверяющие</small><span><?= e($peopleText($row['reviewers'])) ?></span></div></div>
<?php if ($canEdit): ?>
<div class="section-actions"><?php if ($canCreateTasks): ?><a class="section-task-link" href="<?= url('/tasks/new?project_id=' . $projectId . '&workspace_section_id=' . $sectionId . '&task_intent=work') ?>">Создать задачу раздела →</a><?php endif; ?><details class="assignment-editor"><summary>Настроить команду</summary><form method="post" action="<?= url('/projects/' . $projectId . '/structure/items/' . $sectionId . '/assignments') ?>"><?= csrf_field() ?><div class="assignment-columns">
<?php foreach (['executor' => ['Исполнители', 'executors'], 'reviewer' => ['Проверяющие', 'reviewers']] as $role => [$label, $key]): $selected = array_map('intval', array_column($row[$key], 'user_id')); ?>
<fieldset><legend><?= e($label) ?></legend><div class="people-options"><?php foreach ($users as $person): $personId = (int) $person['id']; if (!(int) $person['is_active'] && !in_array($personId, $selected, true)) continue; ?><label class="check-line"><input type="checkbox" name="<?= e($role) ?>_ids[]" value="<?= $personId ?>"<?= in_array($personId, $selected, true) ? ' checked' : '' ?>><span><?= e($person['name']) ?><small><?= e($person['department'] ?: 'Без отдела') ?><?= !(int) $person['is_active'] ? ' · Неактивен: снимите назначение перед сохранением' : '' ?></small></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?>
</div><p class="muted">Исполнитель не может проверять собственную работу.</p><button class="btn primary" type="submit">Сохранить команду раздела</button></form></details>
<details class="section-remove"><summary>Убрать из проекта</summary><p>Раздел уйдёт из активного состава. Его задачи, назначения и история сохранятся. Вернуть раздел можно ниже.</p><form method="post" action="<?= url('/projects/' . $projectId . '/team/sections/' . $sectionId . '/state') ?>"><?= csrf_field() ?><input type="hidden" name="active" value="0"><button class="btn danger" type="submit">Подтвердить: убрать <?= e($row['code']) ?></button></form></details></div>
<?php endif; ?></article>
<?php endforeach; ?>
</div>
<?php if ($removed): ?><details class="removed-sections"><summary>Убранные разделы · <?= count($removed) ?></summary><p class="muted">Здесь сохранены разделы, которые убрали из активного состава проекта.</p><?php foreach ($removed as $row): ?><div class="removed-row"><div><strong><?= e($row['code']) ?></strong> · <?= e($row['title']) ?><small><?= e($row['stage_code'] ?: 'Без стадии') ?></small></div><?php if ($canEdit): ?><form method="post" action="<?= url('/projects/' . $projectId . '/team/sections/' . (int) $row['id'] . '/state') ?>"><?= csrf_field() ?><input type="hidden" name="active" value="1"><button class="btn" type="submit">Вернуть в проект</button></form><?php endif; ?></div><?php endforeach; ?></details><?php endif; ?>
