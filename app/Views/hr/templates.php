<?php
$questionTypes = $questionTypes ?? [];
$questionScopes = $questionScopes ?? \App\Services\PerformanceReviewService::QUESTION_SCOPES;
$templates = $templates ?? [];
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">HR / конструктор</span>
        <h2>Шаблоны Performance Review</h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/hr') ?>">Performance Review</a>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/hr/templates') ?>">
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full">
        <h2>Новый шаблон</h2>
        <button class="btn btn--red" type="submit">Создать</button>
    </div>
    <label><span>Название</span><input name="title" required></label>
    <label class="form-grid__full"><span>Описание</span><textarea name="description" rows="2"></textarea></label>
</form>

<?php foreach ($templates as $template): ?>
    <section class="panel sheet-panel">
        <div class="panel__head">
            <div>
                <h2><?= e($template['title']) ?></h2>
                <span class="muted"><?= e($template['description'] ?? '') ?></span>
            </div>
            <span class="muted"><?= count($template['questions'] ?? []) ?> вопросов</span>
        </div>
        <form class="form-grid" method="post" action="<?= url('/hr/templates/questions') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
            <label><span>Ключ</span><input name="question_key" placeholder="опционально"></label>
            <label><span>Тип</span><select name="question_type"><?php foreach ($questionTypes as $type => $label): ?><option value="<?= e($type) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Стадия</span><select name="answer_scope"><?php foreach ($questionScopes as $scope => $label): ?><option value="<?= e($scope) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Порядок</span><input type="number" name="sort_order" value="100"></label>
            <label class="form-checkbox"><input type="checkbox" name="is_required" value="1"> <span>Обязательный</span></label>
            <label class="form-grid__full"><span>Вопрос</span><textarea name="label" rows="2" required></textarea></label>
            <button class="btn btn--red btn-sm" type="submit">Добавить вопрос</button>
        </form>
        <?php foreach (($template['questions'] ?? []) as $q): ?>
            <form id="question-edit-<?= (int) $q['id'] ?>" method="post" action="<?= url('/hr/templates/questions/' . (int) $q['id']) ?>">
                <?= csrf_field() ?>
            </form>
        <?php endforeach; ?>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead><tr><th>Порядок</th><th>Вопрос</th><th>Стадия</th><th>Тип</th><th>Обяз.</th><th></th></tr></thead>
                <tbody>
                <?php foreach (($template['questions'] ?? []) as $q): ?>
                    <?php $editFormId = 'question-edit-' . (int) $q['id']; ?>
                    <tr>
                        <td><input form="<?= e($editFormId) ?>" type="number" name="sort_order" value="<?= (int) $q['sort_order'] ?>" style="width: 5.5rem"></td>
                        <td>
                            <textarea form="<?= e($editFormId) ?>" name="label" rows="2" required><?= e($q['label']) ?></textarea>
                            <small><?= e($q['question_key']) ?></small>
                        </td>
                        <td>
                            <select form="<?= e($editFormId) ?>" name="answer_scope">
                                <?php foreach ($questionScopes as $scope => $label): ?>
                                    <option value="<?= e($scope) ?>"<?= selected((string) ($q['answer_scope'] ?? 'both'), $scope) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select form="<?= e($editFormId) ?>" name="question_type">
                                <?php foreach ($questionTypes as $type => $label): ?>
                                    <option value="<?= e($type) ?>"<?= selected((string) $q['question_type'], $type) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><label class="form-checkbox"><input form="<?= e($editFormId) ?>" type="checkbox" name="is_required" value="1"<?= checked((int) ($q['is_required'] ?? 0), 1) ?>> <span>да</span></label></td>
                        <td><button form="<?= e($editFormId) ?>" class="btn btn-outline btn-sm" type="submit">Сохранить</button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($template['questions'])): ?><tr><td colspan="6" class="muted">Вопросов пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
