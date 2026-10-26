<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Services\TaskInputRequestService;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class TaskInputController extends BaseController
{
    public function show(int $id): void
    {
        $user=require_auth();$service=new TaskInputRequestService($this->db());
        try{$row=$service->get($id,$user);}catch(DomainException $e){$this->error($e);return;}
        $this->page($service,$row,$user);
    }
    public function create(int $id): void
    {
        $user=require_auth();$service=new TaskInputRequestService($this->db());
        try{$request=$service->create($id,$user,$_POST);flash('success','Запрос передан. Данные появятся в работе после вашего принятия ответа.');redirect('/input-requests/'.$request);}
        catch(InvalidArgumentException $e){
            $task=$service->visibleTask($id,$user);if(!$task){$this->error(new DomainException('Нет доступа.',403));return;}
            http_response_code(422);
            view('inputs/create',['title'=>'Запросить исходные данные','inputTask'=>$task,'inputCandidates'=>$service->candidates((int)$task['project_id'],(int)$user['id']),'inputError'=>$e->getMessage(),'inputOld'=>$_POST,'inputNonce'=>(string)($_POST['request_nonce']??bin2hex(random_bytes(16)))]);
        }catch(DomainException $e){$this->error($e);}
    }
    public function respond(int $id): void {$this->mutate($id,'respond');}
    public function decide(int $id): void {$this->mutate($id,'decide');}
    public function cancel(int $id): void {$this->mutate($id,'cancel');}
    private function mutate(int $id,string $action): void
    {
        $user=require_auth();$service=new TaskInputRequestService($this->db());
        try{$service->$action($id,$user,$_POST);flash('success',match($action){'respond'=>'Ответ передан на принятие.','cancel'=>'Запрос отменён; история сохранена.',default=>($_POST['decision']??'')==='accepted'?'Ответ принят как основание работы. Статус и срок задачи сохранены.':'Ответ возвращён на уточнение.'});redirect('/input-requests/'.$id);}
        catch(DomainException $e){$this->error($e);}
        catch(InvalidArgumentException|RuntimeException $e){
            if($e instanceof \PDOException)throw $e;
            http_response_code($e->getCode()===409?409:422);
            try{$row=$service->get($id,$user);}catch(DomainException $denied){$this->error($denied);return;}
            $this->page($service,$row,$user,$e->getMessage(),$_POST);
        }
    }
    private function page(TaskInputRequestService $service,array $row,array $user,string $error='',array $old=[]): void
    {
        view('inputs/show',['title'=>'Исходные данные','inputRequest'=>$row,'inputResponses'=>$service->responses((int)$row['id'],$user),'inputError'=>$error,'inputOld'=>$old]);
    }
    private function error(DomainException $e): void {http_response_code(in_array($e->getCode(),[403,404],true)?$e->getCode():403);view('layouts/error',['title'=>'Запрос недоступен','message'=>$e->getMessage()]);}
}
