<?php
$isNew = !$folder;
$folderId = (int) ($folder['id'] ?? 0);
?>
<form class="panel form-grid" method="post" action="<?= url($isNew ? '/knowledge/folders/new' : '/knowledge/folders/' . $folderId) ?>">
    <?= csrf_field() ?>
    <label class="field field--wide">
        <span>Название папки</span>
        <input type="text" name="name" value="<?= e($folder['name'] ?? '') ?>" maxlength="160" required autofocus>
    </label>
    <label class="field">
        <span>Родительская папка</span>
        <select name="parent_id">
            <option value="">Корень базы знаний</option>
            <?php foreach ($folderOptions as $folderOption): ?>
                <option value="<?= (int) $folderOption['id'] ?>"<?= $selectedParentId === (int) $folderOption['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $folderOption['depth']) . $folderOption['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="field-hint">Допустимо не больше четырёх уровней.</small>
    </label>
    <label class="field">
        <span>Порядок</span>
        <input type="number" name="sort_order" value="<?= (int) ($folder['sort_order'] ?? 100) ?>" min="0" max="100000">
    </label>
    <div class="form-actions field--wide">
        <a class="btn btn-outline" href="<?= url($isNew ? '/knowledge' : '/knowledge/folders/' . $folderId) ?>">Отмена</a>
        <button class="btn btn--red" type="submit"><?= $isNew ? 'Создать папку' : 'Сохранить' ?></button>
    </div>
</form>
