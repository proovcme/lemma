<?php
$items = array_values((array) ($items ?? []));
$columns = (array) ($columns ?? []);
$selectedIds = array_fill_keys(array_map('intval', (array) ($selectedIds ?? [])), true);
$selectAllByDefault = (bool) ($selectAllByDefault ?? false);
$inputName = (string) ($inputName ?? 'employee_ids[]');
$searchPlaceholder = (string) ($searchPlaceholder ?? 'Найти сотрудника');
$emptyText = (string) ($emptyText ?? 'Ничего не найдено.');
$checkboxAriaPrefix = (string) ($checkboxAriaPrefix ?? 'Выбрать');
$selectedCount = 0;
foreach ($items as $item) {
    $itemId = (int) ($item['id'] ?? 0);
    if ($selectAllByDefault || isset($selectedIds[$itemId])) {
        $selectedCount++;
    }
}
?>

<div class="bulk-checklist" data-bulk-checklist>
    <div class="bulk-checklist__toolbar">
        <label class="bulk-checklist__search">
            <span class="sr-only">Поиск по списку</span>
            <input type="search" data-bulk-checklist-search placeholder="<?= e($searchPlaceholder) ?>" autocomplete="off">
        </label>
        <div class="bulk-checklist__actions" role="group" aria-label="Массовый выбор">
            <button class="btn btn-outline btn-sm" type="button" data-bulk-checklist-select="all">Выбрать всех</button>
            <button class="btn btn-outline btn-sm" type="button" data-bulk-checklist-select="none">Снять выделение</button>
        </div>
        <span class="bulk-checklist__count muted" aria-live="polite"><strong data-bulk-checklist-count><?= $selectedCount ?></strong> из <?= count($items) ?> выбрано</span>
    </div>
    <div class="table-wrap bulk-checklist__list">
        <table class="data-table data-table--compact" data-no-column-filters>
            <thead>
                <tr><th class="bulk-checklist__check-column"><span class="sr-only">Выбор</span></th><th>Сотрудник</th><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $itemId = (int) ($item['id'] ?? 0);
                $checked = $selectAllByDefault || isset($selectedIds[$itemId]);
                $primary = (string) ($item['primary'] ?? '');
                $secondary = trim((string) ($item['secondary'] ?? ''));
                $searchText = trim($primary . ' ' . $secondary . ' ' . implode(' ', array_map('strval', (array) ($item['cells'] ?? []))));
                ?>
                <tr data-bulk-checklist-row data-bulk-checklist-text="<?= e(mb_strtolower($searchText, 'UTF-8')) ?>"<?= $checked ? ' class="is-selected"' : '' ?>>
                    <td><input type="checkbox" data-bulk-checklist-checkbox name="<?= e($inputName) ?>" value="<?= $itemId ?>"<?= $checked ? ' checked' : '' ?> aria-label="<?= e($checkboxAriaPrefix . ' ' . $primary) ?>"></td>
                    <td><strong><?= e($primary) ?></strong><?php if ($secondary !== ''): ?><small><?= e($secondary) ?></small><?php endif; ?></td>
                    <?php foreach ($columns as $key => $label): ?><td><?= e((string) (($item['cells'] ?? [])[$key] ?? '—')) ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr data-bulk-checklist-empty hidden><td colspan="<?= 2 + count($columns) ?>" class="muted"><?= e($emptyText) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
