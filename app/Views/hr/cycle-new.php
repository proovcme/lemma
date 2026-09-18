<?php
$templates = $templates ?? [];
$users = $users ?? [];
$participants = array_map(static fn (array $user): array => [
    'id' => (int) $user['id'],
    'primary' => (string) $user['name'],
    'secondary' => (string) ($user['email'] ?? ''),
    'cells' => [
        'position' => trim((string) ($user['position_title'] ?? '')) ?: role_label((string) ($user['role'] ?? '')),
        'grade' => trim((string) ($user['position_grade'] ?? '')) ?: 'не указан',
        'target' => is_numeric($user['competency_position_index'] ?? null) ? 'настроена' : 'не настроена',
        'department' => (string) ($user['department'] ?: '—'),
    ],
], $users);
?>

<section class="project-head project-head--tab performance-review-head">
    <div>
        <span class="muted">HR / Performance Review / новый цикл</span>
        <h2>Начать Performance Review</h2>
        <p class="muted">Сначала сохраните черновик. Участники ничего не увидят, пока вы не проверите состав и не запустите первую партию.</p>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/hr') ?>">К списку циклов</a>
    </div>
</section>

<form class="panel form-grid review-cycle-form" method="post" action="<?= url('/hr/cycles') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="cycle_form_version" value="2">
    <div class="panel__head form-grid__full">
        <div>
            <h2>Параметры цикла</h2>
            <span class="muted">Черновик останется в общем списке, его можно будет открыть и отредактировать.</span>
        </div>
        <button class="btn btn--red" type="submit">Сохранить черновик</button>
    </div>
    <label><span>Год оценки</span><input type="number" name="review_year" min="2000" max="2200" value="<?= e(date('Y')) ?>" required></label>
    <label><span>Название</span><input name="title" required value="Performance Review <?= e(date('Y')) ?>"></label>
    <label><span>Шаблон анкеты</span><select name="template_id" required><?php foreach ($templates as $template): ?><option value="<?= (int) $template['id'] ?>"<?= selected((string) ($template['title'] ?? ''), \App\Services\PerformanceReviewService::ANNUAL_TEMPLATE_TITLE) ?>><?= e($template['title']) ?></option><?php endforeach; ?></select></label>
    <label class="review-test-check"><input type="checkbox" name="is_test" value="1"><span><strong>Тестовый прогон</strong><small>Отметьте для проверки сценария. Тест не занимает официальный годовой цикл.</small></span></label>
    <label><span>Начало оцениваемого периода</span><input type="date" name="period_start" lang="ru-RU"><small>С какого дня подводятся итоги работы.</small></label>
    <label><span>Конец оцениваемого периода</span><input type="date" name="period_end" lang="ru-RU"><small>По какой день оцениваются результаты.</small></label>
    <label><span>Заполнить до</span><input type="date" name="response_deadline" lang="ru-RU"><small>Срок анкеты и самооценки.</small></label>
    <div class="form-grid__full review-cycle-participants">
        <div class="review-cycle-participants__head">
            <div><h3 class="bulk-checklist__title">Участники</h3><p class="muted">Выберите всех участников цикла. После сохранения вы сможете проверить список и запускать людей партиями.</p></div>
        </div>
        <?php view('components/bulk_checklist', [
            'items' => $participants,
            'columns' => ['position' => 'Должность', 'grade' => 'Грейд', 'target' => 'Цель', 'department' => 'Отдел'],
            'searchPlaceholder' => 'Найти по имени, почте, роли или отделу',
            'checkboxAriaPrefix' => 'Включить в цикл',
        ], ''); ?>
    </div>
</form>
