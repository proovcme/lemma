<form class="panel form-grid" method="post" action="<?= url('/admin/legal-entities') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новое юрлицо</h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <label>
        <span>Код (например, ВСС, АБИ, ТСС)</span>
        <input name="code" required>
    </label>
    <label>
        <span>Краткое название</span>
        <input name="name" required>
    </label>
    <label>
        <span>Полное юр. название</span>
        <input name="full_name">
    </label>
    <label>
        <span>ИНН</span>
        <input name="inn">
    </label>
    <label>
        <span>Порядок</span>
        <input type="number" name="sort_order" value="0">
    </label>
    <label class="form-checkbox">
        <input type="checkbox" name="is_active" value="1" checked> <span>Активно</span>
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Юрлица</h2>
        <span><?= count($entities) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Код</th><th>Название</th><th>Полное название</th><th>ИНН</th><th>Активно</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($entities as $row): ?>
                <tr>
                    <td><strong><?= e($row['code']) ?></strong></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['full_name'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                    <td><?= e($row['inn'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                    <td><?= (int) $row['is_active'] ? 'да' : '<span class="muted">нет</span>' ?></td>
                    <td>
                        <form method="post" action="<?= url('/admin/legal-entities/' . (int) $row['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('Удалить юрлицо? Назначения сотрудников по нему тоже удалятся.')">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline btn-sm" type="submit">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
