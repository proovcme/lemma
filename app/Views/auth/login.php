<?php if (!empty($demoMode)): ?>
<style>
    .demo-access { display: grid; gap: 1rem; }
    .demo-access__head { display: grid; gap: .35rem; text-align: center; }
    .demo-access__head h1 { margin: 0; }
    .demo-access__hint { margin: 0; font-size: .9rem; color: #4E5969; }
    .demo-access__grid { display: grid; gap: .5rem; }
    .demo-access__grid form { margin: 0; }
    .demo-access__btn { width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: .1rem; padding: .6rem .85rem; }
    .demo-access__role { font-weight: 600; }
    .demo-access__desc { font-size: .78rem; opacity: .85; font-weight: 400; }
</style>
<section class="demo-access" aria-labelledby="demo-access-title">
    <div class="demo-access__head">
        <h1 id="demo-access-title">Демо доступ</h1>
        <p class="demo-access__hint">Выберите роль для просмотра демо.</p>
    </div>
    <div class="demo-access__grid">
        <?php foreach (($demoPersonas ?? []) as $key => $persona): ?>
        <form method="post" action="<?= url('/demo-login') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="persona" value="<?= e($key) ?>">
            <button class="btn btn--red demo-access__btn" type="submit">
                <span class="demo-access__role"><?= e($persona['label']) ?></span>
                <span class="demo-access__desc"><?= e($persona['hint']) ?></span>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</section>
<?php else: ?>
<form class="form-stack" method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>
    <?php if (!empty($next)): ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php endif; ?>
    <h1>Вход</h1>
    <label>
        <span>Табельный номер или email</span>
        <input type="text" name="login" autocomplete="username" required autofocus>
    </label>
    <label>
        <span>Пароль</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn--red" type="submit">Войти</button>
</form>
<?php endif; ?>
