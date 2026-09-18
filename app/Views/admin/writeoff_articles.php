<form class="panel form-grid" method="post" action="<?= url('/admin/writeoff-articles') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новая статья списания</h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <label>
        <span>Код (например, 00, 92, 93)</span>
        <input name="code" required>
    </label>
    <label>
        <span>Название</span>
        <input name="name" required>
    </label>
    <label>
        <span>Вид</span>
        <select name="kind">
            <option value="nonproject">Непроектная</option>
            <option value="project">Проектная</option>
        </select>
    </label>
    <label>
        <span>Авто из категории времени</span>
        <select name="maps_category">
            <?php foreach ($categories as $val => $label): ?>
                <option value="<?= e($val) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Порядок</span>
        <input type="number" name="sort_order" value="0">
    </label>
    <label class="form-checkbox">
        <input type="checkbox" name="is_active" value="1" checked> <span>Активна</span>
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Статьи списания</h2>
        <span><?= count($articles) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Код</th><th>Название</th><th>Вид</th><th>Из категории</th><th>Активна</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($articles as $row): ?>
                <tr>
                    <td><strong><?= e($row['code']) ?></strong></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= $row['kind'] === 'project' ? 'проектная' : 'непроектная' ?></td>
                    <td><?= e($categories[$row['maps_category'] ?? ''] ?? ($row['maps_category'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
                    <td><?= (int) $row['is_active'] ? 'да' : '<span class="muted">нет</span>' ?></td>
                    <td>
                        <form method="post" action="<?= url('/admin/writeoff-articles/' . (int) $row['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('Удалить статью?')">
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
