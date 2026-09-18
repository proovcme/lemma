<?php
$roles = $roles ?? [];
$departments = $departments ?? [];
?>

<section class="panel">
    <div class="panel__head">
        <h2>Импорт пользователей из CSV</h2>
        <a class="btn" href="<?= url('/admin/users') ?>">← Назад к пользователям</a>
    </div>

    <div class="form-grid__full" style="padding: 0 var(--space-4) var(--space-2);">
        <p class="muted">
            Загрузите CSV-файл с двумя колонками: <strong>ФИО</strong> и <strong>email</strong>.
            Порядок колонок не важен — система определяет email по символу <code>@</code>.
            Разделитель: запятая или точка с запятой (Excel-формат поддерживается).
        </p>
        <p class="muted">
            Для каждого пользователя будет сгенерирован временный пароль и назначен следующий свободный табельный номер.
            Если email уже есть в системе — строка пропускается.
        </p>
    </div>

    <form class="form-grid" method="post" action="<?= url('/admin/users/import') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <label class="form-grid__full">
            <span>CSV-файл <span class="required">*</span></span>
            <input type="file" name="csv_file" accept=".csv,.txt" required>
        </label>

        <label>
            <span>Роль по умолчанию</span>
            <select name="role">
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role) ?>"<?= $role === 'engineer' ? ' selected' : '' ?>><?= e(role_label($role)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Отдел по умолчанию</span>
            <select name="department">
                <option value="">-- Без отдела --</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= e($dept['code']) ?>"><?= e($dept['code']) ?> (<?= e($dept['name']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="form-grid__full" style="flex-direction: row; align-items: center; gap: var(--space-2);">
            <input type="checkbox" name="skip_header" value="1" checked>
            <span>Первая строка — заголовок (пропустить)</span>
        </label>

        <div class="form-grid__full">
            <button class="btn btn--red" type="submit">Загрузить и создать пользователей</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel__head"><h2>Пример формата CSV</h2></div>
    <pre style="padding: var(--space-3) var(--space-4); background: var(--bg-alt, #f5f5f5); border-radius: 4px; font-size: 0.85em;">ФИО;Email
Инженер Демо 1;engineer1@example.local
Инженер Демо 2;engineer2@example.local
Руководитель Демо;manager@example.local</pre>
    <p class="muted" style="padding: 0 var(--space-4) var(--space-3);">
        Файл можно сохранить из Excel как «CSV (разделители — запятые)» или «CSV UTF-8».
        Кириллические имена поддерживаются в кодировке UTF-8 и Windows-1251.
    </p>
</section>
