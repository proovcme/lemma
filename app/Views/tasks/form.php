<?php
$isEdit = (bool) $task;
$drawerMode = ($_GET['drawer'] ?? '') === '1';
$action = $isEdit ? '/tasks/' . $task['id'] : '/tasks/new';
$sectionOptions = [];
foreach (($dictionaries['section'] ?? []) as $sectionItem) {
    $sectionValue = trim((string) ($sectionItem['value'] ?? ''));
    if ($sectionValue === '' || isset($sectionOptions[$sectionValue])) {
        continue;
    }
    $sectionLabel = trim((string) ($sectionItem['label'] ?? ''));
    $sectionOptions[$sectionValue] = $sectionLabel !== '' ? $sectionLabel : $sectionValue;
}
$statuses = ['new', 'in_progress', 'correction', 'blocked', 'overdue'];
$workflowManagedStatus = $isEdit && in_array((string) ($task['status'] ?? ''), ['review', 'pending_close', 'done'], true);
$taskTypes = ['work', 'assignment', 'issuance', 'labor_estimate', 'delegation', 'bim_family_request'];
$taskIntents = [
    'work' => [
        'task_type' => 'work',
        'title' => 'Поставить задачу',
        'meta' => 'Обычная работа',
        'hint' => 'Для обычных рабочих действий: подготовить, проверить, исправить, посчитать.',
        'title_placeholder' => 'Например: Подготовить ведомость нагрузок',
        'due_label' => 'Срок',
        'assignee_label' => 'Исполнитель',
        'assignee_empty' => 'Не назначен',
        'what_label' => 'Что сделать',
        'what_placeholder' => 'Коротко сформулируйте результат',
        'why_label' => 'Зачем / контекст',
        'why_placeholder' => 'Почему задача важна и что будет считаться успехом',
        'composition_meta' => 'укажите обязательный раздел задачи',
        'source_label' => 'Связанная задача / зависит от',
    ],
    'assign_out' => [
        'task_type' => 'assignment',
        'title' => 'Выдать задание',
        'meta' => 'Передать смежнику',
        'hint' => 'Попадает в обмен заданиями и требует понятного адресата, раздела и срока.',
        'title_placeholder' => 'Например: Выдать АР задание на отверстия',
        'due_label' => 'Срок задания',
        'assignee_label' => 'Получатель / ответственный',
        'assignee_empty' => 'Выберите получателя',
        'what_label' => 'Что передать',
        'what_placeholder' => 'Опишите задание так, чтобы смежник мог взять его в работу',
        'why_label' => 'Исходный контекст',
        'why_placeholder' => 'Откуда задание и какой раздел оно закрывает',
        'composition_meta' => 'обязательно укажите раздел-получатель',
        'source_label' => 'Источник задания',
    ],
    'assign_request' => [
        'task_type' => 'assignment',
        'title' => 'Запросить задание',
        'meta' => 'Получить от смежника',
        'hint' => 'Тоже попадает в обмен заданиями, но формулируется как входящий запрос: что нужно получить и к какому сроку.',
        'title_placeholder' => 'Например: Запросить задание от ВК по стоякам',
        'due_label' => 'Нужно получить до',
        'assignee_label' => 'Ответственный за получение',
        'assignee_empty' => 'Выберите ответственного',
        'what_label' => 'Что нужно получить',
        'what_placeholder' => 'Опишите входящее задание или исходные данные, которые нужны',
        'why_label' => 'Для чего нужно',
        'why_placeholder' => 'Какая работа или выдача зависит от этого задания',
        'composition_meta' => 'привяжите запрос к своему разделу',
        'source_label' => 'Для какой задачи / раздела',
    ],
    'issuance' => [
        'task_type' => 'issuance',
        'title' => 'Выдача',
        'meta' => 'Том на приемку',
        'hint' => 'Из таких задач собираются тома, график РД и цепочка РГ → ГИП → выдача.',
        'title_placeholder' => 'Например: Выпустить том ОВ 15.6.3.1',
        'due_label' => 'Плановая выдача',
        'assignee_label' => 'Исполнитель выпуска',
        'assignee_empty' => 'Не назначен',
        'what_label' => 'Что выпускаем',
        'what_placeholder' => 'Укажите состав тома или комплект, который должен быть готов к приемке',
        'why_label' => 'Состав выдачи / замечания',
        'why_placeholder' => 'Что должно войти в выдачу и какие условия приемки важны',
        'composition_meta' => 'обязательно укажите раздел',
        'source_label' => 'Задача / раздел тома',
    ],
    'labor_estimate' => [
        'task_type' => 'labor_estimate',
        'title' => 'Оценка трудозатрат',
        'meta' => 'Предпроект',
        'hint' => 'Задача оценки трудозатрат создаётся из карточки предпроекта по конкретному разделу и исполнителю.',
        'title_placeholder' => 'Например: Оценить трудозатраты по разделу ОВ',
        'due_label' => 'Срок оценки',
        'assignee_label' => 'Ответственный за оценку',
        'assignee_empty' => 'Выберите исполнителя',
        'what_label' => 'Что оценить',
        'what_placeholder' => 'Укажите раздел и ожидаемый состав работ',
        'why_label' => 'Контекст оценки',
        'why_placeholder' => 'Исходные данные, стадия, допущения',
        'composition_meta' => 'раздел предпроекта',
        'source_label' => 'Раздел / исходная задача',
    ],
    'delegate_department' => [
        'task_type' => 'delegation',
        'title' => 'Делегировать отделу',
        'meta' => 'ГИП → руководитель',
        'hint' => 'ГИП ставит ответственность руководителю отдела, а руководитель распределяет работу на своих сотрудников или берёт задачу на себя.',
        'title_placeholder' => 'Например: Распределить раздел ОВ по корпусу 2',
        'due_label' => 'Срок результата',
        'assignee_label' => 'Руководитель / ответственный за распределение',
        'assignee_empty' => 'Выберите руководителя',
        'what_label' => 'Что нужно организовать',
        'what_placeholder' => 'Опишите результат, который должен быть распределён и собран руководителем',
        'why_label' => 'Контекст для распределения',
        'why_placeholder' => 'Укажите ограничения, исходные данные, приоритет и ожидаемый состав работ',
        'composition_meta' => 'раздел или зона ответственности',
        'source_label' => 'Исходная задача / основание',
    ],
    'bim_family_request' => [
        'task_type' => 'bim_family_request',
        'title' => 'Заявка на семейство ТИМ',
        'meta' => 'BIM / Revit',
        'hint' => 'Обычная задача для запроса, доработки или выпуска семейства ТИМ по проекту.',
        'title_placeholder' => 'Например: Подготовить семейство клапана КВУ',
        'due_label' => 'Срок готовности',
        'assignee_label' => 'Исполнитель заявки ТИМ',
        'assignee_empty' => 'Не назначен',
        'what_label' => 'Какое семейство нужно',
        'what_placeholder' => 'Укажите категорию, назначение, параметры и ожидаемый результат',
        'why_label' => 'Контекст модели',
        'why_placeholder' => 'Для какого раздела, модели или выдачи требуется семейство',
        'composition_meta' => 'раздел или модель, к которой относится заявка',
        'source_label' => 'Связанная задача / модель',
    ],
];
// Выдача тома и оценка трудозатрат доступны только с уровня руководителя отдела.
// Для новой задачи прячем эти интенты у ролей ниже; редактирование существующих не трогаем.
if (!$isEdit) {
    $intentUser = current_user() ?? [];
    if (!\App\Services\PermissionService::canCreateIssuance($intentUser)) {
        unset($taskIntents['issuance']);
    }
    if (!\App\Services\PermissionService::canAccessLaborEstimates($intentUser)) {
        unset($taskIntents['labor_estimate']);
    }
    if (!\App\Services\PermissionService::canCreateDelegationTask($intentUser)) {
        unset($taskIntents['delegate_department']);
    }
}
$levels = ['low', 'mid', 'high'];
$currentTaskType = (string) ($task['task_type'] ?? ($_GET['task_type'] ?? 'work'));
$currentTaskType = in_array($currentTaskType, $taskTypes, true) ? $currentTaskType : 'work';
$intentFromQuery = (string) ($_GET['task_intent'] ?? '');
$currentTaskIntent = match (true) {
    array_key_exists($intentFromQuery, $taskIntents) => $intentFromQuery,
    $currentTaskType === 'issuance' => 'issuance',
    $currentTaskType === 'labor_estimate' => 'labor_estimate',
    $currentTaskType === 'delegation' => 'delegate_department',
    $currentTaskType === 'bim_family_request' => 'bim_family_request',
    $currentTaskType === 'assignment' && str_starts_with((string) ($task['title'] ?? ($_GET['title'] ?? '')), 'Запрос') => 'assign_request',
    $currentTaskType === 'assignment' => 'assign_out',
    default => 'work',
};
$currentIntent = $taskIntents[$currentTaskIntent];
$currentTaskType = $currentIntent['task_type'];
$whenDueRaw = (string) ($smart['when_due'] ?? ($_GET['when_due'] ?? ($task['date_end'] ?? '')));
$whenDueValue = preg_match('/^\d{4}-\d{2}-\d{2}$/', $whenDueRaw) ? $whenDueRaw : '';
$ppItems = $accounting['pp'] ?? [];
$btpItems = $accounting['btp'] ?? [];
$currentSection = (string) ($task['section'] ?? ($task['discipline'] ?? ($_GET['section'] ?? ($_GET['discipline'] ?? ''))));
if ($currentSection !== '' && !isset($sectionOptions[$currentSection])) {
    $sectionOptions[$currentSection] = $currentSection;
}
$currentCostGroupCode = (string) ($task['cost_group_code'] ?? $currentSection);
$currentPpCodeId = (string) ($task['pp_code_id'] ?? ($_GET['pp_code_id'] ?? ''));
$currentBtpCodeId = (string) ($task['btp_code_id'] ?? ($_GET['btp_code_id'] ?? ''));
$currentProjectId = (int) ($task['project_id'] ?? ($_GET['project_id'] ?? 0));
$atlasContext = [
    'atlas_url' => (string) ($_GET['atlas_url'] ?? ''),
    'atlas_element_id' => (string) ($_GET['atlas_element_id'] ?? ''),
    'atlas_element_name' => (string) ($_GET['atlas_element_name'] ?? ''),
    'atlas_model_id' => (string) ($_GET['atlas_model_id'] ?? ''),
    'atlas_model_label' => (string) ($_GET['atlas_model_label'] ?? ''),
    'atlas_context' => (string) ($_GET['atlas_context'] ?? ''),
    'atlas_viewpoint' => (string) ($_GET['atlas_viewpoint'] ?? ($_GET['viewpoint'] ?? '')),
    'atlas_overlay' => (string) ($_GET['atlas_overlay'] ?? ''),
];
$hasAtlasContext = !$isEdit && implode('', $atlasContext) !== '';
$currentDependency = (string) ($task['parent_id'] ?? ($_GET['parent_id'] ?? ''));
if ($currentDependency === '' && !empty($smart['depends_on']) && ctype_digit((string) $smart['depends_on'])) {
    $currentDependency = (string) $smart['depends_on'];
}
$currentAssignee = $task['assignee_id'] ?? ($_GET['assignee_id'] ?? (in_array($currentTaskType, ['assignment', 'delegation'], true) ? '' : current_user()['id']));
$tagValue = implode(', ', array_map(static fn (array $tag): string => (string) $tag['name'], $taskTags ?? []));
$tagOptions = $tagOptions ?? [];
$participants = $participants ?? ['assignee' => [], 'coauthor' => [], 'observer' => []];
$selectedParticipantIds = [
    'assignee' => array_map('intval', array_column($participants['assignee'] ?? [], 'id')),
    'coauthor' => array_map('intval', array_column($participants['coauthor'] ?? [], 'id')),
    'observer' => array_map('intval', array_column($participants['observer'] ?? [], 'id')),
];
$participantDropdown = static function (string $name, string $label, array $selectedIds, array $users): void {
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));
    $summary = count($selectedIds) > 0 ? 'Выбрано: ' . count($selectedIds) : 'Выберите из списка';
    ?>
    <div class="participant-picker" data-participant-picker>
        <span><?= e($label) ?></span>
        <details>
            <summary>
                <span data-participant-summary data-empty-text="Выберите из списка"><?= e($summary) ?></span>
            </summary>
            <div class="participant-picker__menu">
                <?php foreach ($users as $item): ?>
                    <?php $checked = in_array((int) $item['id'], $selectedIds, true); ?>
                    <label>
                        <input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int) $item['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                        <span><?= e($item['name']) ?></span>
                        <?php if (!empty($item['department'])): ?><small><?= e($item['department']) ?></small><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
    <?php
};
$selectedProject = null;
foreach ($projects as $project) {
    if ((int) $project['id'] === $currentProjectId) {
        $selectedProject = $project;
        break;
    }
}
$projectLocked = !$isEdit && $selectedProject !== null;
$hasRequiredCustomFields = (bool) array_filter($customFields ?? [], static function (array $field) use ($currentProjectId, $currentTaskType): bool {
    $isBimField = str_starts_with((string) ($field['name'] ?? ''), 'bim_');
    if ($isBimField && $currentTaskType !== 'bim_family_request') {
        return false;
    }

    return (int) ($field['required'] ?? 0) === 1
        && ((int) ($field['project_id'] ?? 0) === 0 || (int) ($field['project_id'] ?? 0) === $currentProjectId);
});
$advancedOpen = $isEdit;
$customOpen = $isEdit || $hasRequiredCustomFields || $currentTaskType === 'bim_family_request';
$customFieldGroups = [
    'required' => ['title' => 'Обязательное', 'hint' => 'Поля, без которых задачу нельзя сохранить.', 'fields' => []],
    'bim' => ['title' => 'Заявка ТИМ', 'hint' => 'Поля реестра семейств BIM-отдела.', 'fields' => []],
    'links' => ['title' => 'Файлы и ссылки', 'hint' => 'Загрузки файлов пока нет: добавляйте ссылку на сетевую папку, модель или документ.', 'fields' => []],
    'project' => ['title' => 'Поля проекта', 'hint' => 'Показываются только для выбранного проекта.', 'fields' => []],
    'common' => ['title' => 'Дополнительно', 'hint' => 'Редко используемые признаки и служебные отметки.', 'fields' => []],
];
foreach ($customFields ?? [] as $field) {
    if (str_starts_with((string) ($field['name'] ?? ''), 'bim_')) {
        $customFieldGroups['bim']['fields'][] = $field;
    } elseif ((int) ($field['required'] ?? 0) === 1) {
        $customFieldGroups['required']['fields'][] = $field;
    } elseif (in_array((string) ($field['type'] ?? ''), ['link', 'links'], true)) {
        $customFieldGroups['links']['fields'][] = $field;
    } elseif ((int) ($field['project_id'] ?? 0) > 0) {
        $customFieldGroups['project']['fields'][] = $field;
    } else {
        $customFieldGroups['common']['fields'][] = $field;
    }
}
$requiredCustomCount = count($customFieldGroups['required']['fields']);
?>
<form id="task-form" class="task-create-form" method="post" action="<?= url($action) ?>" enctype="multipart/form-data" data-tour-surface="task-form" data-tour="task-form" data-task-intent-current="<?= e($currentTaskIntent) ?>" data-edit-mode="<?= $isEdit ? '1' : '0' ?>">
    <?= csrf_field() ?>
    <?php if ($drawerMode): ?><input type="hidden" name="drawer" value="1"><?php endif; ?>
    <input type="hidden" name="task_type" value="<?= e($currentTaskType) ?>" data-task-type-field>
    <?php if ($hasAtlasContext): ?>
        <?php foreach ($atlasContext as $atlasKey => $atlasValue): ?>
            <input type="hidden" name="<?= e($atlasKey) ?>" value="<?= e($atlasValue) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <section class="panel task-form-card task-form-card--primary">
        <div class="panel__head task-form-head">
            <div>
                <h2><?= $isEdit ? 'Редактирование задачи' : 'Быстрое создание задачи' ?></h2>
                <p><?= $isEdit ? 'Главные поля сверху, технические детали ниже.' : 'Сначала выберите действие, а форма подстроит поля под рабочий сценарий.' ?></p>
            </div>
        </div>

        <div class="task-required-note" role="note">
            <strong>Сначала обязательное</strong>
            <span>Поля с отметкой «Обязательно» должны быть заполнены до сохранения.</span>
        </div>

        <div class="task-kind" data-task-kind>
            <div class="task-form-section-title">
                <span>Тип задачи <em class="field-required">Обязательно</em></span>
                <b data-task-kind-meta><?= e($currentIntent['meta']) ?></b>
            </div>
            <div class="task-kind-grid">
                <?php foreach ($taskIntents as $intentKey => $card): ?>
                    <label class="task-kind-card">
                        <input
                            type="radio"
                            name="task_intent"
                            value="<?= e($intentKey) ?>"
                            required
                            <?= checked($currentTaskIntent === $intentKey) ?>
                            data-task-kind-control
                            data-task-type="<?= e($card['task_type']) ?>"
                            data-meta="<?= e($card['meta']) ?>"
                            data-title-placeholder="<?= e($card['title_placeholder']) ?>"
                            data-due-label="<?= e($card['due_label']) ?>"
                            data-assignee-label="<?= e($card['assignee_label']) ?>"
                            data-assignee-empty="<?= e($card['assignee_empty']) ?>"
                            data-what-label="<?= e($card['what_label']) ?>"
                            data-what-placeholder="<?= e($card['what_placeholder']) ?>"
                            data-why-label="<?= e($card['why_label']) ?>"
                            data-why-placeholder="<?= e($card['why_placeholder']) ?>"
                            data-composition-meta="<?= e($card['composition_meta']) ?>"
                            data-source-label="<?= e($card['source_label']) ?>"
                        >
                        <span>
                            <strong><?= e($card['title']) ?></strong>
                            <small><?= e($card['meta']) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php foreach ($taskIntents as $intentKey => $card): ?>
                <p class="task-kind-note" data-task-kind-panel="<?= e($intentKey) ?>"><?= e($card['hint']) ?></p>
            <?php endforeach; ?>
        </div>

        <?php if ($hasAtlasContext): ?>
            <div class="task-atlas-context">
                <strong>Контекст Атласа</strong>
                <span><?= e($atlasContext['atlas_element_name'] ?: $atlasContext['atlas_element_id'] ?: $atlasContext['atlas_model_label'] ?: 'Модель') ?></span>
                <?php if ($atlasContext['atlas_url'] !== ''): ?><a href="<?= e($atlasContext['atlas_url']) ?>" target="_blank" rel="noreferrer">Открыть</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="task-form-quick">
            <label class="task-form-wide" data-tour="task-form-title">
                <span>Название <em class="field-required">Обязательно</em></span>
                <input name="title" required placeholder="<?= e($currentIntent['title_placeholder']) ?>" value="<?= e($task['title'] ?? ($_GET['title'] ?? '')) ?>" data-intent-placeholder="title">
            </label>

            <?php if ($projectLocked): ?>
                <div class="task-project-context" data-tour="task-form-project">
                    <input type="hidden" name="project_id" id="task-project-select" value="<?= (int) $selectedProject['id'] ?>">
                    <span>Проект <em class="field-required">Обязательно</em></span>
                    <strong><?= e($selectedProject['code'] . ' · ' . $selectedProject['title']) ?></strong>
                    <a class="btn btn-outline btn-sm" href="<?= url('/tasks/new') ?>">Сменить</a>
                </div>
            <?php else: ?>
                <label data-tour="task-form-project">
                    <span>Проект <em class="field-required">Обязательно</em></span>
                    <select name="project_id" id="task-project-select" required>
                        <option value="">Выберите проект</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= (int) $project['id'] ?>"<?= selected($task['project_id'] ?? ($_GET['project_id'] ?? ''), $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label>
                <span><span data-intent-label="due"><?= e($currentIntent['due_label']) ?></span> <em class="field-required">Обязательно</em></span>
                <input type="date" name="when_due" required value="<?= e($whenDueValue) ?>">
            </label>

            <?php if ($isEdit): ?>
                <label class="task-form-wide">
                    <span>Причина переноса срока</span>
                    <textarea name="deadline_reason_text" rows="2" placeholder="Если меняете срок, проверяющий увидит эту причину перед подтверждением"></textarea>
                </label>
            <?php endif; ?>

            <label data-tour="task-form-assignee">
                <span><span data-intent-label="assignee"><?= e($currentIntent['assignee_label']) ?></span> <em class="field-required">Обязательно</em></span>
                <select name="assignee_id" required data-task-assignee>
                    <option value="" data-intent-empty="assignee"><?= e($currentIntent['assignee_empty']) ?></option>
                    <?php foreach ($users as $item): ?>
                        <?php
                        $vacationFrom = (string) ($item['vacation_date_from'] ?? '');
                        $vacationTo = (string) ($item['vacation_date_to'] ?? '');
                        $substituteName = (string) ($item['vacation_substitute_name'] ?? '');
                        $availabilitySuffix = $vacationFrom !== ''
                            ? ' · отпуск ' . format_date($vacationFrom) . '–' . format_date($vacationTo) . ' · замена ' . $substituteName
                            : '';
                        ?>
                        <option
                            value="<?= (int) $item['id'] ?>"
                            data-cost-group-code="<?= e($item['cost_group_code'] ?? '') ?>"
                            data-vacation-from="<?= e($vacationFrom) ?>"
                            data-vacation-to="<?= e($vacationTo) ?>"
                            data-vacation-substitute-id="<?= (int) ($item['vacation_substitute_user_id'] ?? 0) ?>"
                            data-vacation-substitute-name="<?= e($substituteName) ?>"
                            <?= selected($currentAssignee, $item['id']) ?>
                        ><?= e($item['name'] . $availabilitySuffix) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="task-assignee-vacation form-grid__full" data-task-assignee-vacation hidden>
                <span data-task-assignee-vacation-text></span>
                <button class="btn btn-outline btn-sm" type="button" data-task-use-vacation-substitute>Назначить замену</button>
            </div>

            <label>
                <span>Код раздела</span>
                <input value="<?= e($currentCostGroupCode) ?>" readonly data-task-cost-group-code placeholder="Заполнится по исполнителю">
                <small class="field-hint" data-task-cost-group-hint>Берётся из стоимостной группы исполнителя.</small>
            </label>

            <label>
                <span>Важность <em class="field-required">Обязательно</em></span>
                <select name="priority" required>
                    <?php if (!$isEdit): ?><option value="">Выберите важность</option><?php endif; ?>
                    <?php foreach ($levels as $level): ?>
                        <option value="<?= e($level) ?>"<?= selected($task['priority'] ?? '', $level) ?>><?= e(priority_label($level)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Срочность <em class="field-required">Обязательно</em></span>
                <select name="urgency" required>
                    <?php if (!$isEdit): ?><option value="">Выберите срочность</option><?php endif; ?>
                    <?php foreach ($levels as $level): ?>
                        <option value="<?= e($level) ?>"<?= selected($task['urgency'] ?? '', $level) ?>><?= e(priority_label($level)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>План, ч <em class="field-required">Обязательно</em></span>
                <input type="number" min="0.25" step="0.25" name="planned_hours" required value="<?= e($task['planned_hours'] ?? ($_GET['planned_hours'] ?? '')) ?>">
            </label>

            <label class="task-form-wide" data-tour="task-form-smart">
                <span><span data-intent-label="what"><?= e($currentIntent['what_label']) ?></span> <em class="field-required">Обязательно</em></span>
                <textarea name="what" rows="3" required placeholder="<?= e($currentIntent['what_placeholder']) ?>" data-intent-placeholder="what"><?= e($smart['what'] ?? ($_GET['what'] ?? '')) ?></textarea>
            </label>

            <?php if (!$isEdit): ?>
                <label class="task-attachment-picker task-form-wide" data-task-attachment-picker>
                    <span>Файлы и фото <em class="field-optional">Необязательно</em></span>
                    <span class="task-attachment-picker__control">
                        <strong>Выбрать файлы</strong>
                        <small>Фото, PDF, офисные и проектные файлы · до 20 МБ каждый</small>
                    </span>
                    <input type="file" name="attachments[]" multiple accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.7z,.dwg,.dxf,.ifc,.ifczip,.frag,.nwc,.nwd,.nwf,.rvt" data-task-attachment-input>
                    <span class="task-attachment-picker__selection" data-task-attachment-selection>Файлы не выбраны</span>
                    <span class="task-attachment-picker__preview" data-task-attachment-preview aria-live="polite"></span>
                </label>
            <?php endif; ?>

            <div class="task-form-optional-divider task-form-wide"><span>Дополнительно</span></div>

            <label data-tour="task-form-reviewer" data-intent-panel="work assign_out assign_request issuance labor_estimate bim_family_request" data-intent-edit-strict="1">
                <span>Проверяющий результата</span>
                <select name="reviewer_id">
                    <option value="">Автоматически</option>
                    <?php foreach ($users as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"<?= selected($task['reviewer_id'] ?? '', $item['id']) ?>><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">Для сдачи результата. Если не выбран, система назначит автоматически.</small>
            </label>

            <?php $participantDropdown('participant_coauthor_ids', 'Соавторы', $selectedParticipantIds['coauthor'], $users); ?>
            <?php $participantDropdown('participant_observer_ids', 'Согласующие / наблюдатели', $selectedParticipantIds['observer'], $users); ?>

            <label class="task-form-wide">
                <span data-intent-label="why"><?= e($currentIntent['why_label']) ?></span>
                <textarea name="why" rows="2" placeholder="<?= e($currentIntent['why_placeholder']) ?>" data-intent-placeholder="why"><?= e($smart['why'] ?? ($_GET['why'] ?? '')) ?></textarea>
            </label>
        </div>

        <div class="task-form-composition" data-intent-panel="work assign_out assign_request issuance labor_estimate delegate_department bim_family_request">
            <div class="task-form-section-title">
                <span>Классификация задачи</span>
            </div>
            <div class="task-form-composition__grid">
                <label class="task-form-wide">
                    <span>Раздел <em class="field-required">Обязательно</em></span>
                    <select name="section" required>
                        <option value="">Выберите раздел</option>
                        <?php foreach ($sectionOptions as $sectionValue => $sectionLabel): ?>
                            <?php $sectionCaption = $sectionLabel !== $sectionValue ? $sectionValue . ' · ' . $sectionLabel : $sectionValue; ?>
                            <option value="<?= e($sectionValue) ?>"<?= selected($currentSection, $sectionValue) ?>><?= e($sectionCaption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">Единственная обязательная привязка задачи к проектному содержанию.</small>
                </label>

                <label>
                    <span>ПП / сделка</span>
                    <select name="pp_code_id" data-project-accounting-pp>
                        <option value="">Не выбрана</option>
                        <?php $ppKnown = $currentPpCodeId === ''; ?>
                        <?php foreach ($ppItems as $item): ?>
                            <?php $ppKnown = $ppKnown || (string) $item['id'] === $currentPpCodeId; ?>
                            <option value="<?= (int) $item['id'] ?>" data-project="<?= (int) $item['project_id'] ?>"<?= selected($currentPpCodeId, $item['id']) ?>>
                                <?= e($item['code']) ?><?= !empty($item['title']) ? ' · ' . e($item['title']) : '' ?><?= (int) $item['project_id'] !== $currentProjectId && !empty($item['project_code']) ? ' · ' . e($item['project_code']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$ppKnown): ?><option value="<?= e($currentPpCodeId) ?>" selected>ПП #<?= e($currentPpCodeId) ?></option><?php endif; ?>
                    </select>
                </label>

                <label>
                    <span>БТП / статья списания</span>
                    <select name="btp_code_id" data-project-accounting-btp>
                        <option value="">Не выбрана</option>
                        <?php $btpKnown = $currentBtpCodeId === ''; ?>
                        <?php foreach ($btpItems as $item): ?>
                            <?php $btpKnown = $btpKnown || (string) $item['id'] === $currentBtpCodeId; ?>
                            <option value="<?= (int) $item['id'] ?>" data-project="<?= (int) $item['project_id'] ?>" data-pp="<?= (int) $item['pp_code_id'] ?>"<?= selected($currentBtpCodeId, $item['id']) ?>>
                                <?= e($item['code']) ?><?= !empty($item['title']) ? ' · ' . e($item['title']) : '' ?> · ПП <?= e($item['pp_code']) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$btpKnown): ?><option value="<?= e($currentBtpCodeId) ?>" selected>БТП #<?= e($currentBtpCodeId) ?></option><?php endif; ?>
                    </select>
                </label>

                <label class="task-form-wide">
                    <span>БТП вручную</span>
                    <input name="btp" value="<?= e($task['btp'] ?? '') ?>" placeholder="Если статьи ещё нет в справочнике">
                </label>

                <label class="task-form-wide">
                    <span data-intent-label="source"><?= e($currentIntent['source_label']) ?></span>
                    <select name="dependency_task_id" data-task-dependency-select>
                        <option value="">Нет связи</option>
                        <?php foreach (($relationTasks ?? []) as $relationTask): ?>
                            <option value="<?= (int) $relationTask['id'] ?>" data-project="<?= (int) $relationTask['project_id'] ?>"<?= selected($currentDependency, $relationTask['id']) ?>>
                                #<?= (int) $relationTask['id'] ?> · <?= e($relationTask['project_code']) ?> · <?= e($relationTask['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </section>

    <details class="panel task-form-fold"<?= $advancedOpen ? ' open' : '' ?>>
        <summary class="panel__head task-form-fold__summary">
            <h2>Детали и связи</h2>
            <span><?= $advancedOpen ? 'открыто' : 'по необходимости' ?></span>
        </summary>
        <div class="form-grid task-form-details">
            <?php if ($isEdit): ?>
                <label>
                    <span>Статус</span>
                    <?php if ($workflowManagedStatus): ?>
                        <input type="hidden" name="status" value="<?= e($task['status'] ?? '') ?>">
                        <strong class="form-readonly-value"><?= e(task_status_label($task['status'] ?? '')) ?></strong>
                        <small>Статус меняется только действием по маршруту задачи.</small>
                    <?php else: ?>
                        <select name="status">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>"<?= selected($task['status'] ?? 'new', $status) ?>><?= e(task_status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </label>
            <?php else: ?>
                <input type="hidden" name="status" value="new">
            <?php endif; ?>

            <?php if ($isEdit): ?>
                <input type="hidden" name="date_start" value="<?= e($task['date_start'] ?? '') ?>">
            <?php endif; ?>

            <?php if ($isEdit): ?>
                <label>
                    <span>Прогресс, %</span>
                    <input type="number" min="0" max="100" name="progress" value="<?= e($task['progress'] ?? 0) ?>">
                </label>
            <?php else: ?>
                <input type="hidden" name="progress" value="0">
            <?php endif; ?>

            <label class="form-grid__full">
                <span>Теги</span>
                <input name="tags" list="task-tag-options" placeholder="выдача, коллизии, срочно" value="<?= e($tagValue) ?>">
                <datalist id="task-tag-options">
                    <?php foreach ($tagOptions as $tagOption): ?>
                        <option value="<?= e($tagOption['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>

        </div>
    </details>

    <?php if ($customFields): ?>
        <details class="panel task-form-fold task-form-fold--custom" data-custom-fields-shell<?= $customOpen ? ' open' : '' ?>>
            <summary class="panel__head task-form-fold__summary">
                <h2>Дополнительные поля</h2>
                <span><?= $requiredCustomCount > 0 ? $requiredCustomCount . ' обязательных' : count($customFields) . ' полей' ?></span>
            </summary>
            <div class="task-custom-fields">
                <?php foreach ($customFieldGroups as $groupKey => $group): ?>
                    <?php if (!$group['fields']): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <section class="custom-field-group" data-custom-group="<?= e($groupKey) ?>"<?= $groupKey === 'bim' ? ' data-intent-panel="bim_family_request" data-intent-edit-strict="1"' : '' ?>>
                        <header class="custom-field-group__head">
                            <strong><?= e($group['title']) ?></strong>
                            <span><?= e($group['hint']) ?></span>
                        </header>
                        <div class="custom-field-grid custom-field-grid--form">
                            <?php foreach ($group['fields'] as $field): ?>
                                <?php
                                $value = $customValues[$field['id']] ?? '';
                                $fieldProjectId = (int) ($field['project_id'] ?? 0);
                                $fieldProjectCode = (string) ($field['project_code'] ?? '');
                                ?>
                                <label class="custom-field-input" data-custom-project="<?= $fieldProjectId ?>">
                                    <span>
                                        <?= e($field['label']) ?><?php if ((int) $field['required']): ?> <em class="field-required">Обязательно</em><?php endif; ?>
                                        <?php if ($fieldProjectCode !== ''): ?><em><?= e($fieldProjectCode) ?></em><?php endif; ?>
                                    </span>
                                    <?php if ($field['type'] === 'select'): ?>
                                        <?php $options = json_decode((string) $field['options'], true) ?: []; ?>
                                        <select name="custom_<?= (int) $field['id'] ?>"<?= (int) $field['required'] ? ' required' : '' ?>>
                                            <option value=""></option>
                                            <?php foreach ($options as $option): ?>
                                                <option value="<?= e($option) ?>"<?= selected($value, $option) ?>><?= e($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($field['type'] === 'user'): ?>
                                        <select name="custom_<?= (int) $field['id'] ?>"<?= (int) $field['required'] ? ' required' : '' ?>>
                                            <option value=""></option>
                                            <?php foreach ($users as $item): ?>
                                                <option value="<?= (int) $item['id'] ?>"<?= selected($value, $item['id']) ?>><?= e($item['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($field['type'] === 'bool'): ?>
                                        <span class="custom-bool">
                                            <input type="checkbox" name="custom_<?= (int) $field['id'] ?>" value="1"<?= checked($value) ?>>
                                            <b>Да</b>
                                        </span>
                                    <?php elseif ($field['type'] === 'link'): ?>
                                        <?php $entries = custom_link_entries($value); $entry = $entries[0] ?? ['label' => '', 'url' => '']; ?>
                                        <div class="link-field">
                                            <input name="custom_<?= (int) $field['id'] ?>[label]" placeholder="Подпись" value="<?= e($entry['label']) ?>">
                                            <input name="custom_<?= (int) $field['id'] ?>[url]" placeholder="\\fileserver\share\folder или file://fileserver/share/folder"<?= (int) $field['required'] ? ' required' : '' ?> value="<?= e($entry['url']) ?>">
                                        </div>
                                    <?php elseif ($field['type'] === 'links'): ?>
                                        <?php $entries = custom_link_entries($value); $entries = $entries ?: [['label' => '', 'url' => '']]; ?>
                                        <div class="link-list" data-link-list data-field-name="custom_<?= (int) $field['id'] ?>">
                                            <?php foreach ($entries as $index => $entry): ?>
                                                <div class="link-row">
                                                    <input name="custom_<?= (int) $field['id'] ?>[label][]" placeholder="Подпись" value="<?= e($entry['label']) ?>">
                                                    <input name="custom_<?= (int) $field['id'] ?>[url][]" placeholder="\\fileserver\share\folder или file://fileserver/share/folder"<?= (int) $field['required'] && $index === 0 ? ' required' : '' ?> value="<?= e($entry['url']) ?>">
                                                    <button class="btn btn-outline" type="button" data-remove-link-row>Убрать</button>
                                                </div>
                                            <?php endforeach; ?>
                                            <button class="btn btn-outline" type="button" data-add-link-row>Добавить ссылку</button>
                                        </div>
                                    <?php else: ?>
                                        <input type="<?= $field['type'] === 'date' ? 'date' : ($field['type'] === 'number' ? 'number' : 'text') ?>" name="custom_<?= (int) $field['id'] ?>" value="<?= e($value) ?>"<?= (int) $field['required'] ? ' required' : '' ?>>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <div class="task-form-actions">
        <button class="btn btn--red" type="submit"><?= $isEdit ? 'Сохранить изменения' : 'Добавить задачу' ?></button>
    </div>
</form>
