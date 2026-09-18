<form class="panel form-grid" method="post" action="<?= url('/admin/exchange-templates') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новая матрица</h2>
        <button class="btn btn--red" type="submit">Добавить</button>
    </div>
    <label>
        <span>Название</span>
        <input type="text" name="name" required>
    </label>
    <label>
        <span>Код</span>
        <input type="text" name="code" placeholder="asu_base">
    </label>
    <label>
        <span>Раздел</span>
        <input type="text" name="scope_section" placeholder="АСУ">
    </label>
    <label>
        <span>Порядок</span>
        <input type="number" name="sort_order" value="100">
    </label>
    <label class="form-grid__full">
        <span>Описание</span>
        <textarea name="description" rows="2"></textarea>
    </label>
</form>

<?php foreach (($sets ?? []) as $set): ?>
    <?php $setItems = $itemsBySet[(int) $set['id']] ?? []; ?>
    <section class="panel">
        <div class="panel__head">
            <div>
                <h2><?= e($set['name'] ?? '') ?></h2>
                <span><?= e($set['scope_section'] ?? '') ?> · <?= e($set['code'] ?? '') ?></span>
            </div>
            <span><?= count($setItems) ?> пунктов</span>
        </div>

        <?php if (!empty($set['description'])): ?>
            <p class="muted"><?= e($set['description']) ?></p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Тип</th>
                    <th>От</th>
                    <th>Кому</th>
                    <th>Задание</th>
                    <th>Статус</th>
                    <th>Комментарий</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($setItems as $item): ?>
                    <tr>
                        <td><?= e(($item['direction'] ?? '') === 'incoming' ? 'Ждём' : 'Выдаём') ?></td>
                        <td><?= e($item['from_section'] ?? '') ?></td>
                        <td><?= e($item['to_section'] ?? '') ?></td>
                        <td><strong><?= e($item['assignment'] ?? '') ?></strong></td>
                        <td><?= e(exchange_status_label($item['default_status'] ?? 'pending')) ?></td>
                        <td><?= e($item['comments'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$setItems): ?>
                    <tr><td colspan="6"><span class="muted">Пунктов пока нет</span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form class="form-grid form-grid--compact" method="post" action="<?= url('/admin/exchange-templates/items') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="template_set_id" value="<?= (int) $set['id'] ?>">
            <label>
                <span>Код пункта</span>
                <input type="text" name="item_code" placeholder="in_eom">
            </label>
            <label>
                <span>Тип</span>
                <select name="direction">
                    <option value="incoming">Ждём</option>
                    <option value="outgoing">Выдаём</option>
                </select>
            </label>
            <label>
                <span>От раздела</span>
                <input type="text" name="from_section">
            </label>
            <label>
                <span>К разделу</span>
                <input type="text" name="to_section">
            </label>
            <label>
                <span>Статус</span>
                <select name="default_status">
                    <option value="pending">Ожидает</option>
                    <option value="in_progress">В работе</option>
                    <option value="blocked">Блокер</option>
                    <option value="done">Готово</option>
                </select>
            </label>
            <label>
                <span>Порядок</span>
                <input type="number" name="sort_order" value="100">
            </label>
            <label class="form-grid__full">
                <span>Задание</span>
                <textarea name="assignment" rows="2" required></textarea>
            </label>
            <label class="form-grid__full">
                <span>Комментарий</span>
                <textarea name="comments" rows="2"></textarea>
            </label>
            <div class="form-grid__full form-actions">
                <button class="btn btn-outline" type="submit">Добавить пункт</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>

<?php if (empty($sets)): ?>
    <section class="panel">
        <div class="empty-state empty-state--compact">
            <span class="empty-state__icon">+</span>
            <strong>Матриц пока нет</strong>
            <span>Создайте первую матрицу обмена заданиями.</span>
        </div>
    </section>
<?php endif; ?>
