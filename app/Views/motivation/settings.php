<?php
$formatDecimal = static function (mixed $value, int $precision = 4): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
};
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Мотивация / параметры формул</span>
        <h2>Настройки мотивации</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url('/motivation') ?>">К витрине</a>
        <a class="btn" href="<?= url('/motivation/projects') ?>">Фонды</a>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/motivation/settings') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <div>
            <h2>KPI и максимум выплаты</h2>
            <span class="muted">Все значения хранятся в базе и меняются без правки кода</span>
        </div>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <?php foreach ($settings as $key => $setting): ?>
        <label>
            <span><?= e($setting['label'] ?? $key) ?></span>
            <input name="<?= e($key) ?>" type="number" step="0.0001" value="<?= e($formatDecimal($setting['value'] ?? 0)) ?>">
        </label>
    <?php endforeach; ?>
</form>

<form class="panel form-grid" method="post" action="<?= url('/motivation/settings') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <div>
            <h2>Коэффициенты грейдов</h2>
            <span class="muted">Нулевой коэффициент исключает грейд из проектного распределения</span>
        </div>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <?php foreach ($grades as $grade => $row): ?>
        <label>
            <span><?= e($row['label'] ?? $grade) ?></span>
            <input name="grade_<?= e($grade) ?>" type="number" step="0.0001" value="<?= e($formatDecimal($row['coefficient'] ?? 0)) ?>">
        </label>
    <?php endforeach; ?>
</form>
