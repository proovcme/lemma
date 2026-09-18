<form class="panel form-grid" method="post" action="<?= url('/admin/fields') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full"><h2>Новое поле</h2><button class="btn btn--red" type="submit">Добавить</button></div>
    <label><span>name</span><input name="name" pattern="[a-zA-Z0-9_]+" required></label>
    <label><span>Метка</span><input name="label" required></label>
    <label>
        <span>Тип</span>
        <select name="type">
            <option value="text">text</option>
            <option value="select">select</option>
            <option value="date">date</option>
            <option value="number">number</option>
            <option value="user">user</option>
            <option value="bool">bool</option>
            <option value="link">link — одна ссылка</option>
            <option value="links">links — список ссылок</option>
        </select>
    </label>
    <label><span>Проект</span><select name="project_id"><option value="">Глобальное</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= e($project['code']) ?></option><?php endforeach; ?></select></label>
    <label><span>Опции для select через запятую</span><input name="options"></label>
    <label><span>Порядок</span><input type="number" name="sort_order" value="0"></label>
    <label><span>Обязательное</span><input type="checkbox" name="required" value="1"></label>
</form>

<section class="panel">
    <div class="panel__head"><h2>Поля</h2><span><?= count($fields) ?></span></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>name</th><th>Метка</th><th>Тип</th><th>Проект</th><th>Обяз.</th><th>Порядок</th></tr></thead>
            <tbody>
            <?php foreach ($fields as $field): ?>
                <tr><td><?= e($field['name']) ?></td><td><?= e($field['label']) ?></td><td><?= e($field['type']) ?></td><td><?= e($field['project_code'] ?: 'Глобальное') ?></td><td><?= (int) $field['required'] ? 'Да' : 'Нет' ?></td><td><?= (int) $field['sort_order'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
