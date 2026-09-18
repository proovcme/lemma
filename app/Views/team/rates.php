<?php require __DIR__ . '/_tabs.php'; ?>

<section class="panel">
    <div class="panel__head"><div><h2>Ставки сотрудников</h2><p class="muted">Финансовые данные доступны только директору и аварийной технической учётке.</p></div><div class="filterbar"><label><span>Поиск</span><input type="search" placeholder="ФИО, отдел, должность" data-team-search></label></div></div>
    <div class="table-wrapper team-table-wrapper search-results" aria-live="polite">
        <table class="data-table team-table" data-no-column-filters><thead><tr><th>Сотрудник</th><th>Должность</th><th>Отдел</th><th>Ставка, ₽/ч</th><th>Обновлено</th></tr></thead><tbody>
        <?php foreach ($items as $item): $search = mb_strtolower(implode(' ', [$item['name'], $item['position_title'] ?? '', $item['department'] ?? '']), 'UTF-8'); ?>
            <tr data-team-row data-search="<?= e($search) ?>" data-department="" data-status="">
                <td data-label="Сотрудник"><a href="<?= url('/profiles/' . (int) $item['id']) ?>"><strong><?= e($item['name']) ?></strong></a></td>
                <td data-label="Должность"><?= e($item['position_title'] ?: 'Не назначена') ?></td><td data-label="Отдел"><?= e($item['department'] ?: '—') ?></td>
                <td data-label="Ставка"><form class="team-rate-form" method="post" action="<?= url('/admin/users/' . (int) $item['id'] . '/rate') ?>"><?= csrf_field() ?><input type="hidden" name="return_to" value="/team/rates"><input type="number" min="0" step="0.01" name="hourly_rate" value="<?= e((string) $item['hourly_rate']) ?>" aria-label="Ставка <?= e($item['name']) ?>"><button class="btn btn-sm btn-outline" type="submit">Сохранить</button></form></td>
                <td data-label="Обновлено" class="tabular-nums"><?= !empty($item['updated_at']) ? e(date('d.m.Y', strtotime((string) $item['updated_at']))) : '—' ?></td>
            </tr>
        <?php endforeach; ?><tr class="is-hidden" data-team-empty><td colspan="5">По этому поиску сотрудников не найдено.</td></tr>
        </tbody></table>
    </div>
</section>
