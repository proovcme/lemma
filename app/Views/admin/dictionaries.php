<?php
$scopeLabels = [0 => 'Глобальный'];
$technicalKinds = array_filter($kinds, static fn (string $kind): bool => $kind !== 'section', ARRAY_FILTER_USE_KEY);
$technicalItems = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['kind'] ?? '') !== 'section'));
?>
<section class="panel" id="sections">
    <div class="panel__head">
        <div>
            <h2>Разделы</h2>
            <p class="muted">Разделы ведутся в одном общем справочнике и используются в составе проектов и задачах.</p>
        </div>
        <a class="btn btn-outline" href="<?= url('/team/sections') ?>">Открыть разделы</a>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/admin/dictionaries') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новая запись технического справочника</h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <label>
        <span>Тип</span>
        <select name="kind" required>
            <?php foreach ($technicalKinds as $kind => $label): ?>
                <option value="<?= e($kind) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Область</span>
        <select name="project_id">
            <option value="">Глобальный справочник</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Значение</span>
        <input name="value" required placeholder="15.6.3.1 или DEMO/2026-ОВ">
    </label>
    <label>
        <span>Название</span>
        <input name="label" placeholder="Как показывать в списках">
    </label>
    <label>
        <span>Раздел</span>
        <select name="discipline">
            <option value="">Не привязана</option>
            <?php foreach (($sections ?? []) as $section): ?>
                <?php $sectionCaption = (string) $section['label'] !== (string) $section['value'] ? $section['value'] . ' · ' . $section['label'] : $section['value']; ?>
                <option value="<?= e($section['value']) ?>"><?= e($sectionCaption) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Порядок</span>
        <input type="number" name="sort_order" value="0">
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Технические справочники</h2>
        <span class="muted"><?= count($technicalItems) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Область</th>
                <th>Тип</th>
                <th>Значение</th>
                <th>Название</th>
                <th>Раздел</th>
                <th>Порядок</th>
                <th>Статус</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($technicalItems as $item): ?>
                <tr>
                    <td><span class="scope-pill <?= (int) $item['scope_project_id'] === 0 ? 'scope-pill--global' : '' ?>"><?= (int) $item['scope_project_id'] === 0 ? 'Глобальный' : e($item['project_code']) ?></span></td>
                    <td><?= e($kinds[$item['kind']] ?? $item['kind']) ?></td>
                    <td><strong><?= e($item['value']) ?></strong></td>
                    <td><?= e($item['label']) ?></td>
                    <td><?= e($item['discipline']) ?></td>
                    <td><?= (int) $item['sort_order'] ?></td>
                    <td><?= (int) $item['active'] ? 'Активен' : 'Отключён' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
