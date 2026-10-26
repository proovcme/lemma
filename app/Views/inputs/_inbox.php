<?php if($inputInbox): ?>
<section class="input-inbox" aria-labelledby="input-inbox-title"><div class="section-heading"><h2 id="input-inbox-title">Исходные данные · нужно ваше действие</h2><span class="muted"><?= count($inputInbox) ?><?= count($inputInbox)===24?' · показаны первые 24':'' ?></span></div><div class="day-list">
<?php foreach($inputInbox as $r): $needsReview=$r['state']==='responded'; ?>
<article class="day-entry"><div class="day-entry-main"><div><span class="day-action-label"><?= $needsReview?'Принять ответ как основание':($r['state']==='returned'?'Уточнить ответ':'Подготовить исходные данные') ?></span><h2><a href="<?= url('/input-requests/'.(int)$r['id']) ?>"><?= e($r['title']) ?></a></h2><p class="muted"><?= e($r['project_code']) ?> · <?= e($needsReview?$r['responsible_name']:$r['requester_name']) ?></p><small>До <?= e(format_date($r['due_date'])) ?><?= $r['due_date']<date('Y-m-d')?' · Просрочено':'' ?></small></div><a class="btn primary" href="<?= url('/input-requests/'.(int)$r['id']) ?>"><?= $needsReview?'Проверить ответ':'Открыть запрос' ?></a></div></article>
<?php endforeach; ?></div></section>
<?php endif; ?>
