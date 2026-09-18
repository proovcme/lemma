<?php
$formatDecimal = static function (mixed $value, int $precision = 2): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$objectTypes = $objectTypes ?? [];
$totalHours = 0.0;
foreach ($preprojects as $preproject) {
    $totalHours += (float) ($preproject['labor_hours'] ?? 0);
}
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Предпроект / оценка трудозатрат / сверка СБЦ</span>
        <h2>Оценка</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <?php if ($canManageRates): ?><a class="btn btn-outline" href="<?= url('/cost-estimates/rates') ?>">Ставки</a><?php endif; ?>
    </div>
</section>

<section class="metric-row project-summary-metrics">
    <div class="metric"><span><?= count($preprojects) ?></span><strong>Предпроектов</strong></div>
    <div class="metric"><span><?= e($formatDecimal($totalHours, 2)) ?></span><strong>Часы к оценке</strong></div>
</section>

<?php if ($canEdit): ?>
    <form class="panel form-grid cost-estimate-create" method="post" action="<?= url('/cost-estimates') ?>">
        <?= csrf_field() ?>
        <div class="panel__head">
            <h2>Новый предпроект</h2>
            <span>без ГИП, РП и папки</span>
        </div>
        <label>Код
            <input type="text" name="code" maxlength="20" placeholder="PRE-2026-001">
        </label>
        <label>Название
            <input type="text" name="title" required placeholder="Концепция / коммерческая оценка">
        </label>
        <label>Объект
            <input type="text" name="object" placeholder="Наименование объекта">
        </label>
        <label>Адрес
            <input type="text" name="address" placeholder="Адрес / площадка">
        </label>
        <label>Тип объекта
            <select name="object_type">
                <option value=""></option>
                <?php foreach ($objectTypes as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Площадь, м2
            <input type="number" name="area_m2" step="0.01">
        </label>
        <label>Стадия
            <input type="text" name="stage" value="Предпроект">
        </label>
        <label class="form-grid__full">Стадии / состав
            <textarea name="stages_text" rows="2" placeholder="П, РД, обследование, концепция..."></textarea>
        </label>
        <label class="form-grid__full">Разделы
            <textarea name="sections_text" rows="4" placeholder="АР - Архитектурные решения&#10;КР - Конструктивные решения&#10;ОВ - Отопление и вентиляция"></textarea>
        </label>
        <button class="btn btn--red btn-sm cost-estimate-create__submit" type="submit">Создать предпроект</button>
    </form>
<?php endif; ?>

<section class="panel sheet-panel">
    <div class="panel__head">
        <h2>Предпроекты</h2>
        <span class="muted"><?= count($preprojects) ?> строк</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Код</th>
                <th>Название</th>
                <th>Объект</th>
                <th>Тип / площадь</th>
                <th>Разделы</th>
                <th>Оценки</th>
                <th>Часы</th>
                <th>Обновлено</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($preprojects as $preproject): ?>
                <tr>
                    <td><a class="task-link" href="<?= url('/cost-estimates/' . $preproject['id']) ?>"><?= e($preproject['code']) ?></a></td>
                    <td><strong><?= e($preproject['title']) ?></strong><small><?= e($preproject['stages_text'] ?? '') ?></small></td>
                    <td><span><?= e($preproject['object'] ?? '') ?></span><small><?= e($preproject['address'] ?? '') ?></small></td>
                    <td>
                        <span><?= e($objectTypes[$preproject['object_type'] ?? ''] ?? ($preproject['object_type'] ?? '')) ?></span>
                        <?php if ((float) ($preproject['area_m2'] ?? 0) > 0): ?><small><?= e($formatDecimal($preproject['area_m2'], 2)) ?> м2</small><?php endif; ?>
                    </td>
                    <td><?= (int) ($preproject['sections_count'] ?? 0) ?></td>
                    <td><?= (int) ($preproject['labor_count'] ?? 0) ?></td>
                    <td><?= e($formatDecimal($preproject['labor_hours'] ?? 0, 2)) ?></td>
                    <td><?= e(format_date(substr((string) ($preproject['updated_at'] ?? ''), 0, 10))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$preprojects): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state empty-state--compact">
                            <span class="empty-state__icon">+</span>
                            <strong>Предпроектов пока нет</strong>
                            <span>Создайте предпроект, добавьте разделы и назначьте исполнителям оценку трудозатрат.</span>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
