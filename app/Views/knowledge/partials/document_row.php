<?php
$documentTitle = (string) (($canEdit && ($document['status'] ?? '') === 'draft') ? ($document['draft_title'] ?? $document['title']) : $document['title']);
$documentSummary = trim((string) (($canEdit && ($document['status'] ?? '') === 'draft') ? ($document['draft_summary'] ?? $document['summary']) : $document['summary']));
$documentHref = ($canEdit && ($document['status'] ?? '') === 'draft')
    ? '/knowledge/documents/' . (int) $document['id'] . '/edit'
    : '/knowledge/documents/' . (int) $document['id'];
?>
<a class="knowledge-document-row" href="<?= url($documentHref) ?>">
    <span class="knowledge-document-row__index"><?= str_pad((string) (int) $document['id'], 2, '0', STR_PAD_LEFT) ?></span>
    <span class="knowledge-document-row__copy">
        <strong><?= e($documentTitle) ?></strong>
        <small><?= e($documentSummary !== '' ? $documentSummary : ($document['excerpt'] ?? 'Рабочий документ')) ?></small>
        <span class="knowledge-document-row__meta">
            <?php if (!empty($document['folder_name'])): ?><span><?= e($document['folder_name']) ?></span><?php endif; ?>
            <?php if (($document['status'] ?? '') === 'draft'): ?><span class="status-chip status-chip--warning">Черновик</span><?php else: ?><span>Версия <?= (int) ($document['current_version'] ?? 0) ?></span><?php endif; ?>
            <?php if (!empty($document['updated_at'])): ?><span><?= e(date('d.m.Y', strtotime((string) $document['updated_at']))) ?></span><?php endif; ?>
        </span>
    </span>
    <span class="knowledge-document-row__arrow" aria-hidden="true">→</span>
</a>
