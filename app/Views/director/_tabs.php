<nav class="tabs team-tabs" aria-label="Разделы директора">
    <a class="<?= ($directorTab ?? '') === 'portfolio' ? 'is-active' : '' ?>" href="<?= url('/director/portfolio') ?>">Портфель проектов</a>
    <a class="<?= ($directorTab ?? '') === 'staffing' ? 'is-active' : '' ?>" href="<?= url('/director/staffing') ?>">Штатное расписание</a>
    <a class="<?= ($directorTab ?? '') === 'budget' ? 'is-active' : '' ?>" href="<?= url('/director/budget') ?>">Бюджет</a>
</nav>
