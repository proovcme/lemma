<section class="panel"><div class="panel__head"><div><h2>Исходные данные для работы #<?= (int)$inputTask['id'] ?></h2><p><?= e($inputTask['title']) ?></p></div><a class="btn" href="<?= url('/tasks/'.(int)$inputTask['id']) ?>">К работе</a></div>
<?php if($inputError): ?><div class="alert alert--error" role="alert"><?= e($inputError) ?></div><?php endif; ?>
<?php require BASE_PATH.'/app/Views/inputs/_request_form.php'; ?></section>
