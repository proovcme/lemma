<?php $isEdit = !empty($project['id']); ?>
<?php $users = $users ?? []; ?>
<?php $formErrors = (array) ($formErrors ?? []); ?>
<?php $structureCatalog = (array) ($structureCatalog ?? ['stages' => [], 'activities' => [], 'templates' => []]); ?>
<?php
$formatGroupedNumber = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $normalized = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim((string) $value));
    if (!is_numeric($normalized)) {
        return (string) $value;
    }
    return rtrim(rtrim(number_format((float) $normalized, 2, '.', ' '), '0'), '.');
};
$fieldClass = static fn (string $name): string => isset($formErrors[$name]) ? 'form-field--error' : '';
$fieldInvalid = static fn (string $name): string => isset($formErrors[$name]) ? ' aria-invalid="true"' : '';
?>
<form id="<?= $isEdit ? 'project-edit' : 'project-create' ?>" class="panel form-grid project-form" method="post" action="<?= url($isEdit ? '/projects/' . $project['id'] : '/projects/new') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2><?= $isEdit ? 'Редактирование проекта' : 'Новый проект' ?></h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <?php if ($formErrors): ?>
        <div class="form-grid__full form-error-summary" role="alert">
            <strong>Проверьте обязательные поля</strong>
            <span>Всё, что вы уже ввели, сохранено в форме.</span>
        </div>
    <?php endif; ?>
    <p class="form-grid__full required-note"><b class="required-mark" aria-hidden="true">*</b> Обязательные поля</p>
    <label class="<?= $fieldClass('code') ?>">
        <span>Код проекта <b class="required-mark" aria-hidden="true">*</b></span>
        <input name="code" maxlength="20" required value="<?= e($project['code'] ?? '') ?>"<?= $fieldInvalid('code') ?>>
        <?php if (isset($formErrors['code'])): ?><small class="field-error"><?= e($formErrors['code']) ?></small><?php endif; ?>
    </label>
    <?php if ($isEdit): ?><label><span>Основная стадия в паспорте</span><select name="stage"><?php foreach (['ПД','РД','ПД-РД','АН'] as $stage): ?><option value="<?= e($stage) ?>"<?= selected($project['stage'] ?? 'РД', $stage) ?>><?= e($stage) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>
        <span>Начало проекта</span>
        <input type="date" name="start_date" value="<?= e($project['start_date'] ?? '') ?>">
    </label>
    <label>
        <span>Окончание проекта</span>
        <input type="date" name="finish_date" value="<?= e($project['finish_date'] ?? '') ?>">
    </label>
    <label class="form-grid__full <?= $fieldClass('title') ?>">
        <span>Название <b class="required-mark" aria-hidden="true">*</b></span>
        <input name="title" required value="<?= e($project['title'] ?? '') ?>"<?= $fieldInvalid('title') ?>>
        <?php if (isset($formErrors['title'])): ?><small class="field-error"><?= e($formErrors['title']) ?></small><?php endif; ?>
    </label>
    <label class="form-grid__full">
        <span>Объект</span>
        <input name="object" value="<?= e($project['object'] ?? '') ?>">
    </label>
    <label class="form-grid__full">
        <span>Адрес</span>
        <input name="address" value="<?= e($project['address'] ?? '') ?>">
    </label>
    <label>
        <span>Тип объекта</span>
        <input name="object_type" value="<?= e($project['object_type'] ?? '') ?>">
    </label>
    <label>
        <span>Площадь, м2</span>
        <input type="text" inputmode="decimal" name="area_m2" value="<?= e($formatGroupedNumber($project['area_m2'] ?? null)) ?>" data-grouped-number>
    </label>
    <label class="form-grid__full">
        <span>Стадии / состав</span>
        <textarea name="stages_text" rows="2"><?= e($project['stages_text'] ?? '') ?></textarea>
    </label>
    <?php if (!$isEdit): ?>
        <?php
        $chosenStages = (array) ($project['structure_stage_codes'] ?? ['ПД', 'РД']);
        $chosenTemplates = (array) ($project['structure_stage_templates'] ?? ['ПД' => 'pp87', 'РД' => 'rd']);
        $chosenActivities = (array) ($project['structure_activity_codes'] ?? ['ТИМ', 'УПРАВЛЕНИЕ']);
        ?>
        <section class="form-grid__full project-create-structure <?= $fieldClass('structure') ?>">
            <div class="panel__head"><div><h3>Структура проекта <b class="required-mark" aria-hidden="true">*</b></h3><span>Выберите стадии и готовый состав. После создания его можно дополнить своими строками из общего справочника.</span></div></div>
            <div class="table-wrap"><table class="data-table data-table--compact" data-no-column-filters><thead><tr><th>Создать</th><th>Стадия</th><th>Готовый состав разделов</th></tr></thead><tbody>
            <?php foreach ((array) ($structureCatalog['stages'] ?? []) as $stageItem): ?><?php $stageCode = (string) $stageItem['value']; ?>
                <tr><td><input type="checkbox" name="stage_codes[]" value="<?= e($stageCode) ?>"<?= checked(in_array($stageCode, $chosenStages, true)) ?> aria-label="Создать стадию <?= e($stageCode) ?>"></td><td><strong><?= e($stageCode) ?></strong><small><?= e($stageItem['label']) ?></small></td><td><select name="stage_templates[<?= e($stageCode) ?>]" data-no-search><option value="">Пустая стадия</option><option value="pp87"<?= selected($chosenTemplates[$stageCode] ?? '', 'pp87') ?>>Постановление №87</option><option value="rd"<?= selected($chosenTemplates[$stageCode] ?? '', 'rd') ?>>Обычный перечень РД</option></select></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <fieldset class="project-create-activities"><legend>Общие активности — не разделы</legend><?php foreach ((array) ($structureCatalog['activities'] ?? []) as $activity): ?><?php $code = (string) $activity['value']; ?><label class="check-row"><input type="checkbox" name="activity_codes[]" value="<?= e($code) ?>"<?= checked(in_array($code, $chosenActivities, true)) ?>><span><strong><?= e($code) ?></strong> · <?= e($activity['label']) ?></span></label><?php endforeach; ?></fieldset>
            <?php if (isset($formErrors['structure'])): ?><small class="field-error"><?= e($formErrors['structure']) ?></small><?php endif; ?>
        </section>
    <?php endif; ?>
    <label>
        <span>ПП</span>
        <input name="pp" maxlength="255" value="<?= e($project['pp'] ?? '') ?>">
    </label>
    <label class="<?= $fieldClass('gip_user_id') ?>">
        <span>ГИП <b class="required-mark" aria-hidden="true">*</b></span>
        <select name="gip_user_id" required data-no-search<?= $fieldInvalid('gip_user_id') ?>>
            <option value=""></option>
            <?php foreach ($users as $projectUser): ?>
                <option value="<?= (int) $projectUser['id'] ?>"<?= selected($project['gip_user_id'] ?? '', $projectUser['id']) ?>>
                    <?= e($projectUser['name'] . ' · ' . role_label($projectUser['role'] ?? '') . (isset($projectUser['is_active']) && (int) $projectUser['is_active'] !== 1 ? ' · неактивен' : '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($formErrors['gip_user_id'])): ?><small class="field-error"><?= e($formErrors['gip_user_id']) ?></small><?php endif; ?>
    </label>
    <label class="<?= $fieldClass('rp_user_id') ?>">
        <span>РП <b class="required-mark" aria-hidden="true">*</b></span>
        <select name="rp_user_id" required data-no-search<?= $fieldInvalid('rp_user_id') ?>>
            <option value=""></option>
            <?php foreach ($users as $projectUser): ?>
                <option value="<?= (int) $projectUser['id'] ?>"<?= selected($project['rp_user_id'] ?? '', $projectUser['id']) ?>>
                    <?= e($projectUser['name'] . ' · ' . role_label($projectUser['role'] ?? '') . (isset($projectUser['is_active']) && (int) $projectUser['is_active'] !== 1 ? ' · неактивен' : '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($formErrors['rp_user_id'])): ?><small class="field-error"><?= e($formErrors['rp_user_id']) ?></small><?php endif; ?>
    </label>
    <div class="form-grid__full panel__head"><div><h3>Бюджет проекта <b class="required-mark" aria-hidden="true">*</b></h3><span class="muted">Можно указать только общую сумму, только части или заполнять детализацию постепенно.</span></div></div>
    <div class="form-grid__full project-budget-fields <?= $fieldClass('budget') ?>">
        <label><span>Общий бюджет, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_total_thousand" value="<?= e($formatGroupedNumber($project['budget_total_thousand'] ?? $project['budget_manual_thousand'] ?? null)) ?>" data-grouped-number data-project-budget-total></label>
        <label><span>Затраты, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_cost_thousand" value="<?= e($formatGroupedNumber($project['budget_cost_thousand'] ?? null)) ?>" data-grouped-number data-project-budget-part></label>
        <label><span>Прибыль, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_profit_thousand" value="<?= e($formatGroupedNumber($project['budget_profit_thousand'] ?? null)) ?>" data-grouped-number data-project-budget-part></label>
        <label><span>Премиальная часть, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_bonus_thousand" value="<?= e($formatGroupedNumber($project['budget_bonus_thousand'] ?? null)) ?>" data-grouped-number data-project-budget-part></label>
        <p class="project-budget-remainder" data-project-budget-remainder></p>
        <?php if (isset($formErrors['budget'])): ?><small class="field-error"><?= e($formErrors['budget']) ?></small><?php endif; ?>
    </div>
    <label class="form-grid__full">
        <span>Папка с моделями для Атласа (необязательно)</span>
        <input name="model_folder_url" placeholder="/data/projects/demo/models" value="<?= e($project['model_folder_url'] ?? '') ?>">
        <small class="field-hint">Сетевая папка с .frag/.ifc — Атлас покажет все модели из неё (рекурсивно, .frag в приоритете). Положили новую версию — кнопка «Обновить» подтянет.</small>
    </label>
    <label>
        <span>Цвет</span>
        <input type="color" name="color" value="<?= e($project['color'] ?? '#cc1f1f') ?>">
    </label>
</form>
