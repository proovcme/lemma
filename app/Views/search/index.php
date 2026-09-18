<?php
$query = (string) ($query ?? '');
$results = $results ?? ['tasks' => [], 'projects' => [], 'records' => [], 'users' => []];
$total = array_sum(array_map('count', $results));
?>
<section class="search-page">
    <form class="search-page__form" method="get" action="<?= url('/search') ?>">
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="Задача, проект, вопрос, раздел..." autofocus>
        <button class="btn btn-red" type="submit">Найти</button>
    </form>

    <?php if ($query === ''): ?>
        <div class="empty-state">
            <strong>Ищем по рабочей системе</strong>
            <span>Задачи, проекты, теги, комментарии, вкладки проекта и пользователи для администраторов.</span>
        </div>
    <?php elseif (mb_strlen($query, 'UTF-8') < (int) $minQueryLength): ?>
        <div class="empty-state">
            <strong>Слишком короткий запрос</strong>
            <span>Введите минимум <?= (int) $minQueryLength ?> символа.</span>
        </div>
    <?php elseif ($total === 0): ?>
        <div class="empty-state">
            <strong>Ничего не найдено</strong>
            <span>Попробуйте код проекта, номер задачи, раздел или часть формулировки.</span>
        </div>
    <?php else: ?>
        <div class="search-summary">Найдено: <?= (int) $total ?></div>

        <?php if (!empty($results['tasks'])): ?>
            <section class="search-group">
                <h2>Задачи</h2>
                <div class="search-results">
                    <?php foreach ($results['tasks'] as $task): ?>
                        <a class="search-result" href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link>
                            <span class="search-result__type"><?= e($task['project_code']) ?> · <?= e(task_status_label($task['status'])) ?></span>
                            <strong>#<?= (int) $task['id'] ?> <?= e($task['title']) ?></strong>
                            <small><?= e(implode(' · ', array_filter([$task['discipline'] ?? '', $task['section'] ?? '', $task['assignee_name'] ?? '', format_date($task['date_end'] ?? '')]))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($results['projects'])): ?>
            <section class="search-group">
                <h2>Проекты</h2>
                <div class="search-results">
                    <?php foreach ($results['projects'] as $project): ?>
                        <a class="search-result" href="<?= url('/projects/' . (int) $project['id']) ?>">
                            <span class="search-result__type"><?= e($project['stage']) ?> · <?= e($project['status']) ?></span>
                            <strong><?= e($project['code']) ?> · <?= e($project['title']) ?></strong>
                            <small><?= e(implode(' · ', array_filter([$project['object'] ?? '', $project['gip_name'] ?? '', $project['rp_name'] ?? '']))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($results['records'])): ?>
            <section class="search-group">
                <h2>Проектные таблицы</h2>
                <div class="search-results">
                    <?php foreach ($results['records'] as $record): ?>
                        <a class="search-result" href="<?= url($record['href']) ?>">
                            <span class="search-result__type"><?= e($record['label']) ?> · <?= e($record['project_code']) ?></span>
                            <strong><?= e($record['result_title']) ?></strong>
                            <small><?= e(implode(' · ', array_filter([$record['result_meta'] ?? '', $record['project_title'] ?? '']))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($results['users'])): ?>
            <section class="search-group">
                <h2>Пользователи</h2>
                <div class="search-results">
                    <?php foreach ($results['users'] as $foundUser): ?>
                        <a class="search-result" href="<?= url('/admin/users') ?>">
                            <span class="search-result__type"><?= e($foundUser['department']) ?> · <?= e(role_label($foundUser['role'])) ?></span>
                            <strong><?= e($foundUser['name']) ?></strong>
                            <small><?= e($foundUser['tab_number']) ?> · <?= e($foundUser['email']) ?><?= (int) $foundUser['is_active'] === 0 ? ' · выключен' : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
