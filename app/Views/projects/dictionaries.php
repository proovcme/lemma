<section class="project-head">
    <div>
        <span class="muted"><?= e($project['code']) ?></span>
        <h2>Справочники проекта</h2>
    </div>
    <a class="btn" href="<?= url('/projects/' . $project['id']) ?>">К проекту</a>
</section>

<?php $canViewProjectFinance = (bool) ($canViewProjectFinance ?? false); ?>

<?php $projectNavActive = ''; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<?php
$accounting = $accounting ?? ['pp' => [], 'btp' => [], 'uts' => []];
$ppCodes = $accounting['pp'] ?? [];
$btpCodes = $accounting['btp'] ?? [];
$utsFacts = $accounting['uts'] ?? [];
$technicalKinds = array_filter($kinds, static fn (string $kind): bool => $kind !== 'section', ARRAY_FILTER_USE_KEY);
$technicalItems = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['kind'] ?? '') !== 'section'));
?>

<section class="panel" id="sections">
    <div class="panel__head">
        <div>
            <h2>Разделы</h2>
            <p class="muted">Состав и команда проекта настраиваются отдельно, а новые шифры добавляются в общий справочник.</p>
        </div>
        <a class="btn btn-outline" href="<?= url('/team/sections') ?>">Открыть разделы</a>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>ПП / сделки</h2>
        <span class="muted"><?= count($ppCodes) ?></span>
    </div>
    <?php if ($canEdit): ?>
        <form class="form-grid" method="post" action="<?= url('/projects/' . $project['id'] . '/accounting/pp') ?>">
            <?= csrf_field() ?>
            <label>
                <span>Номер ПП</span>
                <input name="code" required placeholder="123456">
            </label>
            <label>
                <span>Название</span>
                <input name="title" placeholder="Договор / этап / заказчик">
            </label>
            <label>
                <span>Порядок</span>
                <input type="number" name="sort_order" value="0">
            </label>
            <label class="form-grid__full">
                <span>Примечание</span>
                <input name="notes">
            </label>
            <div class="form-grid__full"><button class="btn btn--red" type="submit">Сохранить ПП</button></div>
        </form>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead><tr><th>ПП</th><th>Название</th><th>Примечание</th><th>Порядок</th></tr></thead>
            <tbody>
            <?php foreach ($ppCodes as $pp): ?>
                <tr>
                    <td><strong><?= e($pp['code']) ?></strong></td>
                    <td><?= e($pp['title'] ?? '') ?></td>
                    <td><?= e($pp['notes'] ?? '') ?></td>
                    <td><?= (int) ($pp['sort_order'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ppCodes): ?><tr><td colspan="4" class="muted">ПП пока не заведены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>БТП / статьи списания</h2>
        <span class="muted"><?= count($btpCodes) ?></span>
    </div>
    <?php if ($canEdit): ?>
        <form class="form-grid" method="post" action="<?= url('/projects/' . $project['id'] . '/accounting/btp') ?>">
            <?= csrf_field() ?>
            <label>
                <span>ПП</span>
                <select name="pp_code_id" required>
                    <option value="">Выберите ПП</option>
                    <?php foreach ($ppCodes as $pp): ?>
                        <option value="<?= (int) $pp['id'] ?>"><?= e($pp['code']) ?><?= !empty($pp['title']) ? ' · ' . e($pp['title']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>БТП</span>
                <input name="code" required placeholder="Статья списания">
            </label>
            <label>
                <span>Название</span>
                <input name="title">
            </label>
            <label>
                <span>Порядок</span>
                <input type="number" name="sort_order" value="0">
            </label>
            <label class="form-grid__full">
                <span>Примечание</span>
                <input name="notes">
            </label>
            <div class="form-grid__full"><button class="btn btn--red" type="submit">Сохранить БТП</button></div>
        </form>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead><tr><th>ПП</th><th>БТП</th><th>Название</th><th>Примечание</th><th>Порядок</th></tr></thead>
            <tbody>
            <?php foreach ($btpCodes as $btp): ?>
                <tr>
                    <td><?= e($btp['pp_code']) ?></td>
                    <td><strong><?= e($btp['code']) ?></strong></td>
                    <td><?= e($btp['title'] ?? '') ?></td>
                    <td><?= e($btp['notes'] ?? '') ?></td>
                    <td><?= (int) ($btp['sort_order'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$btpCodes): ?><tr><td colspan="5" class="muted">БТП пока не заведены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>УТС / факт затрат</h2>
        <span class="muted"><?= count($utsFacts) ?></span>
    </div>
    <?php if ($canEdit): ?>
        <form class="form-grid" method="post" action="<?= url('/projects/' . $project['id'] . '/accounting/uts') ?>">
            <?= csrf_field() ?>
            <label>
                <span>ПП</span>
                <select name="pp_code_id" required>
                    <option value="">Выберите ПП</option>
                    <?php foreach ($ppCodes as $pp): ?>
                        <option value="<?= (int) $pp['id'] ?>"><?= e($pp['code']) ?><?= !empty($pp['title']) ? ' · ' . e($pp['title']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>БТП</span>
                <select name="btp_code_id">
                    <option value="">Без статьи</option>
                    <?php foreach ($btpCodes as $btp): ?>
                        <option value="<?= (int) $btp['id'] ?>"><?= e($btp['pp_code'] . ' · ' . $btp['code']) ?><?= !empty($btp['title']) ? ' · ' . e($btp['title']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Дата факта</span>
                <input type="date" name="fact_date">
            </label>
            <label>
                <span>Сумма факта</span>
                <input type="number" step="0.01" name="amount" required value="0">
            </label>
            <label>
                <span>Документ / основание</span>
                <input name="document_ref">
            </label>
            <label class="form-grid__full">
                <span>Описание</span>
                <input name="description">
            </label>
            <div class="form-grid__full"><button class="btn btn--red" type="submit">Добавить факт УТС</button></div>
        </form>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead><tr><th>Дата</th><th>ПП</th><th>БТП</th><th>Сумма</th><th>Основание</th><th>Описание</th></tr></thead>
            <tbody>
            <?php foreach ($utsFacts as $uts): ?>
                <tr>
                    <td><?= e(format_date($uts['fact_date'] ?? '') ?: '') ?></td>
                    <td><?= e($uts['pp_code']) ?></td>
                    <td><?= e($uts['btp_code'] ?? '') ?></td>
                    <td><strong><?= e(number_format((float) ($uts['amount'] ?? 0), 2, '.', ' ')) ?></strong></td>
                    <td><?= e($uts['document_ref'] ?? '') ?></td>
                    <td><?= e($uts['description'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$utsFacts): ?><tr><td colspan="6" class="muted">Факты УТС пока не внесены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canEdit): ?>
    <form class="panel form-grid" method="post" action="<?= url('/projects/' . $project['id'] . '/dictionaries') ?>">
        <?= csrf_field() ?>
        <div class="panel__head form-grid__full">
            <h2>Добавить проектное значение</h2>
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
            <span>Значение</span>
            <input name="value" required placeholder="<?= e($project['code']) ?>/2026-ОВ">
        </label>
        <label>
            <span>Название</span>
            <input name="label" placeholder="Как показывать в списках">
        </label>
        <label>
            <span>Порядок</span>
            <input type="number" name="sort_order" value="0">
        </label>
    </form>
<?php endif; ?>

<section class="panel">
    <div class="panel__head">
        <h2>Глобальные и проектные значения</h2>
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
            </tr>
            </thead>
            <tbody>
            <?php foreach ($technicalItems as $item): ?>
                <tr>
                    <td><span class="scope-pill <?= (int) $item['scope_project_id'] === 0 ? 'scope-pill--global' : '' ?>"><?= (int) $item['scope_project_id'] === 0 ? 'Глобальный' : 'Проект' ?></span></td>
                    <td><?= e($kinds[$item['kind']] ?? $item['kind']) ?></td>
                    <td><strong><?= e($item['value']) ?></strong></td>
                    <td><?= e($item['label']) ?></td>
                    <td><?= e($item['discipline']) ?></td>
                    <td><?= (int) $item['sort_order'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
