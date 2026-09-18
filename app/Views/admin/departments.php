<section class="panel">
    <div class="panel__head">
        <div>
            <h2>Отделы теперь на странице структуры</h2>
            <p class="muted">Единая настройка отделов, начальников и подчинённости находится в «Организация → Структура».</p>
        </div>
        <a class="btn btn-red" href="<?= url('/admin/org-structure#org-departments') ?>">Открыть структуру</a>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/admin/departments') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Создать отдел</h2>
        <button class="btn btn--red" type="submit">Создать</button>
    </div>
    <label>
        <span>Код отдела (например, ОВ, ВК, АР)</span>
        <input name="code" pattern="[a-zA-Z0-9_А-Яа-яЁё\-]+" placeholder="Например, ОВ" required>
    </label>
    <label>
        <span>Название отдела</span>
        <input name="name" placeholder="Например, Отдел отопления и вентиляции" required>
    </label>
    <label>
        <span>Руководитель отдела</span>
        <select name="head_user_id">
            <option value="">-- Не назначен --</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e(role_label($u['role'])) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Список отделов</h2>
        <span><?= count($departments) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Код</th>
                    <th>Название отдела</th>
                    <th>Руководитель</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($departments as $dept): ?>
                <tr>
                    <td><strong><?= e($dept['code']) ?></strong></td>
                    <td><?= e($dept['name']) ?></td>
                    <td>
                        <?php if ($dept['head_user_id'] && isset($usersMap[(int) $dept['head_user_id']])): ?>
                            <?= e($usersMap[(int) $dept['head_user_id']]['name']) ?>
                            <small class="muted" style="display:block; font-size:11px; margin-top:2px;">
                                <?= e(role_label($usersMap[(int) $dept['head_user_id']]['role'])) ?>
                            </small>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/departments/' . (int) $dept['id'] . '/delete') ?>" style="display:inline;" onsubmit="return confirm(<?= e(json_encode('Удалить отдел ' . $dept['code'] . '? Распределенные в нем пользователи останутся в системе, но их отдел будет сброшен.', JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>)">
                            <?= csrf_field() ?>
                            <button class="btn btn--red" style="padding: 4px 8px; font-size: 12px; line-height: 1;" type="submit">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
