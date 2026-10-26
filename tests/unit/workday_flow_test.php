<?php
declare(strict_types=1);
use App\Core\Database;
use App\Services\{RoleService,WorkdayService,WorkdayFlowService};
function flow_fixture(): array {
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec(file_get_contents(BASE_PATH.'/database/sqlite_schema.sql'));Database::useConnection($p);RoleService::resetCache();
    $p->exec("INSERT INTO users(id,tab_number,name,email,password_hash,role,department) VALUES(1,'01','Исполнитель','one@example.test','disabled','engineer','ОВ'),(2,'02','Проверяющий','two@example.test','disabled','department_head','ОВ'),(3,'03','Автор','three@example.test','disabled','gip','ГИП'),(4,'04','Директор','four@example.test','disabled','director','Дирекция')");
    $p->exec("INSERT INTO projects(id,code,title,gip_user_id) VALUES(1,'P-1','Проект',3),(2,'P-2','Архив',3)");$p->exec("UPDATE projects SET status='archived' WHERE id=2");
    $q=$p->prepare('INSERT INTO tasks(id,title,project_id,assignee_id,author_id,reviewer_id,status,approval_stage,close_requested_at,date_end,task_type) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach([[1,1,'new','draft',null,'work'],[2,1,'correction','draft',null,'work'],[3,1,'review','approved','2026-10-25 09:00:00','work'],[4,1,'in_progress','review_lead',null,'work'],[5,1,'in_progress','approved',null,'work'],[6,1,'blocked','draft',null,'work'],[7,1,'done','approved',null,'work'],[8,2,'new','draft',null,'work'],[9,1,'new','draft',null,'delegation'],[10,1,'new','draft',null,'review']] as [$id,$project,$status,$stage,$closed,$kind])$q->execute([$id,'Работа '.$id,$project,1,3,2,$status,$stage,$closed,'2026-11-01',$kind]);
    $p->exec("INSERT INTO task_approvals(task_id,stage,approved_by,decision,comment) VALUES(2,'review_task',2,'rejected','Уточнить расчёт')");
    $users=[];foreach($p->query('SELECT * FROM users') as $u)$users[$u['id']]=$u;
    return [$p,new WorkdayService($p),$users];
}
test('мой день отделяет принятие от возврата, ожидание проверки от исполнения',function(){
    [$p,$s,$u]=flow_fixture();$before=$p->query('SELECT * FROM tasks ORDER BY id')->fetchAll();$m=$s->model($u[1]);
    assert_same([1],array_column($m['waiting'],'id'));
    assert_same([1,2,6,9],array_column($m['actions'],'id'));
    assert_same([3,4],array_column($m['awaiting'],'id'));
    assert_same([2,5],array_column($m['inWork'],'id'));
    $correction=$m['actions'][1];assert_same(false,$correction['flow']['can_accept']);assert_same('Уточнить расчёт',$correction['last_review_comment']);
    assert_true(str_contains($m['awaiting'][0]['flow']['reason'],'Проверяющий'));
    foreach($m['awaiting'] as $r)assert_same(false,$r['flow']['can_time']);
    assert_same($before,$p->query('SELECT * FROM tasks ORDER BY id')->fetchAll());
});
test('проверяющий получает решение, директорское наблюдение не создаёт личных действий',function(){
    [$p,$s,$u]=flow_fixture();$m=$s->model($u[2]);assert_same([3,4],array_column($m['actions'],'id'));assert_same([], $m['awaiting']);assert_same([], $m['inWork']);
    foreach($m['actions'] as $r)assert_same(false,$r['flow']['can_accept']);
    $director=$s->model($u[4]);assert_same([],$director['actions']);assert_same([],$director['awaiting']);
});
test('личная очередь смешивает запросы и задачи по сроку, без потери одинаковых id',function(){
    $queue=WorkdayFlowService::queue([['id'=>1,'date_end'=>'2026-11-03'],['id'=>2,'date_end'=>null]],[['id'=>1,'due_date'=>'2026-11-01']]);
    assert_same(['input','task','task'],array_column($queue,'kind'));assert_same([1,1,2],array_column($queue,'id'));
    Database::reset();RoleService::resetCache();
});
