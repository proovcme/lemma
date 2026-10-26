<?php
declare(strict_types=1);
use App\Core\Database;
use App\Services\{RoleService,TaskInputRequestService,PermissionService};

function input_fixture(): array {
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec(file_get_contents(BASE_PATH.'/database/sqlite_schema.sql'));
    Database::useConnection($p);RoleService::resetCache();
    $q=$p->prepare('INSERT INTO users(id,tab_number,name,email,password_hash,role,department) VALUES(?,?,?,?,?,?,?)');
    foreach([[1,'engineer','ОВ'],[2,'engineer','АР'],[3,'director','Дирекция'],[4,'engineer','КР']] as [$id,$role,$dept])$q->execute([$id,'T'.$id,'Участник '.$id,'test'.$id.'@example.test','disabled',$role,$dept]);
    $p->exec("INSERT INTO projects(id,code,title) VALUES(1,'TEST-1','Проект'),(2,'TEST-2','Чужой проект')");
    $p->exec("INSERT INTO tasks(id,project_id,title,assignee_id,author_id,status,date_end,planned_hours) VALUES(1,1,'Закрытая от смежника работа',1,3,'in_progress','2026-11-30',8),(2,2,'Чужая',4,4,'new','2026-12-01',4)");
    $p->exec('INSERT INTO project_members(project_id,user_id) VALUES(1,2)');
    $users=[];foreach($p->query('SELECT * FROM users') as $u)$users[$u['id']]=$u;
    return [$p,new TaskInputRequestService($p),$users];
}
function input_payload(): array{return ['title'=>'Планы АР для ОВ','expected_result'=>'Передать согласованные планы с высотами','responsible_id'=>2,'due_date'=>'2026-11-01','request_nonce'=>str_repeat('a',32)];}
function input_reply(int $version=1): array{return ['version'=>$version,'response_text'=>'Передаю планы','source_reference'=>'\\\\share\\project\\АР.pdf','source_revision'=>'Изм. 2 от 26.10.2026'];}

test('запрос создаёт реестры атомарно и повторная отправка не дублирует передачу',function(){
    [$p,$s,$u]=input_fixture();$before=$p->query('SELECT * FROM tasks WHERE id=1')->fetch();
    assert_same(false,PermissionService::canCreateTasks($u[1]));
    $id=$s->create(1,$u[1],input_payload());
    assert_same($id,$s->create(1,$u[1],input_payload()));
    $another=input_payload();$another['request_nonce']=str_repeat('b',32);assert_same($id,$s->create(1,$u[1],$another));
    foreach(['task_input_requests','project_data_registry','project_task_exchange','notifications'] as $table)assert_same(1,(int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
    assert_same(null,$p->query('SELECT task_id FROM project_task_exchange')->fetchColumn());
    assert_same($before,$p->query('SELECT * FROM tasks WHERE id=1')->fetch());
    $changed=input_payload();$changed['title']='Другие планы';assert_throws(fn()=>$s->create(1,$u[1],$changed));
});
test('смежник видит запрос без доступа к исходной задаче, посторонний не видит ничего',function(){
    [$p,$s,$u]=input_fixture();$id=$s->create(1,$u[1],input_payload());
    assert_same(null,$s->visibleTask(1,$u[2]));$r=$s->get($id,$u[2]);assert_same(false,$r['can_view_task']);assert_same('',$r['task_title']);
    assert_same('',$s->inbox($u[2])[0]['task_title']);
    assert_throws(fn()=>$s->get($id,$u[4]));assert_throws(fn()=>$s->respond($id,$u[4],input_reply()));
    assert_throws(fn()=>$s->respond($id,$u[3],input_reply()));
    assert_throws(fn()=>$s->create(2,$u[1],input_payload()));
});
test('ответ не закрывает ожидание, явное принятие фиксирует конкретное основание',function(){
    [$p,$s,$u]=input_fixture();$before=$p->query('SELECT * FROM tasks WHERE id=1')->fetch();$id=$s->create(1,$u[1],input_payload());
    $s->respond($id,$u[2],input_reply());$r=$s->get($id,$u[1]);
    assert_same('responded',$r['state']);assert_same('waiting',$p->query('SELECT status FROM project_data_registry')->fetchColumn());
    assert_same(1,count($s->inbox($u[1])));assert_same(0,count($s->inbox($u[2])));
    $decision=['version'=>2,'response_id'=>$r['latest_response_id'],'decision'=>'accepted'];
    assert_throws(fn()=>$s->decide($id,$u[3],$decision));
    $s->decide($id,$u[1],$decision);$accepted=$s->get($id,$u[1]);assert_same('accepted',$accepted['state']);
    assert_same($r['latest_response_id'],$accepted['accepted_response_id']);assert_same('received',$p->query('SELECT status FROM project_data_registry')->fetchColumn());assert_same('done',$p->query('SELECT status FROM project_task_exchange')->fetchColumn());
    assert_same(0,count($s->inbox($u[1])));assert_same($before,$p->query('SELECT * FROM tasks WHERE id=1')->fetch());
    assert_throws(fn()=>$s->respond($id,$u[2],input_reply(3)));assert_throws(fn()=>$s->cancel($id,$u[1],['version'=>3,'cancel_reason'=>'Передумал']));
});
test('уточнение сохраняет обе редакции и защищает от устаревшего решения',function(){
    [$p,$s,$u]=input_fixture();$id=$s->create(1,$u[1],input_payload());$s->respond($id,$u[2],input_reply());$r=$s->get($id,$u[1]);
    $d=['version'=>2,'response_id'=>$r['latest_response_id'],'decision'=>'returned'];assert_throws(fn()=>$s->decide($id,$u[1],$d));
    $d['decision_comment']='Не указаны высоты';$s->decide($id,$u[1],$d);
    assert_same('returned',$s->get($id,$u[1])['state']);assert_same(1,count($s->inbox($u[2])));
    assert_throws(fn()=>$s->respond($id,$u[2],input_reply(1)));
    $new=input_reply(3);$new['source_revision']='Изм. 3';$s->respond($id,$u[2],$new);
    $d['decision']='accepted';assert_throws(fn()=>$s->decide($id,$u[1],$d));
    $responses=$s->responses($id,$u[1]);assert_same(2,count($responses));assert_same('returned',$responses[1]['decision']);assert_same('Не указаны высоты',$responses[1]['decision_comment']);assert_same('Изм. 3',$responses[0]['source_revision']);
    $d['version']=4;$d['response_id']=$responses[0]['id'];$s->decide($id,$u[1],$d);assert_same('accepted',$s->get($id,$u[1])['state']);
});
test('архив, неверный получатель и незаполненная редакция не меняют историю',function(){
    [$p,$s,$u]=input_fixture();$bad=input_payload();$bad['responsible_id']=4;assert_throws(fn()=>$s->create(1,$u[1],$bad));assert_same(0,(int)$p->query('SELECT COUNT(*) FROM task_input_requests')->fetchColumn());
    $id=$s->create(1,$u[1],input_payload());$reply=input_reply();$reply['source_revision']='';assert_throws(fn()=>$s->respond($id,$u[2],$reply));
    $p->exec("UPDATE projects SET status='archived' WHERE id=1");assert_throws(fn()=>$s->respond($id,$u[2],input_reply()));assert_throws(fn()=>$s->cancel($id,$u[1],['version'=>1,'cancel_reason'=>'Причина']));assert_same('waiting',$s->get($id,$u[1])['state']);assert_same(0,count($s->inbox($u[2])));
    $p->exec("UPDATE projects SET status='active' WHERE id=1;UPDATE tasks SET status='done' WHERE id=1");assert_throws(fn()=>$s->create(1,$u[1],input_payload()));
    $s->respond($id,$u[2],input_reply());assert_same('done',$p->query('SELECT status FROM tasks WHERE id=1')->fetchColumn());
});
test('отмена сохраняет историю и допускает новый осознанный запрос',function(){
    [$p,$s,$u]=input_fixture();$id=$s->create(1,$u[1],input_payload());$s->cancel($id,$u[1],['version'=>1,'cancel_reason'=>'Основание изменилось']);
    assert_same('cancelled',$s->get($id,$u[1])['state']);assert_same('not_needed',$p->query('SELECT status FROM project_data_registry')->fetchColumn());
    assert_same($id,$s->create(1,$u[1],input_payload()));$payload=input_payload();$payload['request_nonce']=str_repeat('c',32);assert_true($s->create(1,$u[1],$payload)>$id);
});
test('ошибка уведомления откатывает запрос и обе строки реестров',function(){
    [$p,$s,$u]=input_fixture();$p->exec("CREATE TRIGGER fail_input_notification BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT,'test failure'); END");assert_throws(fn()=>$s->create(1,$u[1],input_payload()));
    foreach(['task_input_requests','project_data_registry','project_task_exchange'] as $table)assert_same(0,(int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
    Database::reset();RoleService::resetCache();
});
