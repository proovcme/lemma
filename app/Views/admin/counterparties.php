<form class="panel form-grid" method="post" action="<?= url('/admin/counterparties') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новый контрагент</h2>
        <button class="btn btn--red" type="submit">Добавить</button>
    </div>
    <label>
        <span>Фирма</span>
        <input type="text" name="company" required>
    </label>
    <label>
        <span>Роль</span>
        <input type="text" name="role" placeholder="Заказчик, подрядчик, смежник">
    </label>
    <label>
        <span>Представитель</span>
        <input type="text" name="representative" placeholder="ФИО или должность">
    </label>
    <label>
        <span>Контакт</span>
        <input type="text" name="contact" placeholder="телефон, email, примечание">
    </label>
</form>

<section class="panel">
    <div class="panel__head">
        <h2>Справочник контрагентов</h2>
        <span><?= count($items ?? []) ?> записей</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Фирма</th>
                <th>Роль</th>
                <th>Представитель</th>
                <th>Контакт</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (($items ?? []) as $item): ?>
                <tr>
                    <td><strong><?= e($item['company'] ?? '') ?></strong></td>
                    <td><?= e($item['role'] ?? '') ?></td>
                    <td><?= e($item['representative'] ?? '') ?></td>
                    <td><?= e($item['contact'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state empty-state--compact">
                            <span class="empty-state__icon">+</span>
                            <strong>Контрагентов пока нет</strong>
                            <span>Добавьте фирму, роль и представителя для реестра заданий.</span>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
