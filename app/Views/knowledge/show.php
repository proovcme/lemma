<article class="knowledge-reader" data-knowledge-reader>
    <nav class="knowledge-breadcrumbs" aria-label="Путь к документу">
        <a href="<?= url('/knowledge') ?>">База знаний</a>
        <?php foreach ($breadcrumbs as $crumb): ?>
            <span aria-hidden="true">/</span>
            <a href="<?= url('/knowledge/folders/' . (int) $crumb['id']) ?>"><?= e($crumb['name']) ?></a>
        <?php endforeach; ?>
        <span aria-hidden="true">/</span><span><?= e($document['title']) ?></span>
    </nav>

    <header class="knowledge-reader__head">
        <p class="eyebrow"><?= e($document['folder_name'] ?: 'Документ') ?></p>
        <h2><?= e($document['title']) ?></h2>
        <?php if (!empty($document['summary'])): ?><p><?= e($document['summary']) ?></p><?php endif; ?>
        <div class="knowledge-reader__meta">
            <span>Версия <?= (int) $document['current_version'] ?></span>
            <?php if (!empty($document['published_at'])): ?><span>Опубликовано <?= e(date('d.m.Y', strtotime((string) $document['published_at']))) ?></span><?php endif; ?>
            <?php if (!empty($document['updated_by_name'])): ?><span><?= e($document['updated_by_name']) ?></span><?php endif; ?>
        </div>
    </header>

    <details class="knowledge-toc" data-knowledge-toc open>
        <summary>Оглавление</summary>
        <nav aria-label="Оглавление документа" data-knowledge-toc-links></nav>
    </details>

    <div class="knowledge-reader__body" data-knowledge-body>
        <?= $document['body_html'] ?>
    </div>
</article>
