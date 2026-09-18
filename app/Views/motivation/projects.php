<?php
$formatDecimal = static function (mixed $value, int $precision = 2): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
};
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Мотивация / проектная часть</span>
        <h2>Фонды проектов</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url('/motivation') ?>">К витрине</a>
        <a class="btn" href="<?= url('/motivation/settings') ?>">Настройки</a>
    </div>
</section>

<section class="panel sheet-panel">
    <div class="panel__head">
        <h2>Ручные фонды</h2>
        <span class="muted">Проектная надбавка считается только для оплаченных проектов</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Проект</th>
                <th>Фонд, ₽</th>
                <th>Бюджет часов</th>
                <th>Оплачен</th>
                <th>Дата оплаты</th>
                <th>Комментарий</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <?php $formId = 'motivation-project-' . (int) $project['project_id']; ?>
                <tr>
                    <td>
                        <strong><?= e($project['code']) ?></strong>
                        <small><?= e($project['title']) ?></small>
                    </td>
                    <td>
                        <form id="<?= e($formId) ?>" method="post" action="<?= url('/motivation/projects') ?>"></form>
                        <input form="<?= e($formId) ?>" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input form="<?= e($formId) ?>" type="hidden" name="project_id" value="<?= (int) $project['project_id'] ?>">
                        <input form="<?= e($formId) ?>" name="project_fund" type="number" min="0" step="0.01" value="<?= e($formatDecimal($project['project_fund'] ?? 0)) ?>">
                    </td>
                    <td>
                        <input form="<?= e($formId) ?>" name="budget_hours" type="number" min="0" step="0.01" value="<?= e($project['budget_hours'] !== null ? $formatDecimal($project['budget_hours']) : '') ?>" placeholder="<?= e($formatDecimal($project['approved_hours'] ?? 0)) ?>">
                        <small>оценка: <?= e($formatDecimal($project['approved_hours'] ?? 0)) ?></small>
                    </td>
                    <td>
                        <label class="form-checkbox">
                            <input form="<?= e($formId) ?>" type="checkbox" name="is_paid" value="1"<?= (int) ($project['is_paid'] ?? 0) === 1 ? ' checked' : '' ?>>
                            <span>доступен</span>
                        </label>
                    </td>
                    <td><input form="<?= e($formId) ?>" name="paid_at" type="date" value="<?= e((string) ($project['paid_at'] ?? '')) ?>"></td>
                    <td><input form="<?= e($formId) ?>" name="comment" value="<?= e((string) ($project['comment'] ?? '')) ?>"></td>
                    <td><button form="<?= e($formId) ?>" class="btn btn--red btn-sm" type="submit">Сохранить</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$projects): ?>
                <tr><td colspan="7" class="muted">Активных проектов нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
