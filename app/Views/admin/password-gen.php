<form class="panel form-grid" method="get" action="<?= url('/admin/password-gen') ?>">
    <div class="panel__head form-grid__full"><h2>Генератор паролей</h2><button class="btn btn--red" type="submit">Сгенерировать</button></div>
    <label><span>Длина</span><input type="number" name="length" min="8" max="20" value="<?= (int) $length ?>"></label>
    <label><span>Спецсимволы</span><input type="checkbox" name="special" value="1"<?= checked($special) ?>></label>
    <div class="form-grid__full one-time-password">
        <code id="generated-password"><?= e($password) ?></code>
        <button class="btn" type="button" data-copy="#generated-password">Копировать</button>
    </div>
</form>
