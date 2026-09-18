<section class="entry-home" aria-labelledby="entry-home-title">
    <div class="entry-home__head">
        <p class="entry-home__eyebrow">Единая рабочая среда</p>
        <h1 id="entry-home-title">Лемма</h1>
        <p>Управление проектами и просмотр информационных моделей.</p>
    </div>
    <div class="entry-home__actions">
        <a class="entry-choice entry-choice--primary" href="<?= url('/login') ?>">
            <span class="entry-choice__index">01</span>
            <span>
                <strong>Вход</strong>
                <small>Проекты, задачи и команда</small>
            </span>
            <span class="entry-choice__arrow" aria-hidden="true">→</span>
        </a>
        <a class="entry-choice" href="<?= url('/login?next=' . rawurlencode('/atlas/')) ?>">
            <span class="entry-choice__index">02</span>
            <span>
                <strong>Атлас</strong>
                <small>Просмотр моделей</small>
            </span>
            <span class="entry-choice__arrow" aria-hidden="true">→</span>
        </a>
        <a class="entry-choice" href="<?= url('/login?next=' . rawurlencode('/calculator')) ?>">
            <span class="entry-choice__index">03</span>
            <span>
                <strong>Калькулятор</strong>
                <small>Оценка проектных работ</small>
            </span>
            <span class="entry-choice__arrow" aria-hidden="true">→</span>
        </a>
        <a class="entry-choice" href="<?= url('/login?next=' . rawurlencode('/knowledge')) ?>">
            <span class="entry-choice__index">04</span>
            <span>
                <strong>База знаний</strong>
                <small>Регламенты и инструкции</small>
            </span>
            <span class="entry-choice__arrow" aria-hidden="true">→</span>
        </a>
    </div>
    <p class="entry-home__note">Все разделы доступны только после авторизации. Калькулятор — только руководству.</p>
</section>
