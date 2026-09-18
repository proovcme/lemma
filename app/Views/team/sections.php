<?php require __DIR__ . '/_tabs.php'; ?>

<section class="panel">
    <div class="panel__head">
        <div>
            <h2>Разделы</h2>
            <p class="muted">Общий список разделов для состава проектов и привязки задач.</p>
        </div>
        <span class="muted"><?= count($sections) ?></span>
    </div>

    <form class="form-grid team-compact-form" method="post" action="<?= url('/team/sections') ?>">
        <?= csrf_field() ?>
        <label>
            <span>Шифр</span>
            <input name="code" maxlength="120" placeholder="АСУ" required>
        </label>
        <label>
            <span>Название</span>
            <input name="label" maxlength="255" placeholder="Автоматизированные системы управления" required>
        </label>
        <button class="btn btn--red" type="submit">Добавить раздел</button>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Шифр</th>
                <th>Название</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sections as $section): ?>
                <tr>
                    <td class="mono"><strong><?= e($section['value']) ?></strong></td>
                    <td><?= e($section['label']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
