<form class="form-stack" method="post" action="<?= url('/password/change') ?>">
    <?= csrf_field() ?>
    <h1>Смена пароля</h1>
    <label>
        <span>Новый пароль</span>
        <input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus>
    </label>
    <label>
        <span>Подтверждение</span>
        <input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required>
    </label>
    <button class="btn btn--red" type="submit">Сохранить пароль</button>
</form>
