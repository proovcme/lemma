<?php
$report = $report ?? [];
$sectionLabels = $sectionLabels ?? [];
$statusLabels = $statusLabels ?? [];
$periodTypeLabels = $periodTypeLabels ?? [];
$severityLabels = $severityLabels ?? [];
$canEditWeeklyReports = (bool) ($canEditWeeklyReports ?? false);
$itemsBySection = [];
foreach (($report['items'] ?? []) as $item) {
    $itemsBySection[(string) ($item['section_key'] ?? 'done')][] = $item;
}
$statusText = static fn (?string $status): string => $statusLabels[$status ?? ''] ?? (string) $status;
$periodText = static fn (?string $periodType): string => $periodTypeLabels[$periodType ?? ''] ?? (string) $periodType;
$severityText = static fn (?string $severity): string => $severityLabels[$severity ?? ''] ?? (string) $severity;
$projectLabel = static function (array $item): string {
    $code = trim((string) ($item['project_code'] ?? ''));
    $title = trim((string) ($item['project_title'] ?? ''));
    return trim($code . ($code !== '' && $title !== '' ? ' · ' : '') . $title);
};
?>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Периодический отчёт</span>
            <h2><?= e($report['title'] ?? 'Отчёт по проектам') ?></h2>
        </div>
        <div class="toolbar__actions">
            <a class="btn btn-outline" href="<?= url('/reports/periodic') ?>">К журналу</a>
            <button class="btn btn-outline" type="button" onclick="window.print()">Печать</button>
            <?php if ($canEditWeeklyReports): ?>
                <form method="post" action="<?= url('/reports/periodic/' . (int) $report['id'] . '/lock') ?>" onsubmit="return confirm('Зафиксировать отчёт? После фиксации он станет доступен только для чтения.');">
                    <?= csrf_field() ?>
                    <button class="btn btn--red" type="submit">Зафиксировать</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($canEditWeeklyReports): ?>
<form method="post" action="<?= url('/reports/periodic/' . (int) $report['id']) ?>">
    <?= csrf_field() ?>
<?php endif; ?>

<section class="panel form-grid">
    <div class="panel__head form-grid__full">
        <h2>Паспорт отчёта</h2>
        <span class="pill"><?= (string) ($report['state'] ?? '') === 'locked' ? 'Зафиксирован' : 'Черновик' ?></span>
    </div>
    <?php if ($canEditWeeklyReports): ?>
        <label class="form-grid__full"><span>Название</span><input type="text" name="title" value="<?= e($report['title'] ?? '') ?>" required></label>
        <label>
            <span>Тип периода</span>
            <select name="period_type">
                <?php foreach ($periodTypeLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($report['period_type'] ?? 'week', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Дата начала / опорная дата</span><input type="date" name="date_from" value="<?= e($report['date_from'] ?? '') ?>" required></label>
        <label><span>Дата конца</span><input type="date" name="date_to" value="<?= e($report['date_to'] ?? '') ?>" required></label>
        <label class="form-grid__full"><span>Адресат</span><input type="text" name="recipient" value="<?= e($report['recipient'] ?? '') ?>"></label>
        <label>
            <span>Статус</span>
            <select name="portfolio_status">
                <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($report['portfolio_status'] ?? '', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Предыдущий статус</span>
            <select name="previous_status">
                <option value="">Не указан</option>
                <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($report['previous_status'] ?? '', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="form-grid__full"><span>Резюме</span><textarea name="summary" rows="5"><?= e($report['summary'] ?? '') ?></textarea></label>
        <label class="form-grid__full"><span>Финансы</span><textarea name="finances_text" rows="3"><?= e($report['finances_text'] ?? '') ?></textarea></label>
        <label class="form-grid__full"><span>Выводы и решения</span><textarea name="conclusions_text" rows="4" placeholder="Свободный текст: выводы, поручения, ключевые решения периода"><?= e($report['conclusions_text'] ?? '') ?></textarea></label>
        <label class="form-grid__full"><span>Произвольные заметки</span><textarea name="notes_text" rows="4" placeholder="Свободный текст для любой дополнительной информации по отчёту"><?= e($report['notes_text'] ?? '') ?></textarea></label>
    <?php else: ?>
        <div>
            <span class="muted">Проект</span>
            <strong><?= e(implode(', ', array_map(static fn (array $project): string => $project['code'], $report['projects'] ?? []))) ?></strong>
        </div>
        <div>
            <span class="muted">Тип периода</span>
            <strong><?= e($periodText($report['period_type'] ?? 'week')) ?></strong>
        </div>
        <div>
            <span class="muted">Период</span>
            <strong><?= e(format_date($report['date_from'] ?? '')) ?> - <?= e(format_date($report['date_to'] ?? '')) ?></strong>
        </div>
        <div>
            <span class="muted">Статус</span>
            <strong><?= e($statusText($report['previous_status'] ?? null) ?: '-') ?> → <?= e($statusText($report['portfolio_status'] ?? '')) ?></strong>
        </div>
        <div>
            <span class="muted">Адресат</span>
            <strong><?= e($report['recipient'] ?? '') ?></strong>
        </div>
        <div class="form-grid__full">
            <span class="muted">Резюме</span>
            <p><?= nl2br(e($report['summary'] ?? '')) ?></p>
        </div>
        <div class="form-grid__full">
            <span class="muted">Финансы</span>
            <p><?= nl2br(e($report['finances_text'] ?? '')) ?></p>
        </div>
        <?php if (trim((string) ($report['conclusions_text'] ?? '')) !== ''): ?>
        <div class="form-grid__full">
            <span class="muted">Выводы и решения</span>
            <p><?= nl2br(e($report['conclusions_text'] ?? '')) ?></p>
        </div>
        <?php endif; ?>
        <?php if (trim((string) ($report['notes_text'] ?? '')) !== ''): ?>
        <div class="form-grid__full">
            <span class="muted">Произвольные заметки</span>
            <p><?= nl2br(e($report['notes_text'] ?? '')) ?></p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php foreach ($sectionLabels as $sectionKey => $sectionTitle): ?>
    <section class="panel">
        <div class="panel__head">
            <h2><?= e($sectionTitle) ?></h2>
            <span class="muted"><?= count($itemsBySection[$sectionKey] ?? []) ?> строк</span>
        </div>
        <?php if ($canEditWeeklyReports): ?>
            <div class="table-wrap">
                <table class="data-table analytics-table">
                    <thead>
                    <tr>
                        <th>№</th><th>Заголовок</th><th>План</th><th>Факт</th><th>Отклонение</th><th>Комментарий</th><th>Риск</th><th>Удалить</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($itemsBySection[$sectionKey] ?? []) as $item): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="items[<?= (int) $item['id'] ?>][section_key]" value="<?= e($sectionKey) ?>">
                                <input type="number" name="items[<?= (int) $item['id'] ?>][sort_order]" value="<?= (int) ($item['sort_order'] ?? 0) ?>" style="width:72px">
                                <?php if (!empty($item['source_href'])): ?><small><a href="<?= url($item['source_href']) ?>">Источник</a></small><?php endif; ?>
                            </td>
                            <td>
                                <textarea name="items[<?= (int) $item['id'] ?>][item_title]" rows="3"><?= e($item['item_title'] ?? '') ?></textarea>
                                <small><?= e($projectLabel($item)) ?></small>
                            </td>
                            <td><textarea name="items[<?= (int) $item['id'] ?>][plan_text]" rows="3"><?= e($item['plan_text'] ?? '') ?></textarea></td>
                            <td><textarea name="items[<?= (int) $item['id'] ?>][fact_text]" rows="3"><?= e($item['fact_text'] ?? '') ?></textarea></td>
                            <td><textarea name="items[<?= (int) $item['id'] ?>][deviation_text]" rows="3"><?= e($item['deviation_text'] ?? '') ?></textarea></td>
                            <td><textarea name="items[<?= (int) $item['id'] ?>][comment_text]" rows="3"><?= e($item['comment_text'] ?? '') ?></textarea></td>
                            <td>
                                <select name="items[<?= (int) $item['id'] ?>][severity]">
                                    <?php foreach ($severityLabels as $value => $label): ?>
                                        <option value="<?= e($value) ?>"<?= selected($item['severity'] ?? '', $value) ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><label><input type="checkbox" name="items[<?= (int) $item['id'] ?>][delete]" value="1"> удалить</label></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($itemsBySection[$sectionKey])): ?>
                        <tr><td colspan="8"><span class="muted">Строк нет.</span></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table analytics-table">
                    <thead>
                    <tr>
                        <th>Этап / объект</th><th>План</th><th>Факт</th><th>Отклонение</th><th>Комментарий</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($itemsBySection[$sectionKey] ?? []) as $item): ?>
                        <tr>
                            <td>
                                <strong><?= e($item['item_title'] ?? '') ?></strong>
                                <small><?= e($projectLabel($item)) ?><?= ($item['source_href'] ?? '') ? ' · источник' : '' ?></small>
                            </td>
                            <td><?= nl2br(e($item['plan_text'] ?? '')) ?></td>
                            <td><?= nl2br(e($item['fact_text'] ?? '')) ?></td>
                            <td><?= nl2br(e($item['deviation_text'] ?? '')) ?></td>
                            <td><?= nl2br(e($item['comment_text'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($itemsBySection[$sectionKey])): ?>
                        <tr><td colspan="5"><span class="muted">Строк нет.</span></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($canEditWeeklyReports): ?>
    <section class="panel">
        <div class="panel__head">
            <h2>Добавить строку</h2>
        </div>
        <div class="form-grid">
            <label>
                <span>Раздел</span>
                <select name="new_item_placeholder" disabled>
                    <option>Заполняется в форме ниже</option>
                </select>
            </label>
            <button class="btn" type="submit">Сохранить изменения</button>
        </div>
    </section>
</form>

<form class="panel form-grid" method="post" action="<?= url('/reports/periodic/' . (int) $report['id'] . '/items') ?>">
    <?= csrf_field() ?>
    <label>
        <span>Раздел</span>
        <select name="section_key">
            <?php foreach ($sectionLabels as $value => $label): ?>
                <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Проект</span>
        <select name="project_id">
            <option value="">Без проекта</option>
            <?php foreach (($report['projects'] ?? []) as $project): ?>
                <option value="<?= (int) $project['id'] ?>"><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Риск</span>
        <select name="severity">
            <?php foreach ($severityLabels as $value => $label): ?>
                <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="form-grid__full"><span>Заголовок</span><input type="text" name="item_title" required></label>
    <label><span>План</span><textarea name="plan_text" rows="3"></textarea></label>
    <label><span>Факт</span><textarea name="fact_text" rows="3"></textarea></label>
    <label><span>Отклонение</span><textarea name="deviation_text" rows="3"></textarea></label>
    <label><span>Комментарий</span><textarea name="comment_text" rows="3"></textarea></label>
    <div class="form-grid__full toolbar__actions">
        <button class="btn" type="submit">Добавить</button>
    </div>
</form>
<?php endif; ?>
