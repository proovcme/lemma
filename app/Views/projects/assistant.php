<?php
$isArchived = (bool) ($isArchived ?? false);
$projectId = (int) $project['id'];
$canViewProjectFinance = (bool) ($canViewProjectFinance ?? false);
$taskUrl = static function (array $query) use ($projectId): string {
    $query = ['project_id' => $projectId] + $query;
    return url('/tasks/new') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$canIssue = \App\Services\PermissionService::canCreateIssuance(current_user() ?? []);
$actions = [
    [
        'group' => 'Работа',
        'items' => array_values(array_filter([
            ['title' => 'Поставить задачу', 'meta' => 'Обычная работа по проекту', 'href' => $taskUrl(['task_intent' => 'work']), 'tag' => 'Задача'],
            ['title' => 'Выдать задание', 'meta' => 'Передать работу или данные смежнику', 'href' => $taskUrl(['task_intent' => 'assign_out']), 'tag' => 'Задание'],
            ['title' => 'Запросить задание', 'meta' => 'Попросить смежника подготовить входящие данные', 'href' => $taskUrl(['task_intent' => 'assign_request']), 'tag' => 'Запрос'],
            $canIssue ? ['title' => 'Подготовить выдачу', 'meta' => 'Том, комплект или раздел на приемку ГИПом', 'href' => $taskUrl(['task_intent' => 'issuance']), 'tag' => 'Выдача'] : null,
            ['title' => 'Заявка на семейство ТИМ', 'meta' => 'Запросить или доработать BIM/Revit-семейство', 'href' => $taskUrl(['task_intent' => 'bim_family_request']), 'tag' => 'ТИМ'],
        ])),
    ],
    [
        'group' => 'Проблемы',
        'items' => [
            ['title' => 'Открыть вопрос', 'meta' => 'Вопрос по проекту, разделу, решению или согласованию', 'href' => url('/projects/' . $projectId . '/issues#tab-add'), 'tag' => 'Вопрос'],
            ['title' => 'Зафиксировать блокер ИД', 'meta' => 'Не хватает исходных данных, это блокирует задачи', 'href' => url('/projects/' . $projectId . '/data#tab-add'), 'tag' => 'Блокер'],
        ],
    ],
    [
        'group' => 'Структура',
        'items' => [
            ['title' => 'Добавить исходные данные', 'meta' => 'Запрос, получение, влияние и ответственный по ИД', 'href' => url('/projects/' . $projectId . '/data#tab-add'), 'tag' => 'ИД'],
            ['title' => 'Добавить раздел или том', 'meta' => 'Связать проектную структуру с задачей и сроком', 'href' => url('/projects/' . $projectId . '/sections#tab-add'), 'tag' => 'Том'],
        ],
    ],
];
?>
<?php if ($isArchived): ?>
    <div class="archive-banner">
        Проект в архиве · <?= e(format_date($project['archived_at'] ?? '') ?: 'дата не указана') ?>
    </div>
<?php endif; ?>

<section class="project-head project-assistant-head">
    <div>
        <span class="muted"><?= e($project['stage']) ?> · <?= e($project['object']) ?></span>
        <h2><?= e($project['code']) ?> · Помощник проекта</h2>
    </div>
</section>

<?php $projectNavActive = ''; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<section class="project-assistant">
    <div class="project-assistant__intro">
        <span>Выберите действие</span>
        <h2>Что нужно сделать по проекту?</h2>
    </div>

    <?php foreach ($actions as $group): ?>
        <section class="assistant-group">
            <h3><?= e($group['group']) ?></h3>
            <div class="assistant-action-grid">
                <?php foreach ($group['items'] as $item): ?>
                    <?php if ($isArchived): ?>
                        <span class="assistant-action is-disabled">
                            <b><?= e($item['tag']) ?></b>
                            <strong><?= e($item['title']) ?></strong>
                            <small><?= e($item['meta']) ?></small>
                        </span>
                    <?php else: ?>
                        <a class="assistant-action" href="<?= e($item['href']) ?>">
                            <b><?= e($item['tag']) ?></b>
                            <strong><?= e($item['title']) ?></strong>
                            <small><?= e($item['meta']) ?></small>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</section>
