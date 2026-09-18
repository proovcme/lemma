<?php
$projectId = (int) $project['id'];
$assignableUsers = array_values(array_filter($users, static fn (array $person): bool => (int) ($person['is_active'] ?? 1) === 1 && (string) ($person['role'] ?? '') !== 'admin'));
$completeCount = 0;
$teamUserIds = [];
foreach ($teamRows as $row) {
    $executorIds = array_map(static fn (array $person): int => (int) ($person['user_id'] ?? 0), $row['executors']);
    $reviewerId = (int) ($row['reviewer']['user_id'] ?? 0);
    $teamUserIds = [...$teamUserIds, ...$executorIds, ...($reviewerId > 0 ? [$reviewerId] : [])];
    if ($executorIds !== [] && $reviewerId > 0) $completeCount++;
}
$teamUserIds = array_values(array_unique($teamUserIds));
$peopleSummary = static function (array $rows): string {
    if ($rows === []) return 'Не назначены';
    return implode(', ', array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows));
};
$renderExecutors = static function (string $code, array $assignedRows, array $assignableUsers): void {
    $inputName = 'executor_ids[' . $code . '][]';
    $selected = array_fill_keys(array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $assignedRows), true);
    ?>
    <div class="project-team-people-field" data-project-people-field data-project-people-name="<?= e($inputName) ?>">
        <div class="project-team-people-field__selected" data-project-people-selected>
            <?php foreach ($assignedRows as $assigned): ?><?php $userId = (int) ($assigned['user_id'] ?? 0); ?>
                <span class="project-team-person-chip" data-project-person-chip data-user-id="<?= $userId ?>">
                    <span><?= e($assigned['name'] ?? '') ?></span>
                    <button type="button" data-project-person-remove aria-label="Убрать сотрудника <?= e($assigned['name'] ?? '') ?>">&times;</button>
                    <input type="hidden" name="<?= e($inputName) ?>" value="<?= $userId ?>">
                </span>
            <?php endforeach; ?>
        </div>
        <label class="project-team-people-field__add">
            <span class="sr-only">Добавить исполнителя раздела <?= e($code) ?></span>
            <select data-project-people-add aria-label="Добавить исполнителя раздела <?= e($code) ?>">
                <option value="">Найти исполнителя</option>
                <?php foreach ($assignableUsers as $person): ?><?php $userId = (int) $person['id']; ?>
                    <option value="<?= $userId ?>" data-person-name="<?= e($person['name']) ?>" data-person-department="<?= e($person['department'] ?: 'Без отдела') ?>"<?= isset($selected[$userId]) ? ' hidden' : '' ?>><?= e($person['name'] . ' · ' . ($person['department'] ?: 'Без отдела')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <span class="project-team-people-field__empty" data-project-people-empty<?= $assignedRows !== [] ? ' hidden' : '' ?>>Не назначены</span>
    </div>
    <?php
};
?>
<div class="topbar">
    <div class="topbar__meta"><span><?= e($project['code']) ?></span><h1>Структура и команда</h1><p>Все разделы организации, исполнители и проверяющие — в одной таблице.</p></div>
    <div class="topbar__actions"><a class="btn btn-outline" href="<?= url('/projects/' . $projectId) ?>">К проекту</a><a class="btn btn--red" href="<?= url('/projects/' . $projectId . '/health-report') ?>">Что у нас плохого</a></div>
</div>
<?php $projectNavActive = 'structure'; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<section class="panel project-team-table-panel" id="project-team-table">
    <div class="panel__head">
        <div><h2>Команда по разделам</h2><span>Строки загружены из базы подразделений. Для каждого раздела выберите исполнителей и одного проверяющего.</span><?php if ($canManageDepartments): ?><a class="project-team-departments-link" href="<?= url('/team/sections') ?>">Открыть справочник разделов</a><?php endif; ?></div>
        <div class="project-team-table-stats"><strong><?= count($teamUserIds) ?></strong><span>чел.</span><strong><?= $completeCount ?> / <?= count($teamRows) ?></strong><span>укомплектовано</span></div>
    </div>

    <form method="post" action="<?= url('/projects/' . $projectId . '/structure/assignments') ?>" class="project-team-assignment-table-form">
        <?= csrf_field() ?>
        <div class="project-team-table-toolbar">
            <label><span>Найти в таблице</span><input type="search" placeholder="Код, раздел или сотрудник" data-project-structure-filter autocomplete="off"></label>
            <span><strong data-project-structure-count><?= count($teamRows) ?></strong> разделов</span>
            <?php if ($canEdit && !$isArchived): ?><div class="project-team-table-toolbar__actions"><button class="btn btn--red" type="submit">Сохранить таблицу</button></div><?php endif; ?>
        </div>
        <div class="table-wrap project-team-table-wrap">
            <table class="data-table project-team-assignment-table project-team-department-table" data-no-column-filters>
                <thead><tr><th>Раздел</th><th>Исполнители</th><th>Проверяющий</th></tr></thead>
                <tbody>
                <?php foreach ($teamRows as $row): ?>
                    <?php
                    $code = (string) $row['code'];
                    $reviewer = $row['reviewer'];
                    $reviewerId = (int) ($reviewer['user_id'] ?? 0);
                    $peopleText = implode(' ', array_map(static fn (array $person): string => (string) ($person['name'] ?? ''), $row['executors'])) . ' ' . (string) ($reviewer['name'] ?? '');
                    $searchText = mb_strtolower(trim($code . ' ' . (string) $row['title'] . ' ' . $peopleText), 'UTF-8');
                    ?>
                    <tr data-project-structure-row data-project-structure-search="<?= e($searchText) ?>">
                        <td class="project-team-section-cell" data-label="Раздел">
                            <input type="hidden" name="department_codes[]" value="<?= e($code) ?>">
                            <strong><?= e($code) ?></strong>
                            <small><?= e($row['title']) ?></small>
                            <?php if ($code === 'ГИП'): ?><small class="project-team-gip-note">ГИП проекта назначается отдельно в карточке проекта<?= !empty($project['gip_name']) ? ': ' . e($project['gip_name']) : '' ?>. Здесь указывается команда раздела.</small><?php endif; ?>
                        </td>
                        <td class="project-team-people-cell" data-label="Исполнители">
                            <?php if ($canEdit && !$isArchived): ?><?php $renderExecutors($code, $row['executors'], $assignableUsers); ?><?php else: ?><span><?= e($peopleSummary($row['executors'])) ?></span><?php endif; ?>
                        </td>
                        <td class="project-team-reviewer-cell" data-label="Проверяющий">
                            <?php if ($canEdit && !$isArchived): ?>
                                <select name="reviewer_id[<?= e($code) ?>]" aria-label="Проверяющий раздела <?= e($code) ?>">
                                    <option value="">Не назначен</option>
                                    <?php foreach ($assignableUsers as $person): ?><?php $userId = (int) $person['id']; ?>
                                        <option value="<?= $userId ?>"<?= $reviewerId === $userId ? ' selected' : '' ?>><?= e($person['name'] . ' · ' . ($person['department'] ?: 'Без отдела')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?><span><?= e($reviewer['name'] ?? 'Не назначен') ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="empty-state" data-project-structure-empty<?= $teamRows !== [] ? ' hidden' : '' ?>><strong><?= $teamRows === [] ? 'В базе нет разделов' : 'Ничего не найдено' ?></strong><p><?= $teamRows === [] ? 'Добавьте подразделения в справочник организации.' : 'Измените поиск по коду, разделу или сотруднику.' ?></p></div>
        </div>
    </form>
</section>
