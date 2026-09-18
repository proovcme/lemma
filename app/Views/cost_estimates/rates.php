<?php
$formatRate = static function (mixed $value): string {
    $formatted = number_format((float) $value, 2, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
};
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Директорский справочник</span>
        <h2>Ставки сотрудников</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url('/cost-estimates') ?>">К оценке</a>
    </div>
</section>

<section class="panel sheet-panel">
    <div class="panel__head">
        <h2>Текущие ставки</h2>
        <span>руб./час</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Роль</th>
                <th>Отдел</th>
                <th>Ставка</th>
                <th>Обновлено</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $rateUser): ?>
                <?php $formId = 'rate-' . (int) $rateUser['id']; ?>
                <tr>
                    <td>
                        <strong><?= e($rateUser['name']) ?></strong>
                        <small><?= e($rateUser['email']) ?></small>
                    </td>
                    <td><?= e(role_label($rateUser['role'] ?? '')) ?></td>
                    <td><?= e($rateUser['department'] ?: '—') ?></td>
                    <td>
                        <form id="<?= e($formId) ?>" method="post" action="<?= url('/cost-estimates/rates') ?>"></form>
                        <input form="<?= e($formId) ?>" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input form="<?= e($formId) ?>" type="hidden" name="user_id" value="<?= (int) $rateUser['id'] ?>">
                        <input form="<?= e($formId) ?>" type="number" min="0" step="0.01" name="hourly_rate" value="<?= e($formatRate($rateUser['hourly_rate'] ?? 0)) ?>">
                    </td>
                    <td>
                        <?= e($rateUser['updated_at'] ? format_date($rateUser['updated_at']) : '—') ?>
                        <small><?= e($rateUser['updated_by_name'] ?: '') ?></small>
                    </td>
                    <td><button class="btn btn--red btn-sm" form="<?= e($formId) ?>" type="submit">Сохранить</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state empty-state--compact">
                            <span class="empty-state__icon">—</span>
                            <strong>Нет активных сотрудников</strong>
                            <span>Сначала добавьте пользователей в админке.</span>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
