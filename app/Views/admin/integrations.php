<section class="metric-row">
    <div class="metric"><span>M</span><strong><?= config('integrations.msp_sync_enabled') ? 'MSP включён' : 'MSP выключен' ?></strong></div>
    <div class="metric"><span>СБЦ</span><strong><?= (int) (($sbcStats['total'] ?? 0)) ?> позиций</strong></div>
</section>

<form id="sbc-builtin-import" method="post" action="<?= url('/admin/integrations/sbc/builtin') ?>">
    <?= csrf_field() ?>
</form>

<form class="panel form-grid" method="post" enctype="multipart/form-data" action="<?= url('/admin/integrations/sbc') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Справочник СБЦ</h2>
        <div class="toolbar__actions">
            <a class="btn btn-outline" href="<?= url('/admin/integrations/sbc/template') ?>">Шаблон CSV</a>
            <button class="btn btn-outline" form="sbc-builtin-import" type="submit">Заполнить offline СБЦ</button>
            <button class="btn btn--red" type="submit">Импортировать</button>
        </div>
    </div>
    <label>
        <span>Файл CSV/XLSX</span>
        <input type="file" name="sbc_file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required>
    </label>
    <label>
        <span>Сейчас в базе</span>
        <input value="<?= (int) (($sbcStats['collections'] ?? 0)) ?> сборников · <?= (int) (($sbcStats['total'] ?? 0)) ?> позиций" readonly>
    </label>
</form>

<?php if (!empty($sbcResult)): ?>
    <section class="panel">
        <div class="panel__head">
            <h2>Результат импорта СБЦ</h2>
            <span>создано <?= (int) $sbcResult['created'] ?> / обновлено <?= (int) $sbcResult['updated'] ?> / пропущено <?= (int) $sbcResult['skipped'] ?></span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>ID</th><th>Сборник</th><th>Таблица/пункт</th><th>Работа</th><th>Цена</th></tr></thead>
                <tbody>
                <?php foreach (($sbcResult['items'] ?? []) as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e(trim(($item['collection_code'] ?? '') . ' ' . ($item['collection_name'] ?? ''))) ?></td>
                        <td><?= e(trim('табл. ' . ($item['table_code'] ?? '') . ' п. ' . ($item['item_code'] ?? ''))) ?></td>
                        <td><?= e($item['work_name'] ?? '') ?></td>
                        <td><?= e(number_format((float) ($item['base_price'] ?? 0), 2, '.', ' ')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($sbcRecent)): ?>
    <section class="panel">
        <div class="panel__head">
            <h2>Последние позиции СБЦ</h2>
            <span class="muted">ID можно использовать в CSV плана затрат</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>ID</th><th>Сборник</th><th>Таблица/пункт</th><th>Работа</th><th>Показатель</th><th>Цена</th></tr></thead>
                <tbody>
                <?php foreach ($sbcRecent as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e(trim(($item['collection_code'] ?? '') . ' ' . ($item['collection_name'] ?? ''))) ?></td>
                        <td><?= e(trim('табл. ' . ($item['table_code'] ?? '') . ' п. ' . ($item['item_code'] ?? ''))) ?></td>
                        <td><?= e($item['work_name'] ?? '') ?></td>
                        <td><?= e($item['unit'] ?? '') ?></td>
                        <td><?= e(number_format((float) ($item['base_price'] ?? 0), 2, '.', ' ')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
