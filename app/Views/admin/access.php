<?php
$roleModel = $roleModel ?? \App\Services\RoleService::all();
$capabilityLabels = $capabilityLabels ?? \App\Services\RoleService::capabilityLabels();
$syncGroups = $syncGroups ?? [];
$syncNotes = [];
foreach ($syncGroups as $group) {
    $syncNotes[] = implode(' = ', array_map('role_label', $group));
}
?>

<form class="panel" method="post" action="<?= url('/admin/access') ?>">
    <?= csrf_field() ?>
    <div class="panel__head">
        <h2>Матрица доступов</h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <?php if ($syncNotes): ?>
        <p class="muted">Связанные роли сохраняются зеркально: <?= e(implode('; ', $syncNotes)) ?>.</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead>
            <tr>
                <th>Роль</th>
                <?php foreach ($capabilityLabels as $label): ?>
                    <th><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roleModel as $roleKey => $role): ?>
                <?php $enabled = array_flip($role['capabilities'] ?? []); ?>
                <tr>
                    <td>
                        <strong><?= e($role['label']) ?></strong><br>
                        <small><?= e($roleKey) ?></small>
                    </td>
                    <?php foreach ($capabilityLabels as $capability => $label): ?>
                        <td>
                            <input
                                type="checkbox"
                                name="access[<?= e($roleKey) ?>][<?= e($capability) ?>]"
                                value="1"
                                aria-label="<?= e($role['label'] . ': ' . $label) ?>"
                                <?= isset($enabled[$capability]) ? 'checked' : '' ?>
                            >
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
