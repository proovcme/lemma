<?php
use App\Services\{TimeService,WorkdayFlowService};
$hours = static fn ($minutes): string => TimeService::minutesToHours((int)$minutes) ?: '0';
$formatNumber = static function ($value,$precision=1): string { $result=number_format((float)$value,$precision,'.',' ');return str_contains($result,'.')?rtrim(rtrim($result,'0'),'.'):$result; };
$managerMatrixActions=array_values(array_filter($performanceReviewActions['manager_ready']??[],static fn($row)=>($row['manager_state']??'')==='ready'));
$managerMeetingActions=array_values(array_filter($performanceReviewActions['manager_ready']??[],static fn($row)=>($row['manager_state']??'')==='meeting_ready'));
$absenceLabels=['vacation','sick_leave','business_trip','learning','day_off'];
$tab=in_array($_GET['day']??'',['actions','work','awaiting','due'],true)?$_GET['day']:'actions';
$queue=WorkdayFlowService::queue($model['actions'],$inputInbox??[]);
$items=match($tab){'work'=>WorkdayFlowService::queue($model['inWork'],[]),'awaiting'=>WorkdayFlowService::queue($model['awaiting'],[]),'due'=>WorkdayFlowService::queue($model['due'],[]),default=>$queue};
$counts=['actions'=>count($queue),'work'=>count($model['inWork']),'awaiting'=>count($model['awaiting']),'due'=>count($model['due'])];
$group=null;
?>
<div class="page-heading"><div><h1>Мой день</h1><p>Следующий шаг по вашей работе. Список обновляется после каждого решения.</p></div><a class="btn" href="<?= url('/tasks') ?>">Все мои задачи →</a></div>
<div class="day-facts">
<a href="<?= url('/my-day') ?>"><small>Мои действия</small><strong><?= $counts['actions'] ?></strong></a>
<a href="<?= url('/my-day?day=awaiting') ?>"><small>Жду решения</small><strong><?= $counts['awaiting'] ?></strong></a>
<a href="<?= url('/my-day?day=work') ?>"><small>В работе</small><strong><?= $counts['work'] ?></strong></a>
<a href="<?= url('/time') ?>"><small>Записано сегодня</small><strong><?= e($hours($model['timeToday']['totalMinutes'])) ?> ч</strong></a>
</div>
<nav class="tabs day-flow-tabs" aria-label="Работа на сегодня">
<?php foreach(['actions'=>'Сделать','work'=>'В работе','awaiting'=>'Жду решения','due'=>'Сроки'] as $key=>$label): ?>
<a class="<?= $tab===$key?'active':'' ?>"<?= $tab===$key?' aria-current="page"':'' ?> href="<?= url('/my-day?'.http_build_query(array_filter(['day'=>$key,'department'=>$filters['department']??'','date_from'=>$filters['date_from']??'','date_to'=>$filters['date_to']??'']))) ?>"><?= e($label) ?> <span><?= $counts[$key] ?></span></a>
<?php endforeach; ?></nav>
<?php if(!app_is_demo_mode()) require BASE_PATH.'/app/Views/workday/_workspace_reviews.php'; ?>
<div class="day-list">
<?php if(!$items): ?><div class="empty"><h2><?= match($tab){'actions'=>'Сейчас от вас не ждут решений','work'=>'Пока нет задач в работе','awaiting'=>'Ожиданий решения нет',default=>'Ближайших сроков и просрочек нет'} ?></h2><p><?= $tab==='actions'?'Можно продолжить текущую работу. Новые поручения, возвраты и ответы появятся здесь автоматически.':'Когда состояние работы изменится, список обновится автоматически.' ?></p><?php if($tab==='actions' && $counts['work']): ?><a class="btn primary" href="<?= url('/my-day?day=work') ?>">Продолжить работу · <?= $counts['work'] ?></a><?php endif; ?></div><?php endif; ?>
<?php foreach($items as $entry):
    $currentGroup=!$entry['due']?'Без срока':($entry['due']<$model['today']?'Просрочено':($entry['due']===$model['today']?'Сегодня':'Далее'));
    if($group!==$currentGroup):$group=$currentGroup; ?><h2 class="day-group-title"><?= e($group) ?></h2><?php endif;
    if($entry['kind']==='input') { $r=$entry['item']; require BASE_PATH.'/app/Views/inputs/_day_entry.php'; }
    else { $task=$entry['item']; require BASE_PATH.'/app/Views/workday/_task_entry.php'; }
endforeach; ?>
</div><p class="muted day-limit-note">Показано до 24 задач в каждом списке<?= $tab==='actions'?' и до 24 запросов':'' ?>. Полный перечень — в задачах.</p>
<?php if ($dayNotifications): ?><details class="workspace-secondary"><summary>Непрочитанные уведомления · последние <?= count($dayNotifications) ?></summary><?php foreach ($dayNotifications as $notification): ?><div class="day-notification"><p><?= e($notification['body']) ?></p><?php if ($notification['target_url']): ?><a class="btn text" href="<?= url($notification['target_url']) ?>">Открыть →</a><?php endif; ?></div><?php endforeach; ?><a class="btn" href="<?= url('/notifications') ?>">Все уведомления</a></details><?php endif; ?>
<div class="workspace-secondary">
<?php require BASE_PATH . '/app/Views/workday/_workspace_secondary.php'; ?>
</div>
