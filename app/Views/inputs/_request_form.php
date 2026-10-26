<?php $inputOld=$inputOld??[]; ?>
<form class="form-grid input-request-form" method="post" action="<?= url('/tasks/'.(int)$inputTask['id'].'/input-requests') ?>">
<?= csrf_field() ?><input type="hidden" name="request_nonce" value="<?= e($inputNonce) ?>">
<label class="form-grid__full"><span>Что нужно получить</span><input name="title" maxlength="240" required value="<?= e($inputOld['title']??'') ?>" placeholder="Например: перечень оборудования ОВ для расчёта нагрузок"></label>
<label class="form-grid__full"><span>Какой ответ нужен для этой работы</span><textarea name="expected_result" required maxlength="4000" rows="3" placeholder="Какие данные, в каком объёме и что по ним нужно подтвердить"><?= e($inputOld['expected_result']??'') ?></textarea></label>
<label><span>У кого запросить</span><select name="responsible_id" required aria-label="У кого запросить исходные данные"><option value="">Участник этого проекта</option><?php foreach($inputCandidates as $person): ?><option value="<?= (int)$person['id'] ?>"<?= selected($inputOld['responsible_id']??'',$person['id']) ?>><?= e($person['name'].' · '.$person['department']) ?></option><?php endforeach; ?></select></label>
<label><span>Когда нужны данные</span><input name="due_date" type="date" required value="<?= e($inputOld['due_date']??'') ?>"></label>
<p class="muted form-grid__full">Получатель увидит запрос в «Моём дне». Саму задачу, её срок и исполнителя система не меняет.</p>
<div class="form-actions form-grid__full"><button class="btn btn--red" type="submit">Передать запрос</button></div>
</form>
