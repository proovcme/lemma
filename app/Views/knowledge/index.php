<?php
$childrenByParent = [];
foreach ($folders as $folderRow) {
    $childrenByParent[(int) ($folderRow['parent_id'] ?? 0)][] = $folderRow;
}
$renderTree = function (int $parentId, int $depth = 0) use (&$renderTree, $childrenByParent, $currentFolder): void {
    if (empty($childrenByParent[$parentId])) {
        return;
    }
    echo '<ul class="knowledge-tree__level' . ($depth === 0 ? ' knowledge-tree__level--root' : '') . '">';
    foreach ($childrenByParent[$parentId] as $treeFolder) {
        $isActive = (int) ($currentFolder['id'] ?? 0) === (int) $treeFolder['id'];
        echo '<li><a class="knowledge-tree__link' . ($isActive ? ' is-active' : '') . '" href="' . e(url('/knowledge/folders/' . (int) $treeFolder['id'])) . '">';
        echo '<span aria-hidden="true">' . (!empty($childrenByParent[(int) $treeFolder['id']]) ? '▾' : '○') . '</span>';
        echo '<span>' . e($treeFolder['name']) . '</span>';
        echo '<small>' . (int) $treeFolder['published_count'] . '</small>';
        echo '</a>';
        $renderTree((int) $treeFolder['id'], $depth + 1);
        echo '</li>';
    }
    echo '</ul>';
};
$currentId = (int) ($currentFolder['id'] ?? 0);
$childFolders = $childrenByParent[$currentId] ?? [];
?>

<section class="knowledge-search panel" aria-label="Поиск в базе знаний">
    <form method="get" action="<?= url('/knowledge') ?>" class="filterbar knowledge-search__form">
        <label class="field field--grow">
            <span>Поиск по документам и разделам</span>
            <input type="search" name="q" value="<?= e($query) ?>" placeholder="Например: как создать задачу">
        </label>
        <button class="btn btn--red" type="submit">Найти</button>
        <?php if ($query !== ''): ?><a class="btn btn-outline" href="<?= url('/knowledge') ?>">Сбросить</a><?php endif; ?>
    </form>
</section>

<section class="knowledge-layout" data-knowledge-layout>
    <details class="knowledge-tree panel" data-knowledge-tree open>
        <summary>
            <span>Структура</span>
            <small>Папки базы знаний</small>
        </summary>
        <nav aria-label="Папки базы знаний">
            <a class="knowledge-tree__home<?= $currentFolder === null && $query === '' ? ' is-active' : '' ?>" href="<?= url('/knowledge') ?>">Все документы</a>
            <?php $renderTree(0); ?>
        </nav>
    </details>

    <div class="knowledge-content knowledge-search-results">
        <nav class="knowledge-breadcrumbs" aria-label="Путь к папке">
            <a href="<?= url('/knowledge') ?>">База знаний</a>
            <?php foreach ($breadcrumbs as $crumb): ?>
                <span aria-hidden="true">/</span>
                <a href="<?= url('/knowledge/folders/' . (int) $crumb['id']) ?>"><?= e($crumb['name']) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($currentFolder && $canEdit): ?>
            <div class="knowledge-folder-head">
                <div>
                    <p class="eyebrow">Папка</p>
                    <h2><?= e($currentFolder['name']) ?></h2>
                </div>
                <a class="btn btn-outline" href="<?= url('/knowledge/folders/' . (int) $currentFolder['id'] . '/edit') ?>">Настроить</a>
            </div>
        <?php elseif ($query !== ''): ?>
            <div class="knowledge-folder-head">
                <div><p class="eyebrow">Результаты поиска</p><h2>«<?= e($query) ?>»</h2></div>
                <span class="chip"><?= count($documents) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($pinnedDocuments): ?>
            <section class="knowledge-section" aria-labelledby="knowledge-pinned-title">
                <div class="panel__head"><div><p class="eyebrow">Закреплено</p><h2 id="knowledge-pinned-title">Важное для работы</h2></div></div>
                <div class="knowledge-document-list">
                    <?php foreach ($pinnedDocuments as $document): ?>
                        <?php require BASE_PATH . '/app/Views/knowledge/partials/document_row.php'; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($childFolders && $query === ''): ?>
            <section class="knowledge-section" aria-labelledby="knowledge-folders-title">
                <div class="panel__head"><div><p class="eyebrow">Разделы</p><h2 id="knowledge-folders-title">Папки</h2></div></div>
                <div class="knowledge-folder-grid">
                    <?php foreach ($childFolders as $folderRow): ?>
                        <a class="knowledge-folder-card" href="<?= url('/knowledge/folders/' . (int) $folderRow['id']) ?>">
                            <span class="knowledge-folder-card__mark" aria-hidden="true">⌑</span>
                            <span><strong><?= e($folderRow['name']) ?></strong><small><?= (int) $folderRow['published_count'] ?> документ(ов)</small></span>
                            <span aria-hidden="true">→</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="knowledge-section" aria-labelledby="knowledge-documents-title">
            <div class="panel__head">
                <div>
                    <p class="eyebrow"><?= $query !== '' ? 'Найдено' : 'Документы' ?></p>
                    <h2 id="knowledge-documents-title"><?= $currentFolder ? e($currentFolder['name']) : ($query !== '' ? 'Результаты' : 'В корне') ?></h2>
                </div>
                <span class="chip"><?= count($documents) ?></span>
            </div>
            <?php if ($documents): ?>
                <div class="knowledge-document-list">
                    <?php foreach ($documents as $document): ?>
                        <?php require BASE_PATH . '/app/Views/knowledge/partials/document_row.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <strong><?= $query !== '' ? 'Ничего не найдено' : 'В этой папке пока нет документов' ?></strong>
                    <p><?= $query !== '' ? 'Попробуйте другой запрос или откройте структуру папок.' : ($canEdit ? 'Создайте первый документ в этой папке.' : 'Документы появятся после публикации.') ?></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
