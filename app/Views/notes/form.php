<?php
$isEdit = is_array($note ?? null);
$action = $isEdit ? '/notes/' . (int) $note['id'] : '/notes/new';
$selectedProject = (string) ($note['project_id'] ?? ($_GET['project_id'] ?? ''));
$selectedColor = (string) ($note['color'] ?? 'yellow');
$isConverted = $isEdit && (string) ($note['status'] ?? 'active') === 'converted';
?>
<section class="notes-editor">
    <form id="note-form" class="card note-editor-card" method="post" action="<?= url($action) ?>">
        <?= csrf_field() ?>
        <label>
            Название
            <input name="title" value="<?= e($note['title'] ?? '') ?>" required maxlength="255" placeholder="Короткий заголовок">
        </label>
        <label>
            Текст заметки
            <textarea name="body" rows="12" required placeholder="Запишите мысль, список, контекст или черновик задачи"><?= e($note['body'] ?? '') ?></textarea>
        </label>
        <div class="form-grid">
            <label>
                Проект
                <select name="project_id">
                    <option value="">Без проекта</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int) $project['id'] ?>"<?= selected($selectedProject, (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Цвет
                <select name="color">
                    <?php foreach (['yellow' => 'Жёлтый', 'blue' => 'Синий', 'green' => 'Зелёный', 'red' => 'Красный', 'gray' => 'Серый'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($selectedColor, $key) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox-line">
                <input type="checkbox" name="pinned" value="1"<?= !empty($note['pinned']) ? ' checked' : '' ?>>
                Закрепить сверху
            </label>
        </div>
        <?php if ($isConverted): ?>
            <div class="alert alert-info">Эта заметка уже превращена в задачу #<?= (int) ($note['converted_task_id'] ?? 0) ?> и доступна только как история.</div>
        <?php endif; ?>
    </form>

    <?php if ($isEdit): ?>
        <div class="note-actions-stack">
            <?php if (!$isConverted): ?>
                <section class="card">
                    <h2>Сделать задачей</h2>
                    <form method="post" action="<?= url('/notes/' . (int) $note['id'] . '/convert') ?>">
                        <?= csrf_field() ?>
                        <label>
                            Проект
                            <select name="project_id" required>
                                <option value="">Выберите проект</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int) $project['id'] ?>"<?= selected($selectedProject, (string) $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Исполнитель
                            <select name="assignee_id">
                                <?php foreach ($users as $taskUser): ?>
                                    <option value="<?= (int) $taskUser['id'] ?>"<?= selected((string) (current_user()['id'] ?? ''), (string) $taskUser['id']) ?>><?= e($taskUser['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="form-grid">
                            <label>
                                Тип
                                <select name="task_type">
                                    <option value="work">Работа</option>
                                    <option value="assignment">Задание</option>
                                    <option value="bim_family_request">Заявка ТИМ</option>
                                </select>
                            </label>
                            <label>
                                Срок
                                <input type="date" name="date_end">
                            </label>
                            <label>
                                План, ч
                                <input type="number" name="planned_hours" min="0" step="0.5">
                            </label>
                        </div>
                        <button class="btn btn-red" type="submit">Создать задачу</button>
                    </form>
                </section>
                <section class="card">
                    <h2>Статус</h2>
                    <form method="post" action="<?= url('/notes/' . (int) $note['id'] . '/status') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="<?= ($note['status'] ?? 'active') === 'archived' ? 'active' : 'archived' ?>">
                        <button class="btn btn-outline" type="submit"><?= ($note['status'] ?? 'active') === 'archived' ? 'Вернуть из архива' : 'В архив' ?></button>
                    </form>
                </section>
            <?php else: ?>
                <section class="card">
                    <h2>Связанная задача</h2>
                    <a class="btn btn-outline" href="<?= url('/tasks/' . (int) $note['converted_task_id']) ?>">Открыть задачу #<?= (int) $note['converted_task_id'] ?></a>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
