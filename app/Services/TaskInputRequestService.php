<?php

declare(strict_types=1);
namespace App\Services;

use PDO;
use InvalidArgumentException;
use DomainException;
use RuntimeException;
use Throwable;

/** A request is an explicit handoff, never a hidden task-status transition. */
final class TaskInputRequestService
{
    public function __construct(private PDO $pdo) {}

    public function visibleTask(int $taskId, array $user): ?array
    {
        [$scope, $params] = PermissionService::taskScopeWhere($user, 't');
        $q = $this->pdo->prepare('SELECT t.*, p.status AS project_status, p.gip_user_id, p.rp_user_id FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=:input_task_id AND ' . $scope);
        $q->execute(['input_task_id'=>$taskId] + $params);
        return $q->fetch() ?: null;
    }

    public function canRequest(array $task, array $user): bool
    {
        return ($task['project_status'] ?? '') === 'active' && ($task['status'] ?? '') !== 'done' && empty($task['closed_at'])
            && (in_array((int)$user['id'], array_map('intval', [$task['assignee_id'], $task['author_id'], $task['reviewer_id'] ?? 0]), true)
                || PermissionService::canEditProjectTabs($user, ['status'=>$task['project_status']]));
    }

    public function candidates(int $projectId, int $except = 0): array
    {
        $q = $this->pdo->prepare("SELECT DISTINCT u.id,u.name,u.department FROM users u WHERE u.is_active=1 AND u.id<>? AND u.role<>'admin' AND (
            u.id IN (SELECT user_id FROM project_members WHERE project_id=? AND active=1)
            OR u.id IN (SELECT a.user_id FROM project_section_assignments a JOIN project_sections s ON s.id=a.project_section_id WHERE s.project_id=? AND s.active=1)
            OR u.id IN (SELECT assignee_id FROM tasks WHERE project_id=?)
            OR u.id IN (SELECT author_id FROM tasks WHERE project_id=?)
            OR u.id IN (SELECT reviewer_id FROM tasks WHERE project_id=?)
            OR u.id IN (SELECT gip_user_id FROM projects WHERE id=?)
            OR u.id IN (SELECT rp_user_id FROM projects WHERE id=?)) ORDER BY u.department,u.name");
        $q->execute([$except,$projectId,$projectId,$projectId,$projectId,$projectId,$projectId,$projectId]);
        return $q->fetchAll();
    }

    public function forTask(int $taskId, array $user): array
    {
        $task=$this->visibleTask($taskId,$user);
        if (!$task) throw new DomainException('Нет доступа к работе.',403);
        $q=$this->pdo->prepare($this->select() . ' WHERE r.source_task_id=? ORDER BY r.id DESC');
        $q->execute([$taskId]);
        return $q->fetchAll();
    }

    public function get(int $id, array $user): array
    {
        $q=$this->pdo->prepare($this->select().' WHERE r.id=?'); $q->execute([$id]); $row=$q->fetch();
        if (!$row) throw new DomainException('Запрос не найден или недоступен.',404);
        $task=$this->visibleTask((int)$row['source_task_id'],$user);
        $recipient=(int)$row['responsible_id']===(int)$user['id'] && $this->projectPerson((int)$row['project_id'],(int)$user['id']);
        if (!$task && !$recipient) throw new DomainException('Запрос не найден или недоступен.',404);
        $row['can_view_task']=(bool)$task;
        if (!$task) $row['task_title']='';
        return $row;
    }

    public function responses(int $id, array $user): array
    {
        $this->get($id,$user);
        $q=$this->pdo->prepare('SELECT x.*,u.name AS responder_name,d.name AS decider_name FROM task_input_responses x JOIN users u ON u.id=x.responded_by LEFT JOIN users d ON d.id=x.decided_by WHERE x.request_id=? ORDER BY x.id DESC');
        $q->execute([$id]);return $q->fetchAll();
    }

    public function inbox(array $user): array
    {
        $q=$this->pdo->prepare($this->select()." WHERE p.status='active' AND ((r.responsible_id=? AND r.state IN ('waiting','returned')) OR (r.requester_id=? AND r.state='responded')) ORDER BY r.due_date,r.id LIMIT 24");
        $q->execute([(int)$user['id'],(int)$user['id']]);
        $rows=[];
        foreach($q->fetchAll() as $row){try{$rows[]=$this->get((int)$row['id'],$user);}catch(DomainException){}}
        return $rows;
    }

    public function create(int $taskId, array $user, array $input): int
    {
        $task=$this->visibleTask($taskId,$user);
        if (!$task || !$this->canRequest($task,$user)) throw new DomainException('Запросить данные можно только для доступной активной работы.',403);
        $title=$this->text($input['title']??'',240,'Что нужно получить');
        $expected=$this->text($input['expected_result']??'',4000,'Какой ответ нужен');
        $due=trim((string)($input['due_date']??''));
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$due);
        if (!$date || $date->format('Y-m-d')!==$due) throw new InvalidArgumentException('Укажите срок получения данных.');
        $responsible=(int)($input['responsible_id']??0);
        if ($responsible===(int)$user['id'] || !$this->projectPerson((int)$task['project_id'],$responsible)) throw new InvalidArgumentException('Выберите другого действующего участника этого проекта.');
        $nonce=(string)($input['request_nonce']??'');
        if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) throw new InvalidArgumentException('Обновите форму перед отправкой запроса.');
        $hash=hash('sha256',json_encode([$taskId,(int)$user['id'],$responsible,$title,$expected,$due],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $key=hash('sha256',$taskId.':'.$user['id'].':'.$nonce);
        $prior=$this->prior($key,$hash);if($prior)return $prior;
        $this->pdo->beginTransaction();
        try {
            // Recheck archive state inside the same transaction as all projections.
            $this->activeProject((int)$task['project_id']);
            $q=$this->pdo->prepare('SELECT name,department FROM users WHERE id=?');$q->execute([$responsible]);$person=$q->fetch();
            $q=$this->pdo->prepare('INSERT INTO project_data_registry(project_id,blocking_task_ids,section_code,missing_data,responsible,status,date_requested,date_received_plan,impact,comments) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$task['project_id'],json_encode([$taskId]),$task['section']??'',$title,$person['name'],'waiting',date('Y-m-d'),$due,'Основание работы #'.$taskId,$expected]);
            $dataId=(int)$this->pdo->lastInsertId();
            // task_id stays NULL: this handoff does not impersonate the existing assignment task.
            $q=$this->pdo->prepare('INSERT INTO project_task_exchange(project_id,direction,from_user_id,to_user_id,assignment,from_section,to_section,date_issued,deadline,status,comments) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$task['project_id'],'outgoing',$user['id'],$responsible,$title,$task['section']??'',$person['department'],date('Y-m-d'),$due,'pending','Исходные данные для работы #'.$taskId.'. '.$expected]);
            $exchange=(int)$this->pdo->lastInsertId();
            $q=$this->pdo->prepare('INSERT INTO task_input_requests(project_id,source_task_id,data_registry_id,exchange_id,requester_id,responsible_id,title,expected_result,due_date,request_key,dedupe_key,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$task['project_id'],$taskId,$dataId,$exchange,$user['id'],$responsible,$title,$expected,$due,$key,$hash,$hash]);
            $id=(int)$this->pdo->lastInsertId();
            $this->notify($responsible,$id,'input_requested','Нужны исходные данные: '.$title);
            $this->pdo->commit();return $id;
        } catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($error instanceof \PDOException){$prior=$this->prior($key,$hash);if($prior)return $prior;}
            throw $error;
        }
    }

    public function respond(int $id, array $user, array $input): void
    {
        $row=$this->get($id,$user);
        if((int)$row['responsible_id']!==(int)$user['id'] || !$this->projectPerson((int)$row['project_id'],(int)$user['id'])) throw new DomainException('Ответ может передать только назначенный участник.',403);
        $text=$this->text($input['response_text']??'',8000,'Ответ');
        $reference=$this->text($input['source_reference']??'',2000,'Документ, ссылка или путь');
        $revision=$this->text($input['source_revision']??'',160,'Редакция документа');
        $this->pdo->beginTransaction();
        try{
            $this->activeProject((int)$row['project_id']);
            $this->advance($row,(int)($input['version']??0),['waiting','returned'],'responded');
            $q=$this->pdo->prepare('INSERT INTO task_input_responses(request_id,responded_by,response_text,source_reference,source_revision) VALUES(?,?,?,?,?)');
            $q->execute([$id,$user['id'],$text,$reference,$revision]);$reply=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE task_input_requests SET latest_response_id=? WHERE id=?')->execute([$reply,$id]);
            $this->pdo->prepare("UPDATE project_task_exchange SET status='in_progress' WHERE id=?")->execute([$row['exchange_id']]);
            $this->clearNotifications($id,(int)$user['id']);
            $this->notify((int)$row['requester_id'],$id,'input_responded','Получен ответ, требуется принять: '.$row['title']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function decide(int $id, array $user, array $input): void
    {
        $row=$this->get($id,$user);
        if((int)$row['requester_id']!==(int)$user['id']) throw new DomainException('Ответ принимает тот, кто запросил данные.',403);
        $decision=(string)($input['decision']??'');
        if(!in_array($decision,['accepted','returned'],true)) throw new InvalidArgumentException('Выберите решение по ответу.');
        $comment=trim((string)($input['decision_comment']??''));
        if($decision==='returned')$comment=$this->text($comment,4000,'Что нужно уточнить');
        if(mb_strlen($comment)>4000)throw new InvalidArgumentException('Комментарий длиннее 4000 символов.');
        if((int)($input['response_id']??0)!==(int)$row['latest_response_id'])throw new RuntimeException('Ответ обновился. Перезагрузите страницу.',409);
        $this->pdo->beginTransaction();
        try{
            $this->activeProject((int)$row['project_id']);
            $this->advance($row,(int)($input['version']??0),['responded'],$decision);
            $q=$this->pdo->prepare("UPDATE task_input_responses SET decision=?,decision_comment=?,decided_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=? AND request_id=? AND decision='pending'");
            $q->execute([$decision,$comment,$user['id'],$row['latest_response_id'],$id]);
            if($q->rowCount()!==1)throw new RuntimeException('По ответу уже принято решение. Обновите страницу.',409);
            if($decision==='accepted'){
                $this->pdo->prepare('UPDATE task_input_requests SET accepted_response_id=? WHERE id=?')->execute([$row['latest_response_id'],$id]);
                $this->pdo->prepare("UPDATE project_data_registry SET status='received' WHERE id=?")->execute([$row['data_registry_id']]);
                $this->pdo->prepare("UPDATE project_task_exchange SET status='done' WHERE id=?")->execute([$row['exchange_id']]);
            }else{
                $this->pdo->prepare("UPDATE project_task_exchange SET status='blocked' WHERE id=?")->execute([$row['exchange_id']]);
                $this->notify((int)$row['responsible_id'],$id,'input_returned','Нужно уточнить ответ: '.$row['title']);
            }
            $this->clearNotifications($id,(int)$user['id']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function cancel(int $id,array $user,array $input): void
    {
        $row=$this->get($id,$user);
        if((int)$row['requester_id']!==(int)$user['id'])throw new DomainException('Отменить запрос может его автор.',403);
        $reason=$this->text($input['cancel_reason']??'',4000,'Почему данные больше не нужны');
        $this->pdo->beginTransaction();try{
            $this->activeProject((int)$row['project_id']);$this->advance($row,(int)($input['version']??0),['waiting','responded','returned'],'cancelled');
            $this->pdo->prepare('UPDATE task_input_requests SET cancel_reason=?,cancelled_at=CURRENT_TIMESTAMP,dedupe_key=NULL WHERE id=?')->execute([$reason,$id]);
            $this->pdo->prepare("UPDATE project_data_registry SET status='not_needed' WHERE id=?")->execute([$row['data_registry_id']]);
            $this->pdo->prepare("UPDATE project_task_exchange SET status='done' WHERE id=?")->execute([$row['exchange_id']]);
            $this->clearNotifications($id,(int)$row['requester_id']);$this->clearNotifications($id,(int)$row['responsible_id']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function advance(array $row,int $version,array $states,string $state): void
    {
        if(!in_array($row['state'],$states,true) || $version!==(int)$row['version'])throw new RuntimeException('Запрос изменился. Перезагрузите страницу, чтобы не потерять чужое решение.',409);
        $q=$this->pdo->prepare('UPDATE task_input_requests SET state=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND version=? AND state=?');
        $q->execute([$state,$row['id'],$version,$row['state']]);if($q->rowCount()!==1)throw new RuntimeException('Запрос уже обновлён в другой вкладке.',409);
    }
    private function prior(string $key,string $hash): ?int
    {
        $q=$this->pdo->prepare('SELECT id,payload_hash FROM task_input_requests WHERE request_key=?');$q->execute([$key]);$r=$q->fetch();
        if($r){
            if($r['payload_hash']!==$hash)throw new InvalidArgumentException('Эта форма уже отправлена с другими данными. Откройте новую форму.');
            return (int)$r['id'];
        }
        $q=$this->pdo->prepare('SELECT id FROM task_input_requests WHERE dedupe_key=?');$q->execute([$hash]);
        $id=$q->fetchColumn();return $id===false?null:(int)$id;
    }

    private function projectPerson(int $project,int $user): bool {return in_array($user,array_map('intval',array_column($this->candidates($project),'id')),true);}
    private function activeProject(int $id): void {$q=$this->pdo->prepare('SELECT status FROM projects WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!=='active')throw new DomainException('Архивный проект доступен только для чтения.',403);}
    private function text(mixed $value,int $max,string $label): string {$value=trim((string)$value);if($value===''||mb_strlen($value)>$max)throw new InvalidArgumentException($label.': заполните поле, до '.$max.' символов.');return $value;}
    private function notify(int $user,int $id,string $type,string $body): void
    {
        $this->clearNotifications($id,$user);
        $this->pdo->prepare('INSERT INTO notifications(user_id,type,body,target_url) VALUES(?,?,?,?)')->execute([$user,$type,$body,'/input-requests/'.$id]);
    }
    private function clearNotifications(int $id,int $user): void {$this->pdo->prepare('UPDATE notifications SET read_at=CURRENT_TIMESTAMP WHERE user_id=? AND target_url=? AND read_at IS NULL')->execute([$user,'/input-requests/'.$id]);}
    private function select(): string {return 'SELECT r.*,p.code AS project_code,p.status AS project_status,t.title AS task_title,u.name AS requester_name,v.name AS responsible_name FROM task_input_requests r JOIN projects p ON p.id=r.project_id JOIN tasks t ON t.id=r.source_task_id JOIN users u ON u.id=r.requester_id JOIN users v ON v.id=r.responsible_id';}
}
