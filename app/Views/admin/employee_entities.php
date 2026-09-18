<form class="panel form-grid" method="post" action="<?= url('/admin/employee-entities') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Назначить сотрудника в юрлицо</h2>
        <button class="btn btn--red" type="submit">Сохранить</button>
    </div>
    <p class="form-grid__full muted">Ставка чел-часа: если не задана явно, считается из суммы окладных компонент ÷ норма (<?= e($normHours) ?> ч/мес). Норму месяца можно будет менять при генерации свода.</p>
    <label>
        <span>Сотрудник</span>
        <select name="user_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['tab_number']) ?>, <?= e($u['department'] ?: '—') ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Юрлицо</span>
        <select name="legal_entity_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($entities as $le): ?>
                <option value="<?= (int) $le['id'] ?>"><?= e($le['code']) ?> — <?= e($le['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Часов в рабочем дне в этом юрлице</span>
        <input name="daily_hours" type="text" inputmode="decimal" placeholder="напр. 8 или 6">
    </label>
    <label>
        <span>Должность</span>
        <input name="position">
    </label>
    <label>
        <span>Стоимостная группа</span>
        <input name="cost_group" placeholder="напр. АР, СПБ, ОВиК">
    </label>
    <label>
        <span>Базовый оклад, ₽</span>
        <input name="base_oklad" type="text" inputmode="decimal" value="0">
    </label>
    <label>
        <span>Базовая надбавка, ₽</span>
        <input name="base_nadbavka" type="text" inputmode="decimal" value="0">
    </label>
    <label>
        <span>Премия, ₽</span>
        <input name="premium" type="text" inputmode="decimal" value="0">
    </label>
    <label>
        <span>Проектная надбавка, ₽</span>
        <input name="project_nadbavka" type="text" inputmode="decimal" value="0">
    </label>
    <label>
        <span>Ставка чел-часа явно, ₽ (необязательно)</span>
        <input name="rate_override" type="text" inputmode="decimal" placeholder="если задано — перекрывает расчёт">
    </label>
    <label class="form-checkbox">
        <input type="checkbox" name="is_primary" value="1"> <span>Основное место работы</span>
    </label>
    <label class="form-checkbox">
        <input type="checkbox" name="is_piecework" value="1"> <span>Сдельщина</span>
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Назначения сотрудник × юрлицо</h2>
        <span><?= count($assignments) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead><tr>
                <th>Сотрудник</th><th>Юрлицо</th><th>Осн.</th><th>ч/день</th>
                <th>Должность</th><th>Стоим.гр.</th><th>Оклад+надб.+прем.+пр.</th><th>Ставка ₽/чч</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$assignments): ?>
                <tr><td colspan="9" class="muted">Назначений ещё нет. Добавьте через форму выше.</td></tr>
            <?php endif; ?>
            <?php foreach ($assignments as $a): ?>
                <tr>
                    <td><strong><?= e($a['user_name']) ?></strong> <span class="muted">(<?= e($a['tab_number']) ?>)</span></td>
                    <td><?= e($a['entity_code']) ?></td>
                    <td><?= (int) $a['is_primary'] ? '✓' : '' ?></td>
                    <td><?= e(rtrim(rtrim((string) $a['daily_hours'], '0'), '.')) ?></td>
                    <td><?= e($a['position'] ?? '') ?></td>
                    <td><?= e($a['cost_group'] ?? '') ?></td>
                    <td><?= e(number_format((float) $a['base_oklad'] + (float) $a['base_nadbavka'] + (float) $a['premium'] + (float) $a['project_nadbavka'], 0, '.', ' ')) ?><?= $a['rate_override'] !== null ? ' <span class="muted">(ставка явно)</span>' : '' ?></td>
                    <td><strong><?= e(number_format((float) $a['computed_rate'], 2, '.', ' ')) ?></strong></td>
                    <td>
                        <form method="post" action="<?= url('/admin/employee-entities/' . (int) $a['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('Удалить назначение?')">
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
