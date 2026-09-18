<section class="panel">
    <div class="panel__head">
        <div>
            <h1>Выгрузка базы</h1>
            <span>Полный дамп базы со всеми данными и все журналы Лемме</span>
        </div>
    </div>

    <div class="notice">
        <strong>В ZIP попадает полная база данных и все файловые логи Лемме.</strong>
        <span>Экспорт не использует внешние серверы. Модели, вложения, `.env` и серверные настройки в архив не включаются.</span>
    </div>

    <form method="post" action="<?= url('/admin/database-export/download') ?>">
        <?= csrf_field() ?>
        <button class="btn btn--red" type="submit">Выгрузить данные и логи в ZIP</button>
    </form>
</section>
