<?php

declare(strict_types=1);
namespace App\Services;

/** Explains the existing workflow; never grants rights or changes task state. */
final class WorkdayFlowService
{
    public static function describe(array $task, bool $personalAction): array
    {
        $status = (string) ($task['status'] ?? '');
        $stage = (string) ($task['approval_stage'] ?? 'draft');
        $reviewer = trim((string) ($task['reviewer_name'] ?? '')) ?: 'проверяющий';
        $author = trim((string) ($task['author_name'] ?? '')) ?: 'автор задачи';
        $gip = trim((string) ($task['gip_name'] ?? '')) ?: 'ГИП проекта';
        $resultReview = in_array($status, ['review', 'pending_close'], true) && !empty($task['close_requested_at']);
        $base = ['can_accept'=>false, 'can_time'=>false];
        if ($personalAction) {
            return $base + ['can_review_time'=>true, 'bucket'=>'actions', 'step'=>$resultReview ? 'Проверить результат' : 'Согласовать задачу',
                'reason'=>$resultReview ? 'Исполнитель передал результат. Теперь требуется ваше решение.' : 'Согласование дошло до вашего этапа.',
                'button'=>$resultReview ? 'Проверить результат' : 'Открыть согласование'];
        }
        if ($resultReview || in_array($stage, ['review_lead', 'review_gip'], true)) {
            return $base + ['bucket'=>'awaiting', 'step'=>$resultReview ? 'Результат на проверке' : 'На согласовании',
                'reason'=>'Следующее решение: ' . ($stage === 'review_gip' ? $gip : $reviewer) . '.', 'button'=>'Посмотреть ход'];
        }
        if (in_array($status, ['review','pending_close'], true)) {
            return $base + ['bucket'=>'actions','step'=>'Уточнить состояние','reason'=>'Задача отмечена на проверке. Откройте карточку, чтобы уточнить следующий этап.','button'=>'Открыть задачу'];
        }
        if ($status === 'new' && ($task['task_type'] ?? 'work') !== 'delegation') {
            return ['can_accept'=>true, 'can_time'=>false, 'bucket'=>'actions', 'step'=>'Принять задачу',
                'reason'=>$author . ' назначил вам работу. Подтвердите, что можете её выполнить.', 'button'=>'Принять в работу'];
        }
        if ($status === 'correction') {
            return ['can_accept'=>false, 'can_time'=>($task['task_type'] ?? 'work') !== 'delegation', 'bucket'=>'actions', 'step'=>'Разобрать возврат',
                'reason'=>'Задача возвращена на корректировку. Посмотрите замечание перед продолжением.', 'button'=>'Открыть замечание'];
        }
        if ($status === 'blocked') {
            return $base + ['bucket'=>'actions', 'step'=>'Разобрать препятствие', 'reason'=>'Работа отмечена как заблокированная. Уточните, что мешает продолжить.', 'button'=>'Открыть задачу'];
        }
        if (($task['task_type'] ?? 'work') === 'delegation') {
            return $base + ['bucket'=>'actions', 'step'=>'Разобрать поручение', 'reason'=>'Нужно взять работу на себя, распределить её или вернуть с пояснением.', 'button'=>'Открыть поручение'];
        }
        return ['can_accept'=>false, 'can_time'=>in_array($status,['in_progress','overdue'],true), 'bucket'=>'work', 'step'=>'Продолжить работу',
            'reason'=>'Когда результат будет готов, передайте его на проверку: ' . $reviewer . '.', 'button'=>'Продолжить'];
    }

    /** One personal queue, including handoffs. Visibility was checked by the source services. */
    public static function queue(array $tasks, array $inputs): array
    {
        $rows=[];
        foreach ($tasks as $task) $rows[]=['kind'=>'task','item'=>$task,'due'=>$task['date_end'] ?? '', 'id'=>(int)$task['id']];
        foreach ($inputs as $input) $rows[]=['kind'=>'input','item'=>$input,'due'=>$input['due_date'] ?? '', 'id'=>(int)$input['id']];
        usort($rows, static fn(array $a,array $b): int => strcmp($a['due'] ?: '9999',$b['due'] ?: '9999') ?: strcmp($a['kind'],$b['kind']) ?: $a['id'] <=> $b['id']);
        return $rows;
    }
}
