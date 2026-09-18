<?php
$isNew = !$document;
$documentId = (int) ($document['id'] ?? 0);
$draftTitle = (string) ($document['draft_title'] ?? $document['title'] ?? '');
$draftSummary = (string) ($document['draft_summary'] ?? $document['summary'] ?? '');
$draftBody = (string) ($document['draft_body_html'] ?? $document['body_html'] ?? '<h2>Новый раздел</h2><p>Начните писать здесь.</p>');
$draftPinned = (int) ($document['draft_is_pinned'] ?? $document['is_pinned'] ?? 0) === 1;
$sortOrder = (int) ($document['sort_order'] ?? 100);
$formAction = $isNew ? '/knowledge/documents/new' : '/knowledge/documents/' . $documentId . '/draft';
?>

<form id="knowledge-editor-form" class="knowledge-editor-form" method="post" action="<?= url($formAction) ?>"
      data-knowledge-editor-form<?= !$isNew ? ' data-autosave-url="' . e(url('/knowledge/documents/' . $documentId . '/autosave')) . '"' : '' ?>>
    <?= csrf_field() ?>
    <section class="panel knowledge-document-settings">
        <div class="form-grid">
            <label class="field field--wide">
                <span>Название документа</span>
                <input type="text" name="title" value="<?= e($draftTitle) ?>" maxlength="240" required>
            </label>
            <label class="field field--wide">
                <span>Краткое описание</span>
                <input type="text" name="summary" value="<?= e($draftSummary) ?>" maxlength="600" placeholder="Одно предложение о содержании">
            </label>
            <label class="field">
                <span>Папка</span>
                <select name="folder_id">
                    <option value="">Корень базы знаний</option>
                    <?php foreach ($folderOptions as $folder): ?>
                        <option value="<?= (int) $folder['id'] ?>"<?= $selectedFolderId === (int) $folder['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $folder['depth']) . $folder['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Порядок</span>
                <input type="number" name="sort_order" value="<?= $sortOrder ?>" min="0" max="100000">
            </label>
            <label class="check-row field--wide">
                <input type="checkbox" name="is_pinned" value="1"<?= $draftPinned ? ' checked' : '' ?>>
                <span>Закрепить документ в начале базы знаний</span>
            </label>
        </div>
    </section>

    <section class="panel knowledge-editor" data-knowledge-editor>
        <div class="knowledge-editor__toolbar" role="toolbar" aria-label="Форматирование текста">
            <label>
                <span class="sr-only">Стиль текста</span>
                <select data-editor-block aria-label="Стиль текста">
                    <option value="p">Обычный текст</option>
                    <option value="h2">Заголовок</option>
                    <option value="h3">Подзаголовок</option>
                </select>
            </label>
            <button type="button" data-editor-command="bold" aria-label="Полужирный"><strong>Ж</strong></button>
            <button type="button" data-editor-command="italic" aria-label="Курсив"><em>К</em></button>
            <button type="button" data-editor-command="insertUnorderedList" aria-label="Маркированный список">• Список</button>
            <button type="button" data-editor-command="insertOrderedList" aria-label="Нумерованный список">1. Список</button>
            <button type="button" data-editor-link aria-label="Добавить ссылку">Ссылка</button>
            <button type="button" data-editor-callout aria-label="Добавить важный блок">Важно</button>
            <button type="button" data-editor-table aria-label="Добавить таблицу">Таблица</button>
            <button type="button" data-editor-command="undo" aria-label="Отменить">↶</button>
            <button type="button" data-editor-command="redo" aria-label="Повторить">↷</button>
        </div>
        <div class="knowledge-editor__surface" contenteditable="true" role="textbox" aria-label="Текст документа" aria-multiline="true" data-editor-surface><?= $draftBody ?></div>
        <textarea name="body_html" data-editor-input hidden><?= e($draftBody) ?></textarea>
        <div class="knowledge-editor__status" aria-live="polite" data-editor-status><?= $isNew ? 'Сохраните первый черновик, чтобы включить автосохранение.' : 'Черновик готов к редактированию.' ?></div>
    </section>

    <div class="knowledge-editor-actions">
        <a class="btn btn-outline" href="<?= url($isNew ? '/knowledge' : '/knowledge/documents/' . $documentId) ?>">Отмена</a>
        <button class="btn btn-outline" type="submit"><?= $isNew ? 'Создать черновик' : 'Сохранить черновик' ?></button>
        <?php if (!$isNew): ?>
            <button class="btn btn--red" type="submit" formaction="<?= url('/knowledge/documents/' . $documentId . '/publish') ?>">Опубликовать</button>
        <?php endif; ?>
    </div>
</form>

<?php if (!$isNew): ?>
    <details class="panel knowledge-revisions">
        <summary><span>История версий</span><small><?= count($revisions) ?> опубликованных версий</small></summary>
        <?php if ($revisions): ?>
            <div class="knowledge-revisions__list">
                <?php foreach ($revisions as $revision): ?>
                    <div class="knowledge-revision-row">
                        <div><strong>Версия <?= (int) $revision['version_no'] ?></strong><small><?= e(date('d.m.Y H:i', strtotime((string) $revision['created_at']))) ?> · <?= e($revision['created_by_name'] ?: 'Система') ?></small></div>
                        <form method="post" action="<?= url('/knowledge/documents/' . $documentId . '/revisions/' . (int) $revision['id'] . '/restore') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline" type="submit">В черновик</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">История появится после первой публикации.</p>
        <?php endif; ?>
    </details>

    <details class="panel knowledge-danger-zone">
        <summary>Архив документа</summary>
        <form method="post" action="<?= url('/knowledge/documents/' . $documentId . '/archive') ?>" onsubmit="return confirm('Перенести документ в архив?')">
            <?= csrf_field() ?>
            <p>Документ исчезнет из базы знаний, но останется в базе данных.</p>
            <button class="btn btn-outline knowledge-archive-button" type="submit">Перенести в архив</button>
        </form>
    </details>
<?php endif; ?>
