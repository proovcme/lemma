<?php
$regulation = $regulation ?? [];
$sections = $regulation['sections'] ?? [];
$regulationTitle = app_demo_mask_text((string) ($regulation['title'] ?? 'Регламент работы в Лемме'));
$regulationSubtitle = app_demo_mask_text((string) ($regulation['subtitle'] ?? ''));
?>
<section class="manual-page regulation-page">
    <div class="manual-lead">
        <div class="regulation-head">
            <div>
                <h2><?= e($regulationTitle) ?></h2>
                <p><?= e($regulationSubtitle) ?></p>
            </div>
            <div class="regulation-head__actions">
                <?php if (!empty($regulation['version'])): ?>
                    <span class="pill">Версия <?= e($regulation['version']) ?></span>
                <?php endif; ?>
                <a class="btn btn-outline btn-sm" href="<?= url('/manual') ?>">К руководству</a>
                <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">Печать</button>
            </div>
        </div>
    </div>

    <?php if ($sections): ?>
        <nav class="manual-toc" aria-label="Разделы регламента">
            <?php foreach ($sections as $section): ?>
                <a href="#reg-<?= e($section['no'] ?? '') ?>"><?= e(app_demo_mask_text(($section['no'] ?? '') . '. ' . ($section['title'] ?? ''))) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($sections as $section): ?>
            <section class="manual-section regulation-section" id="reg-<?= e($section['no'] ?? '') ?>">
                <h2><?= e(app_demo_mask_text(($section['no'] ?? '') . '. ' . ($section['title'] ?? ''))) ?></h2>
                <ol class="regulation-clauses">
                    <?php foreach (($section['clauses'] ?? []) as $i => $clause): ?>
                        <?php $clauseId = 'reg-' . ($section['no'] ?? '') . '-' . ($i + 1); ?>
                        <li id="<?= e($clauseId) ?>"><a class="regulation-clauses__no" href="#<?= e($clauseId) ?>" title="Ссылка на пункт"><?= e(($section['no'] ?? '') . '.' . ($i + 1)) ?></a><span><?= e(app_demo_mask_text((string) $clause)) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">Регламент пока не заполнен.</div>
    <?php endif; ?>
</section>
