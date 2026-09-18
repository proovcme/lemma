<?php
$cycle = $cycle ?? [];
$templates = $templates ?? [];
$users = $users ?? [];
$participants = array_map(static fn (array $user): array => [
    'id' => (int) $user['id'],
    'primary' => (string) $user['name'],
    'secondary' => (string) ($user['email'] ?? ''),
    'cells' => [
        'position' => trim((string) ($user['position_title'] ?? '')) ?: role_label((string) ($user['role'] ?? '')),
        'grade' => trim((string) ($user['position_grade'] ?? '')) ?: 'не указан',
        'target' => is_numeric($user['competency_position_index'] ?? null) ? 'настроен' : 'не настроен',
        'department' => (string) ($user['department'] ?: '—'),
    ],
], $users);
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">HR / Performance Review / черновик</span>
        <h2>Редактирование цикла</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/hr/cycles/' . (int) $cycle['id']) ?>">Отменить</a>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/hr/cycles/' . (int) $cycle['id']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="cycle_form_version" value="2">
    <div class="panel__head form-grid__full">
        <div><h2><?= e($cycle['title'] ?? 'Черновик') ?></h2><span class="muted">Параметры и состав можно менять только до запуска первой группы.</span></div>
        <button class="btn btn--red" type="submit">Сохранить черновик</button>
    </div>
    <label><span>Год оценки</span><input type="number" name="review_year" min="2000" max="2200" value="<?= (int) ($cycle['review_year'] ?? date('Y')) ?>" required></label>
    <label><span>Название</span><input name="title" required value="<?= e($cycle['title'] ?? '') ?>"></label>
    <label><span>Шаблон анкеты</span><select name="template_id" required><?php foreach ($templates as $template): ?><option value="<?= (int) $template['id'] ?>"<?= selected((int) ($cycle['template_id'] ?? 0), (int) $template['id']) ?>><?= e($template['title']) ?></option><?php endforeach; ?></select></label>
    <label class="review-test-check"><input type="checkbox" name="is_test" value="1"<?= checked((string) ($cycle['cycle_kind'] ?? 'annual'), 'test') ?>><span><strong>Тестовый прогон</strong><small>Тест не занимает официальный годовой цикл.</small></span></label>
    <label><span>Начало оцениваемого периода</span><input type="date" name="period_start" lang="ru-RU" value="<?= e($cycle['period_start'] ?? '') ?>"><small>За какой рабочий период сотрудник подводит итоги.</small></label>
    <label><span>Конец оцениваемого периода</span><input type="date" name="period_end" lang="ru-RU" value="<?= e($cycle['period_end'] ?? '') ?>"><small>Последний день периода, за который оцениваются результаты.</small></label>
    <label><span>Заполнить до</span><input type="date" name="response_deadline" lang="ru-RU" value="<?= e($cycle['response_deadline'] ?? '') ?>"><small>Срок заполнения анкеты и самооценки.</small></label>
    <div class="form-grid__full">
        <h3 class="bulk-checklist__title">Участники цикла</h3>
        <?php view('components/bulk_checklist', [
            'items' => $participants,
            'columns' => ['position' => 'Должность', 'grade' => 'Грейд', 'target' => 'Цель', 'department' => 'Отдел'],
            'selectedIds' => $cycle['employee_ids'] ?? [],
            'searchPlaceholder' => 'Найти по имени, почте, роли или отделу',
            'checkboxAriaPrefix' => 'Включить в цикл',
        ], ''); ?>
    </div>
</form>
