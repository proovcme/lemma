<?php
$isArchivedTask = (string) ($task['project_status'] ?? 'active') === 'archived';
$canReview = !$isArchivedTask && ((int) ($task['reviewer_id'] ?? 0) === (int) current_user()['id'] || has_role(['director', 'deputy_director', 'gip']));
$delta = $task['actual_hours'] !== null && $task['planned_hours'] !== null ? (float) $task['planned_hours'] - (float) $task['actual_hours'] : null;
$taskHoursDisplay = static function (mixed $hours): string {
    if ($hours === null || $hours === '') {
        return '—';
    }

    $formatted = rtrim(rtrim(number_format((float) $hours, 2, '.', ''), '0'), '.');
    return $formatted . ' ч';
};
$plannedHoursDisplay = $taskHoursDisplay($task['planned_hours'] ?? null);
$actualHoursDisplay = $taskHoursDisplay($task['actual_hours'] ?? null);
$deltaHoursDisplay = $delta === null ? '—' : $taskHoursDisplay($delta);

$projectFolderUrl = (string) ($task['project_file_folder_url'] ?? '');
$progress = max(0, min(100, (int) $task['progress']));
$progressTone = progress_fill_class($progress);
$today = date('Y-m-d');
$deadlineState = deadline_state_class($task['date_end'] ?? null, $today);
$deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
$whenDue = (string) ($smart['when_due'] ?? '');
$whenDueDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $whenDue) ? format_date($whenDue) : $whenDue;
$dependencyLabel = $dependencyTask
    ? '#' . (int) $dependencyTask['id'] . ' · ' . $dependencyTask['project_code'] . ' · ' . $dependencyTask['title']
    : (($smart['depends_on'] ?? '') !== '' ? (string) $smart['depends_on'] : 'Нет связи');
$visibleCustomFields = array_values(array_filter($customFields, static function (array $field) use ($customValues): bool {
    $value = (string) ($customValues[$field['id']] ?? '');
    return $value !== '' || $field['type'] === 'bool';
}));
$issuances = $issuances ?? [];
$documentRevisions = $documentRevisions ?? [];
$canManageIssuances = !$isArchivedTask && (bool) ($canManageIssuances ?? false);
$lastIssuanceAccepted = (bool) ($lastIssuanceAccepted ?? false);
$blockingData = $blockingData ?? [];
$linkedIssues = $linkedIssues ?? [];
$linkedSections = $linkedSections ?? [];
$atlasRefs = $atlasRefs ?? [];
$taskTags = $taskTags ?? [];
$participants = $participants ?? ['assignee' => [], 'coauthor' => [], 'observer' => []];
$participantNames = static function (array $rows): string {
    $names = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['name'] ?? '')), $rows)));
    return $names ? implode(', ', $names) : 'нет';
};
$personMeta = static function (?string $role, ?string $department): string {
    $parts = [];
    $roleLabel = \App\Services\RoleService::label((string) $role);
    if ($roleLabel !== '') {
        $parts[] = $roleLabel;
    }
    $department = trim((string) $department);
    if ($department !== '') {
        $parts[] = $department;
    }

    return $parts ? implode(' · ', $parts) : 'роль не указана';
};
$approvalHistory = $approvalHistory ?? [];
$logs = $logs ?? [];
$approvalStage = (string) ($task['approval_stage'] ?? 'draft');
$canSubmitApproval = !$isArchivedTask && (bool) ($canSubmitApproval ?? false);
$canLeadApprove = !$isArchivedTask && (bool) ($canLeadApprove ?? false);
$canGipApprove = !$isArchivedTask && (bool) ($canGipApprove ?? false);
$canAcceptCloseByAuthor = !$isArchivedTask && (bool) ($canAcceptCloseByAuthor ?? false);
$canAcceptCloseByGip = !$isArchivedTask && (bool) ($canAcceptCloseByGip ?? false);
$canRespondAssignment = !$isArchivedTask && (bool) ($canRespondAssignment ?? false);
$canLogTime = !$isArchivedTask && (bool) ($canLogTime ?? false);
$closeRequiresGip = (bool) ($closeRequiresGip ?? false);
$closeAuthorAccepted = (bool) ($closeAuthorAccepted ?? false);
$closeGipAccepted = (bool) ($closeGipAccepted ?? false);
$canDecideReviewCycle = !$isArchivedTask && (bool) ($canDecideReviewCycle ?? false);
$pendingDeadlineShift = $pendingDeadlineShift ?? null;
$canDecideDeadlineShift = !$isArchivedTask && (bool) ($canDecideDeadlineShift ?? false);
$isReviewTask = (string) ($task['task_type'] ?? 'work') === 'review';
$isDelegationTask = (string) ($task['task_type'] ?? 'work') === 'delegation';
$canManageDelegation = !$isArchivedTask && (bool) ($canManageDelegation ?? false);
$canEdit = !$isArchivedTask && (bool) ($canEdit ?? false);
$editMode = !$isArchivedTask && (bool) ($editMode ?? false);
$canCreateTasks = \App\Services\PermissionService::canCreateTasks(current_user() ?? []);
$laborEstimate = $laborEstimate ?? null;
$isLaborEstimateTask = (string) ($task['task_type'] ?? 'work') === 'labor_estimate' && is_array($laborEstimate);
$canSubmitLaborEstimate = !$isArchivedTask && (bool) ($canSubmitLaborEstimate ?? false);
$canGipApproveLaborEstimate = !$isArchivedTask && (bool) ($canGipApproveLaborEstimate ?? false);
$canDirectorApproveLaborEstimate = !$isArchivedTask && (bool) ($canDirectorApproveLaborEstimate ?? false);
$canSeeLaborEstimateMoney = (bool) ($canSeeLaborEstimateMoney ?? false);
$canSeeLaborEstimateRate = (bool) ($canSeeLaborEstimateRate ?? false);
$canAdminCloseTask = !$isArchivedTask && (bool) ($canAdminCloseTask ?? false);
$canAdminDeleteTask = !$isArchivedTask && (bool) ($canAdminDeleteTask ?? false);
$attachments = is_array($attachments ?? null) ? $attachments : [];
$canUploadAttachments = !$isArchivedTask && (bool) ($canUploadAttachments ?? false);
$currentUserId = (int) (current_user()['id'] ?? 0);
$formatAttachmentSize = static function (mixed $bytes): string {
    $size = max(0, (int) $bytes);
    if ($size >= 1048576) {
        return rtrim(rtrim(number_format($size / 1048576, 1, '.', ''), '0'), '.') . ' МБ';
    }
    if ($size >= 1024) {
        return (string) max(1, (int) round($size / 1024)) . ' КБ';
    }
    return $size . ' Б';
};
$laborNumber = static function (mixed $value, int $precision = 2): string {
    if ($value === null || $value === '') {
        return '';
    }

    $formatted = number_format((float) $value, $precision, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
};
$laborUsers = $users ?? [];
$laborUserSelect = static function (string $name, mixed $selected, string $class = '') use ($laborUsers): void {
    echo '<select name="' . e($name) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . '><option value=""></option>';
    foreach ($laborUsers as $userOption) {
        $isSelected = (string) $selected !== '' && (int) $selected === (int) $userOption['id'];
        echo '<option value="' . (int) $userOption['id'] . '"' . ($isSelected ? ' selected' : '') . '>' . e($userOption['name'] . (($userOption['department'] ?? '') ? ' · ' . $userOption['department'] : '')) . '</option>';
    }
    echo '</select>';
};
$reviewLeadCycleStartedAt = '';
foreach ($logs as $log) {
    if ((string) ($log['field'] ?? '') === 'approval_stage' && (string) ($log['new_val'] ?? '') === 'review_lead') {
        $createdAt = (string) ($log['created_at'] ?? '');
        if ($createdAt !== '' && strcmp($createdAt, $reviewLeadCycleStartedAt) > 0) {
            $reviewLeadCycleStartedAt = $createdAt;
        }
    }
}
$approvalDone = [];
$approvalLastByStage = [];
$reviewLeadApprovedByUser = [];
$reviewLeadLastByUser = [];
foreach ($approvalHistory as $approvalEvent) {
    $approvalLastByStage[(string) $approvalEvent['stage']] = $approvalEvent;
    if ((string) $approvalEvent['stage'] === 'review_lead') {
        $eventCreatedAt = (string) ($approvalEvent['created_at'] ?? '');
        $isCurrentLeadCycle = $reviewLeadCycleStartedAt === '' || $eventCreatedAt === '' || strcmp($eventCreatedAt, $reviewLeadCycleStartedAt) >= 0;
        if ($isCurrentLeadCycle) {
            $reviewLeadLastByUser[(int) ($approvalEvent['approved_by'] ?? 0)] = $approvalEvent;
        }
        if ($isCurrentLeadCycle && (string) $approvalEvent['decision'] === 'approved') {
            $reviewLeadApprovedByUser[(int) ($approvalEvent['approved_by'] ?? 0)] = $approvalEvent;
        }
    }
    if (in_array((string) $approvalEvent['decision'], ['approved', 'issued'], true)) {
        $approvalDone[(string) $approvalEvent['stage']] = $approvalEvent;
    }
}
$executorEvent = null;
foreach ($logs as $log) {
    if ((string) ($log['field'] ?? '') === 'approval_stage' && (string) ($log['new_val'] ?? '') === 'review_lead') {
        $executorEvent = [
            'created_at' => $log['created_at'] ?? '',
            'approved_by_name' => $task['assignee_name'] ?: ($log['user_name'] ?? ''),
            'decision' => 'approved',
        ];
    }
}
// Состояние этапа считаем строго по ТЕКУЩЕМУ approval_stage, а не по наличию
// старых записей согласования. Так после возврата исполнителю пройденные этапы
// (промежуточные согласующие / ГИП) сбрасываются в «ожидает», а не остаются с зелёной галкой.
$approvalStepState = static function (string $step) use ($approvalStage): string {
    $doneByStage = [
        'executor' => in_array($approvalStage, ['review_lead', 'review_gip', 'approved', 'issued'], true),
        'review_lead' => in_array($approvalStage, ['review_gip', 'approved', 'issued'], true),
        'review_gip' => in_array($approvalStage, ['approved', 'issued'], true),
        'issued' => $approvalStage === 'issued',
    ];
    $currentByStage = [
        'executor' => $approvalStage === 'draft',
        'review_lead' => $approvalStage === 'review_lead',
        'review_gip' => $approvalStage === 'review_gip',
        'issued' => $approvalStage === 'approved',
    ];

    if ($doneByStage[$step] ?? false) {
        return 'done';
    }

    return ($currentByStage[$step] ?? false) ? 'active' : 'pending';
};
$lastIssuance = $issuances ? $issuances[array_key_last($issuances)] : null;
$fallbackApprovalDate = (string) ($task['updated_at'] ?? '');
$assigneeId = (int) ($task['assignee_id'] ?? 0);
$reviewerId = (int) ($task['reviewer_id'] ?? 0);
$projectGipId = (int) ($task['project_gip_user_id'] ?? 0);
$reviewerIsFinalApprover = \App\Services\RoleService::isAny((string) ($task['reviewer_role'] ?? ''), [\App\Services\RoleService::GIP, \App\Services\RoleService::DEPUTY_DIRECTOR, \App\Services\RoleService::DIRECTOR]);
$isIssuanceTask = (string) ($task['task_type'] ?? 'work') === 'issuance';
$assigneeCanSelfApprove = $isIssuanceTask && \App\Services\PermissionService::canSelfApproveIssuanceRole((string) ($task['assignee_role'] ?? ''));
$isAssigneeSelfApprovalRoute = $assigneeCanSelfApprove && ($projectGipId <= 0 || $projectGipId === $assigneeId);
$centralRoleRank = static function (?string $role): ?int {
    $role = \App\Services\RoleService::normalize($role);
    return [
        \App\Services\RoleService::CHIEF_SPECIALIST => 10,
        \App\Services\RoleService::GROUP_LEAD => 20,
        \App\Services\RoleService::DEPUTY_DEPARTMENT_HEAD => 30,
        \App\Services\RoleService::DEPARTMENT_HEAD => 40,
    ][$role] ?? null;
};
$centralRoute = [];
$addCentralReviewer = static function (int $id, string $name, string $role) use (&$centralRoute, $assigneeId, $projectGipId, $centralRoleRank): void {
    if ($id <= 0 || $id === $assigneeId || ($projectGipId > 0 && $id === $projectGipId)) {
        return;
    }
    if (\App\Services\RoleService::isAny($role, [\App\Services\RoleService::GIP, \App\Services\RoleService::DEPUTY_DIRECTOR, \App\Services\RoleService::DIRECTOR])) {
        return;
    }
    if ($centralRoleRank($role) === null || !\App\Services\PermissionService::canAcceptWork(['role' => $role])) {
        return;
    }
    $centralRoute[$id] = ['id' => $id, 'name' => $name, 'role' => $role];
};
if (!$reviewerIsFinalApprover) {
    $addCentralReviewer($reviewerId, (string) ($task['reviewer_name'] ?? ''), (string) ($task['reviewer_role'] ?? ''));
}
foreach ($approvalHistory as $approvalEvent) {
    if ((string) ($approvalEvent['stage'] ?? '') === 'review_lead' && (string) ($approvalEvent['decision'] ?? '') === 'approved') {
        $addCentralReviewer((int) ($approvalEvent['approved_by'] ?? 0), (string) ($approvalEvent['approved_by_name'] ?? ''), (string) ($approvalEvent['approved_by_role'] ?? ''));
    }
}
foreach (($participants['observer'] ?? []) as $observer) {
    $addCentralReviewer((int) ($observer['id'] ?? 0), (string) ($observer['name'] ?? ''), (string) ($observer['user_role'] ?? ''));
}
$centralRoute = array_values($centralRoute);
usort($centralRoute, static function (array $a, array $b) use ($centralRoleRank): int {
    $rankA = $centralRoleRank((string) ($a['role'] ?? '')) ?? 999;
    $rankB = $centralRoleRank((string) ($b['role'] ?? '')) ?? 999;
    return $rankA <=> $rankB ?: (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
});
$leadStepNeeded = $centralRoute !== []
    || isset($approvalDone['review_lead'])
    || $approvalStage === 'review_lead'
    || ($reviewerId > 0 && $reviewerId !== $assigneeId && ($projectGipId <= 0 || $reviewerId !== $projectGipId) && !$reviewerIsFinalApprover);
$gipStepNeeded = (string) ($task['task_type'] ?? 'work') !== 'bim_family_request'
    && (
        isset($approvalDone['review_gip'])
        || in_array($approvalStage, ['review_gip', 'approved', 'issued'], true)
        || $projectGipId > 0
        || $isAssigneeSelfApprovalRoute
    );
$gipStepLabel = $isAssigneeSelfApprovalRoute ? 'Самосогласование' : 'ГИП';
$gipAssigned = $isAssigneeSelfApprovalRoute
    ? trim((string) ($task['assignee_name'] ?? ''))
    : trim((string) ($task['project_gip_name'] ?? ''));
$approvalSteps = [
    ['key' => 'executor', 'label' => 'Исполнитель', 'assigned' => (string) ($task['assignee_name'] ?? ''), 'event' => $executorEvent],
];
if ($leadStepNeeded) {
    if ($centralRoute !== []) {
        foreach ($centralRoute as $centralReviewer) {
            $centralId = (int) ($centralReviewer['id'] ?? 0);
            $centralDone = in_array($approvalStage, ['review_gip', 'approved', 'issued'], true)
                || ($approvalStage === 'review_lead' && isset($reviewLeadApprovedByUser[$centralId]));
            $centralActive = !$centralDone && $approvalStage === 'review_lead' && $reviewerId === $centralId;
            $approvalSteps[] = [
                'key' => 'review_lead:' . $centralId,
                'label' => \App\Services\RoleService::label((string) ($centralReviewer['role'] ?? '')),
                'assigned' => (string) ($centralReviewer['name'] ?? ''),
                'event' => $reviewLeadApprovedByUser[$centralId] ?? ($reviewLeadLastByUser[$centralId] ?? null),
                'state' => $centralDone ? 'done' : ($centralActive ? 'active' : 'pending'),
            ];
        }
    } else {
        $leadAssigned = (string) ($task['reviewer_name'] ?? '');
        $approvalSteps[] = ['key' => 'review_lead', 'label' => 'Промежуточное согласование', 'assigned' => $leadAssigned, 'event' => $approvalDone['review_lead'] ?? ['created_at' => $fallbackApprovalDate, 'approved_by_name' => $leadAssigned !== '' ? $leadAssigned : 'согласующий']];
    }
}
if ($gipStepNeeded) {
    $approvalSteps[] = ['key' => 'review_gip', 'label' => $gipStepLabel, 'assigned' => $gipAssigned, 'event' => $approvalDone['review_gip'] ?? ['created_at' => $fallbackApprovalDate, 'approved_by_name' => $gipAssigned !== '' ? $gipAssigned : 'ГИП']];
}
$approvalSteps[] = ['key' => 'issued', 'label' => 'Выдача', 'assigned' => '', 'event' => $approvalDone['issued'] ?? ($lastIssuance ? ['created_at' => $lastIssuance['issued_at'] ?? '', 'approved_by_name' => $lastIssuance['issued_by_name'] ?: 'сотрудник'] : null)];
// ТИМ-заявки согласовываются без ГИПа — не показываем эту ступень в цепочке.
if (($task['task_type'] ?? 'work') === 'bim_family_request') {
    $approvalSteps = array_values(array_filter($approvalSteps, static fn (array $step): bool => $step['key'] !== 'review_gip'));
}
$closeAuthorEvent = $approvalLastByStage['close_author'] ?? null;
$closeGipEvent = $approvalLastByStage['close_gip'] ?? null;
$closeSteps = [
    ['key' => 'close_author', 'label' => 'Постановщик', 'done' => $closeAuthorAccepted, 'active' => in_array($task['status'], ['review', 'pending_close'], true) && !$closeAuthorAccepted, 'event' => $closeAuthorEvent],
];
if ($closeRequiresGip) {
    $closeSteps[0]['label'] = 'РП / постановщик';
    $closeSteps[0]['optional'] = true;
    $closeSteps[] = ['key' => 'close_gip', 'label' => 'ГИП', 'done' => $closeGipAccepted, 'active' => in_array($task['status'], ['review', 'pending_close'], true) && !$closeGipAccepted, 'event' => $closeGipEvent];
}
$approvalHistoryDecision = static fn (string $decision): string => [
    'approved' => 'Согласовано',
    'rejected' => 'Возврат',
    'issued' => 'Выдача',
    'submitted' => 'Подал на проверку',
][$decision] ?? $decision;
$approvalHistoryClass = static fn (string $decision): string => [
    'approved' => 'approval-pill--green',
    'rejected' => 'approval-pill--red',
    'issued' => 'approval-pill--blue',
    'submitted' => 'approval-pill--blue',
][$decision] ?? 'approval-pill--blue';
// Объединённый журнал движения: решения (task_approvals) + подачи на проверку,
// которые раньше попадали только в общую «Историю» и не были видны в цепочке.
$approvalMovements = [];
foreach ($approvalHistory as $event) {
    $approvalMovements[] = [
        'created_at' => (string) ($event['created_at'] ?? ''),
        'name' => (string) ($event['approved_by_name'] ?? ''),
        'stage' => (string) ($event['stage'] ?? ''),
        'decision' => (string) ($event['decision'] ?? ''),
        'comment' => (string) ($event['comment'] ?? ''),
    ];
}
foreach ($logs as $log) {
    if ((string) ($log['field'] ?? '') !== 'approval_stage') {
        continue;
    }
    $from = (string) ($log['old_val'] ?? '');
    $to = (string) ($log['new_val'] ?? '');
    if (($from === 'draft' && $to === 'review_lead') || ($from === 'review_lead' && $to === 'review_gip')) {
        $approvalMovements[] = [
            'created_at' => (string) ($log['created_at'] ?? ''),
            'name' => (string) ($log['user_name'] ?? ''),
            'stage' => $to,
            'decision' => 'submitted',
            'comment' => '',
        ];
    }
}
usort($approvalMovements, static fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));
$exchangeContext = trim((string) ($task['section'] ?: ($task['discipline'] ?: ($task['volume'] ?: $task['title']))));
$exchangeLabel = $exchangeContext !== '' ? $exchangeContext : '#' . (int) $task['id'];
$exchangeNoun = $exchangeContext !== '' ? 'разделу ' . $exchangeContext : 'задаче #' . (int) $task['id'];
$assignmentBaseQuery = [
    'project_id' => (int) $task['project_id'],
    'parent_id' => (int) $task['id'],
    'task_type' => 'assignment',
    'discipline' => (string) ($task['discipline'] ?? ''),
    'volume' => (string) ($task['volume'] ?? ''),
    'section' => (string) ($task['section'] ?? ''),
    'date_end' => (string) ($task['date_end'] ?? ''),
    'when_due' => (string) ($task['date_end'] ?? ''),
];
$assignmentUrl = static function (array $query) use ($assignmentBaseQuery): string {
    $query = array_filter($assignmentBaseQuery + $query, static fn (mixed $value): bool => $value !== null && $value !== '');
    return url('/tasks/new') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$issueAssignmentUrl = $assignmentUrl([
    'task_intent' => 'assign_out',
    'title' => 'Выдать задание: ' . $exchangeLabel,
    'what' => 'Передать задание по ' . $exchangeNoun . '.',
    'why' => 'Выдача из задачи #' . (int) $task['id'] . ': ' . (string) $task['title'],
]);
$requestAssignmentUrl = $assignmentUrl([
    'task_intent' => 'assign_request',
    'title' => 'Запрос задания: ' . $exchangeLabel,
    'what' => 'Подготовить задание по ' . $exchangeNoun . '.',
    'why' => 'Запрос из задачи #' . (int) $task['id'] . ': ' . (string) $task['title'],
]);
$taskMailLinks = [];
$addTaskMailLink = static function (string $label, string $target) use (&$taskMailLinks): void {
    $label = trim($label);
    $target = trim($target);
    if ($target === '') {
        return;
    }

    $key = mb_strtolower($label . '|' . $target, 'UTF-8');
    $taskMailLinks[$key] = [
        'label' => $label !== '' ? $label : 'Ссылка',
        'target' => $target,
    ];
};
foreach ($customFields as $field) {
    if (!in_array((string) ($field['type'] ?? ''), ['link', 'links'], true)) {
        continue;
    }

    foreach (custom_link_entries($customValues[$field['id']] ?? '') as $entry) {
        $addTaskMailLink((string) ($entry['label'] ?: ($field['label'] ?? 'Вложение')), (string) $entry['url']);
    }
}
if ($projectFolderUrl !== '') {
    $addTaskMailLink('Папка проекта', $projectFolderUrl);
}
foreach ($attachments as $attachment) {
    $addTaskMailLink(
        (string) ($attachment['filename'] ?? 'Файл'),
        app_url('/tasks/' . (int) $task['id'] . '/attachments/' . (int) ($attachment['id'] ?? 0))
    );
}
$taskMailLinks = array_values($taskMailLinks);
$taskMailHours = static function (mixed $hours): string {
    if ($hours === null || $hours === '') {
        return 'не синхронизирован';
    }

    return rtrim(rtrim(number_format((float) $hours, 2, '.', ''), '0'), '.') . ' ч';
};
$taskMailAttachmentsText = '';
if ($taskMailLinks) {
    $attachLines = [];
    foreach ($taskMailLinks as $mailLink) {
        $attachLines[] = '- ' . $mailLink['label'] . ': ' . $mailLink['target'];
    }
    $taskMailAttachmentsText = implode("\r\n", $attachLines);
} else {
    $taskMailAttachmentsText = '- не указаны в задаче';
}

$taskPublicUrl = (string) ($taskPublicUrl ?? app_url('/tasks/' . (int) $task['id']));
$taskMail = \App\Services\NotificationTemplateService::render('task_mail', [
    '{task_id}'       => (string) (int) $task['id'],
    '{task_title}'    => (string) $task['title'],
    '{task_status}'   => task_status_label($task['status']),
    '{task_type}'     => task_type_label($task['task_type'] ?? 'work'),
    '{project_code}'  => (string) $task['project_code'],
    '{project_title}' => (string) $task['project_title'],
    '{assignee}'      => (string) ($task['assignee_name'] ?: 'не назначен'),
    '{author}'        => (string) ($task['author_name'] ?: 'не указан'),
    '{reviewer}'      => (string) ($task['reviewer_name'] ?: 'не назначен'),
    '{deadline}'      => $deadlineDisplay,
    '{progress}'      => (string) $progress,
    '{planned_hours}' => $taskMailHours($task['planned_hours'] ?? null),
    '{actual_hours}'  => $taskMailHours($task['actual_hours'] ?? null),
    '{task_url}'      => $taskPublicUrl,
    '{attachments}'   => $taskMailAttachmentsText,
]);
$taskMailAttachmentSummary = $taskMailLinks ? (count($taskMailLinks) === 1 ? $taskMailLinks[0]['label'] : count($taskMailLinks) . ' ссылок') : 'без файловых ссылок';
$taskMailBodyId = 'task-mail-body-' . (int) $task['id'];
$issuanceFoldOpen = !$isLaborEstimateTask && !$isReviewTask && ($issuances || $canManageIssuances || $approvalStage === 'approved');
$isCloseReviewPending = !$isLaborEstimateTask
    && !$isReviewTask
    && in_array((string) ($task['status'] ?? ''), ['review', 'pending_close'], true)
    && trim((string) ($task['close_requested_at'] ?? '')) !== '';
$closeFoldOpen = !$isLaborEstimateTask && !$isReviewTask && !$isCloseReviewPending && (in_array($task['status'], ['review', 'pending_close'], true) || $closeAuthorEvent || $closeGipEvent);
$canRequestCloseCycle = !$isLaborEstimateTask
    && !$isReviewTask
    && ((int) ($task['assignee_id'] ?? 0) === (int) current_user()['id'] || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1)
    && in_array($task['status'], ['in_progress', 'overdue', 'correction'], true);
$taskRouteFoldOpen = $canSubmitApproval
    || ($canLeadApprove && $approvalStage === 'review_lead')
    || ($canGipApprove && $approvalStage === 'review_gip')
    || $canRequestCloseCycle
    || $isCloseReviewPending
    || $closeFoldOpen;
$hasPrimaryTaskFlow = !$isLaborEstimateTask && !$isReviewTask && !$isDelegationTask;
?>
<div class="task-detail-stack" data-tour-surface="task-detail">
    <section class="panel task-main task-work-window">
        <div class="task-work-window__bar">
            <span>Рабочее окно</span>
            <div class="task-work-window__bar-actions">
                <strong><?= e(task_type_label($task['task_type'] ?? 'work')) ?> #<?= (int) $task['id'] ?></strong>
                <button class="task-mail-chip" type="button" data-copy-link="<?= e($taskPublicUrl) ?>">Ссылка</button>
                <button class="task-mail-chip" type="button" data-copy="#<?= e($taskMailBodyId) ?>" data-copy-label="Текст">Текст</button>
            </div>
        </div>
        <pre class="credential-card__body" id="<?= e($taskMailBodyId) ?>"><?= e($taskMail['body']) ?></pre>
        <?php if ($isArchivedTask): ?>
            <div class="archive-banner">Проект в архиве · <?= e(format_date($task['project_archived_at'] ?? '') ?: 'дата не указана') ?></div>
        <?php endif; ?>
        <?php if ($editMode && $canEdit): ?>
            <?php require BASE_PATH . '/app/Views/tasks/form.php'; ?>
        <?php else: ?>
            <div class="task-passport" data-tour="task-passport">
                <div class="task-passport__head">
                    <div class="task-passport__crumbs">
                        <span><?= e($task['project_code']) ?></span>
                        <span><?= e(task_type_label($task['task_type'] ?? 'work')) ?></span>
                    </div>
                    <div class="task-passport__idline">
                        <span class="task-code">#<?= (int) $task['id'] ?></span>
                        <span class="status status--<?= e($task['status']) ?>"><?= e(task_status_label($task['status'])) ?></span>
                    </div>
                    <h2><?= e($task['title']) ?></h2>
                    <section class="task-people-grid" data-task-people-grid aria-label="Участники задачи">
                        <article class="task-person-card task-person-card--work">
                            <span class="task-person-card__label">Что сделать</span>
                            <strong><?= e($task['title']) ?></strong>
                            <small><?= e(trim((string) ($smart['what'] ?? '')) !== '' ? (string) $smart['what'] : 'Описание не указано') ?></small>
                        </article>
                        <article class="task-person-card task-person-card--primary">
                            <span class="task-person-card__label">Делает</span>
                            <div class="task-person-card__body">
                                <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color((string) ($task['assignee_name'] ?? ''))) ?>"><?= e(initials((string) ($task['assignee_name'] ?? ''))) ?></span>
                                <div>
                                    <strong><?= e($task['assignee_name'] ?: 'Не назначен') ?></strong>
                                    <small>Исполнитель · <?= e($personMeta((string) ($task['assignee_role'] ?? ''), (string) ($task['assignee_department'] ?? ''))) ?></small>
                                </div>
                            </div>
                        </article>
                        <article class="task-person-card">
                            <span class="task-person-card__label">Сдать кому</span>
                            <div class="task-person-card__body">
                                <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color((string) ($task['reviewer_name'] ?? ''))) ?>"><?= e(initials((string) ($task['reviewer_name'] ?? ''))) ?></span>
                                <div>
                                    <strong><?= e($task['reviewer_name'] ?: 'Не назначен') ?></strong>
                                    <small>Принимает результат · <?= e($personMeta((string) ($task['reviewer_role'] ?? ''), (string) ($task['reviewer_department'] ?? ''))) ?></small>
                                </div>
                            </div>
                        </article>
                        <article class="task-person-card task-person-card--deadline">
                            <span class="task-person-card__label">Срок</span>
                            <strong class="task-person-card__date <?= e($deadlineState) ?>"><?= e($deadlineDisplay !== '—' ? $deadlineDisplay : 'Не задан') ?></strong>
                            <small>Плановая дата завершения<?= $whenDueDisplay !== '' && $whenDueDisplay !== $deadlineDisplay ? ' · SMART: ' . e($whenDueDisplay) : '' ?></small>
                        </article>
                        <article class="task-person-card task-person-card--hours">
                            <span class="task-person-card__label">План</span>
                            <strong><?= e($plannedHoursDisplay) ?></strong>
                            <small>За сколько часов сделать</small>
                        </article>
                        <article class="task-person-card">
                            <span class="task-person-card__label">Поставил</span>
                            <div class="task-person-card__body">
                                <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color((string) ($task['author_name'] ?? ''))) ?>"><?= e(initials((string) ($task['author_name'] ?? ''))) ?></span>
                                <div>
                                    <strong><?= e($task['author_name'] ?: 'Не указан') ?></strong>
                                    <small>Постановщик<?= trim((string) ($task['author_department'] ?? '')) !== '' ? ' · ' . e((string) $task['author_department']) : '' ?></small>
                                </div>
                            </div>
                        </article>
                        <?php if (($participants['coauthor'] ?? []) !== []): ?>
                            <article class="task-person-card task-person-card--secondary">
                                <span class="task-person-card__label">Соавторы</span>
                                <div class="task-person-card__list">
                                    <?php foreach ($participants['coauthor'] as $participant): ?>
                                        <?php $participantName = trim((string) ($participant['name'] ?? '')); ?>
                                        <span class="task-person-card__chip">
                                            <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color($participantName)) ?>"><?= e(initials($participantName)) ?></span>
                                            <span><?= e($participantName !== '' ? $participantName : 'Не указан') ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endif; ?>
                        <?php if (($participants['observer'] ?? []) !== []): ?>
                            <article class="task-person-card task-person-card--secondary">
                                <span class="task-person-card__label">Наблюдают</span>
                                <div class="task-person-card__list">
                                    <?php foreach ($participants['observer'] as $participant): ?>
                                        <?php $participantName = trim((string) ($participant['name'] ?? '')); ?>
                                        <span class="task-person-card__chip">
                                            <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color($participantName)) ?>"><?= e(initials($participantName)) ?></span>
                                            <span><?= e($participantName !== '' ? $participantName : 'Не указан') ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endif; ?>
                    </section>
                    <div class="task-tags">
                        <span class="task-tag task-tag--project"><?= e($task['project_title']) ?></span>
                        <?php if (($task['task_type'] ?? 'work') === 'assignment'): ?><span class="task-tag task-tag--system">Задание</span><?php endif; ?>
                        <?php if (($task['task_type'] ?? 'work') === 'issuance'): ?><span class="task-tag task-tag--system">Выдача</span><?php endif; ?>
                        <?php if (($task['task_type'] ?? 'work') === 'delegation'): ?><span class="task-tag task-tag--system">Делегирование</span><?php endif; ?>
                        <?php if (($task['task_type'] ?? 'work') === 'bim_family_request'): ?><span class="task-tag task-tag--system">Заявка ТИМ</span><?php endif; ?>
                        <?php if (($task['task_type'] ?? 'work') === 'review'): ?><span class="task-tag task-tag--system">Проверка</span><?php endif; ?>
                        <span class="tag tag-<?= e(mb_strtolower((string) ($task['discipline'] ?: 'пз'), 'UTF-8')) ?>"><?= e($task['discipline'] ?: 'Без дисциплины') ?></span>
                        <span class="task-tag"><?= e($task['volume'] ?: 'Без тома') ?></span>
                        <span class="task-tag">Код раздела: <?= e(($task['cost_group_code'] ?? '') ?: ($task['section'] ?: 'не определён')) ?></span>
                        <span class="task-tag">Важность: <?= e(priority_label($task['priority'])) ?></span>
                        <?php foreach ($taskTags as $tag): ?>
                            <span class="task-tag task-tag--custom" style="--task-tag-color: <?= e($tag['color']) ?>">#<?= e($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <dl class="task-fact-strip" data-task-fact-strip>
                        <div>
                            <dt>План</dt>
                            <dd><?= e($plannedHoursDisplay) ?></dd>
                        </div>
                        <div>
                            <dt>Факт</dt>
                            <dd><?= e($actualHoursDisplay) ?></dd>
                        </div>
                        <div>
                            <dt>Остаток</dt>
                            <dd class="<?= $delta !== null && $delta < 0 ? 'metric-value--bad' : ($delta !== null && $delta > 0 ? 'metric-value--good' : '') ?>"><?= e($deltaHoursDisplay) ?></dd>
                        </div>
                        <div>
                            <dt>Прогресс</dt>
                            <dd><?= e((string) $progress) ?>%</dd>
                        </div>
                    </dl>
                </div>
                <section class="smart-card" data-tour="task-smart">
                    <div class="smart-card__head">
                        <span>Постановка задачи</span>
                        <small>SMART</small>
                    </div>
                    <div class="smart-row">
                        <div class="smart-row__mark">Ч</div>
                        <div class="smart-row__body">
                            <span>Что сделать</span>
                            <p><?= nl2br(e(($smart['what'] ?? '') !== '' ? $smart['what'] : 'Описание не указано')) ?></p>
                        </div>
                    </div>
                    <div class="smart-row">
                        <div class="smart-row__mark">К</div>
                        <div class="smart-row__body">
                            <span>Когда нужно</span>
                            <p class="<?= e($deadlineState) ?>"><?= e($whenDueDisplay ?: $deadlineDisplay) ?></p>
                        </div>
                    </div>
                    <div class="smart-row">
                        <div class="smart-row__mark">З</div>
                        <div class="smart-row__body">
                            <span>Зачем это важно</span>
                            <p><?= nl2br(e(($smart['why'] ?? '') !== '' ? $smart['why'] : 'Контекст не указан')) ?></p>
                        </div>
                    </div>
                    <div class="smart-row">
                        <div class="smart-row__mark">К</div>
                        <div class="smart-row__body">
                            <span>Кто отвечает</span>
                            <p>Исполнитель: <?= e($task['assignee_name'] ?: 'не назначен') ?> · проверяющий: <?= e($task['reviewer_name'] ?: 'не назначен') ?> · постановщик: <?= e($task['author_name'] ?: 'не указан') ?></p>
                            <?php if (($participants['coauthor'] ?? []) || ($participants['observer'] ?? [])): ?>
                                <small>
                                    Соавтор: <?= e($participantNames($participants['coauthor'] ?? [])) ?> ·
                                    согласующие / наблюдатели: <?= e($participantNames($participants['observer'] ?? [])) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="smart-row smart-row--dependency">
                        <div class="smart-row__mark">↑</div>
                        <div class="smart-row__body">
                            <span>Зависимость</span>
                            <?php if ($dependencyTask): ?>
                                <p><a class="task-link" href="<?= url('/tasks/' . $dependencyTask['id']) ?>" data-task-drawer-link><?= e($dependencyLabel) ?></a></p>
                            <?php else: ?>
                                <p><?= e($dependencyLabel) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php if ($atlasRefs): ?>
                    <section class="panel task-atlas-links">
                        <div class="panel__head">
                            <h2>Просмотр ТИМ</h2>
                            <span><?= count($atlasRefs) ?></span>
                        </div>
                        <div class="atlas-ref-list">
                            <?php foreach ($atlasRefs as $atlasRef): ?>
                                <div class="atlas-ref-row">
                                    <a href="<?= e($atlasRef['atlas_url']) ?>" target="_blank" rel="noreferrer">
                                        <strong><?= e($atlasRef['element_name'] ?: $atlasRef['element_id'] ?: 'Модель') ?></strong>
                                        <span><?= e($atlasRef['model_label'] ?: $atlasRef['model_id'] ?: 'Просмотр ТИМ') ?></span>
                                    </a>
                                    <?php if (!empty($atlasRef['viewpoint_url'])): ?>
                                        <a class="btn btn-outline btn-sm" href="<?= e($atlasRef['viewpoint_url']) ?>" target="_blank" rel="noreferrer">Открыть точку обзора</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($canRespondAssignment): ?>
                    <section class="panel task-assignment-response">
                        <div class="panel__head">
                            <h2>Ответ исполнителя</h2>
                            <span>принять или отклонить</span>
                        </div>
                        <p class="muted">Если задача принята, <?= e(app_product_name()) ?> автоматически запишет дату начала и переведёт задачу в работу.</p>
                        <div class="approval-actions">
                            <form method="post" action="<?= url('/tasks/' . $task['id'] . '/assignment-response') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="decision" value="accepted">
                                <button class="btn btn--red" type="submit">Принять в работу</button>
                            </form>
                            <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/assignment-response') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="decision" value="rejected">
                                <textarea name="comment" rows="3" placeholder="Почему задачу нужно уточнить или переназначить" required></textarea>
                                <button class="btn btn-outline" type="submit">Отклонить</button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($isDelegationTask): ?>
                    <section class="panel task-delegation-panel">
                        <div class="panel__head">
                            <h2>Распределение руководителем</h2>
                            <span><?= count($children) > 0 ? count($children) . ' подзадач' : 'на распределении' ?></span>
                        </div>
                        <p class="muted">ГИП поставил ответственность руководителю. Руководитель может взять работу на себя или раздать дочерние задачи своим исполнителям. Часы считаются по дочерним задачам; родительская делегирующая задача в табель не попадает.</p>
                        <?php if ($canManageDelegation): ?>
                            <div class="approval-actions task-delegation-actions">
                                <form method="post" action="<?= url('/tasks/' . $task['id'] . '/delegation/take') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--red" type="submit">Взять на себя</button>
                                </form>
                                <a class="btn btn-outline" href="<?= url('/tasks/new') ?>?project_id=<?= (int) $task['project_id'] ?>&parent_id=<?= (int) $task['id'] ?>&task_type=work">Создать задачу исполнителю</a>
                            </div>
                            <form class="approval-reject-form task-delegation-return" method="post" action="<?= url('/tasks/' . $task['id'] . '/delegation/return') ?>">
                                <?= csrf_field() ?>
                                <textarea name="comment" rows="2" placeholder="Что нужно уточнить у ГИПа перед распределением" required></textarea>
                                <button class="btn btn-outline" type="submit">Вернуть ГИПу</button>
                            </form>
                        <?php else: ?>
                            <p class="muted">Действия распределения доступны назначенному руководителю отдела.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if ($hasPrimaryTaskFlow): ?>
                    <div class="task-primary-flow" data-tour="task-primary-flow">
                        <?php if ($isCloseReviewPending): ?>
                            <section class="panel form-stack">
                                <div class="panel__head">
                                    <h2>Проверка результата</h2>
                                    <span class="status status--<?= e($task['status']) ?>"><?= e(task_status_label($task['status'])) ?></span>
                                </div>
                                <p class="muted">
                                    Проверяющий <?= e($task['reviewer_name'] ?: 'не назначен') ?> принимает результат этой задачи.
                                </p>
                                <?php if ($canDecideReviewCycle): ?>
                                    <div class="approval-actions">
                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/review-cycle') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="approved">
                                            <button class="btn btn--red" type="submit">Принять результат</button>
                                        </form>
                                        <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/review-cycle') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="rejected">
                                            <textarea name="comment" rows="3" placeholder="Что нужно исправить" required></textarea>
                                            <button class="btn btn-outline" type="submit">Вернуть на корректировку</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <p class="muted">Решение по проверке доступно назначенному проверяющему.</p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if (is_array($pendingDeadlineShift)): ?>
                            <section class="panel form-stack">
                                <div class="panel__head">
                                    <h2>Проверка переноса срока</h2>
                                    <span class="status status--review">На проверке</span>
                                </div>
                                <p class="muted">
                                    <?= e($pendingDeadlineShift['shifted_by_name'] ?: 'Пользователь') ?> просит изменить срок:
                                    <?= e(format_date($pendingDeadlineShift['date_old'])) ?> → <?= e(format_date($pendingDeadlineShift['date_new'])) ?>.
                                </p>
                                <?php if (trim((string) ($pendingDeadlineShift['reason_text'] ?? '')) !== ''): ?>
                                    <p><?= e($pendingDeadlineShift['reason_text']) ?></p>
                                <?php endif; ?>
                                <?php if ($canDecideDeadlineShift): ?>
                                    <div class="approval-actions">
                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/deadline-shifts/' . $pendingDeadlineShift['id'] . '/approve') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--red" type="submit">Подтвердить срок</button>
                                        </form>
                                        <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/deadline-shifts/' . $pendingDeadlineShift['id'] . '/reject') ?>">
                                            <?= csrf_field() ?>
                                            <textarea name="comment" rows="3" placeholder="Почему срок нужно оставить прежним" required></textarea>
                                            <button class="btn btn-outline" type="submit">Вернуть на корректировку</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <p class="muted">Решение по переносу доступно назначенному проверяющему.</p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if (!$isLaborEstimateTask && !$isReviewTask): ?>
                            <details class="panel task-panel-fold task-route-panel task-approval" data-tour="task-approval"<?= $taskRouteFoldOpen ? ' open' : '' ?>>
                                <summary class="panel__head task-panel-fold__summary">
                                    <h2>Маршрут задачи</h2>
                                    <span class="approval-stage approval-stage--<?= e($approvalStage) ?>"><?= e(task_approval_stage_label($approvalStage)) ?></span>
                                </summary>
                                <div class="approval-chain">
                                    <?php foreach ($approvalSteps as $index => $step): ?>
                                        <?php
                                        $state = $step['state'] ?? $approvalStepState($step['key']);
                                        $icon = $state === 'done' ? '✓' : '';
                                        $assigned = trim((string) ($step['assigned'] ?? ''));
                                        // Кто реально совершил последнее действие на этапе (согласовал/вернул).
                                        $action = $step['event'] ?? ($approvalDone[$step['key']] ?? ($approvalLastByStage[$step['key']] ?? null));
                                        $actorLine = '';
                                        if ($state === 'done') {
                                            if ($step['key'] === 'executor') {
                                                $actorLine = ($task['assignee_name'] ?: 'исполнитель') . ' · ' . format_day_month($task['updated_at'] ?? '');
                                            } elseif ($action) {
                                                $actorLine = ($action['approved_by_name'] ?: 'сотрудник') . ' · ' . format_day_month($action['created_at'] ?? '');
                                            }
                                        }
                                        $stateLine = $actorLine === '' ? ($state === 'active' ? 'текущий этап' : ($state === 'pending' ? 'ожидает' : '')) : '';
                                        ?>
                                        <div class="approval-step approval-step--<?= e($state) ?>">
                                            <strong><?= $icon !== '' ? '<span class="approval-step__icon">' . e($icon) . '</span>' : '' ?><?= e($step['label']) ?></strong>
                                            <?php if ($assigned !== ''): ?><small class="approval-step__assigned">назначен: <?= e($assigned) ?></small><?php endif; ?>
                                            <?php if ($actorLine !== ''): ?><small>✓ <?= e($actorLine) ?></small><?php endif; ?>
                                            <?php if ($stateLine !== ''): ?><small><?= e($stateLine) ?></small><?php endif; ?>
                                        </div>
                                        <?php if ($index < count($approvalSteps) - 1): ?><span class="approval-chain__arrow">›</span><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>

                                <div class="approval-actions">
                                    <?php if ($canSubmitApproval): ?>
                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/approval/submit') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--red" type="submit">Подать на согласование</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canLeadApprove && $approvalStage === 'review_lead'): ?>
                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/approval/lead') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="approved">
                                            <button class="btn btn--red" type="submit">Согласовать</button>
                                        </form>
                                        <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/approval/lead') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="rejected">
                                            <textarea name="comment" rows="3" placeholder="Комментарий к замечаниям" required></textarea>
                                            <button class="btn btn-outline" type="submit">Вернуть с замечаниями</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canGipApprove && $approvalStage === 'review_gip'): ?>
                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/approval/gip') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="approved">
                                            <button class="btn btn--red" type="submit">Согласовать</button>
                                        </form>
                                        <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/approval/gip') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="rejected">
                                            <select name="return_to" required>
                                                <option value="review_lead">Вернуть промежуточному согласующему</option>
                                                <option value="draft">Вернуть исполнителю</option>
                                            </select>
                                            <textarea name="comment" rows="3" placeholder="Комментарий ГИПа" required></textarea>
                                            <button class="btn btn-outline" type="submit">Вернуть</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div class="approval-history">
                                    <?php foreach ($approvalMovements as $event): ?>
                                        <div class="approval-event approval-event--<?= e($event['decision']) ?>">
                                            <span class="approval-event__date"><?= e(format_date($event['created_at'])) ?></span>
                                            <strong><?= e($event['name']) ?></strong>
                                            <span><?= e(task_approval_stage_label($event['stage'] ?? '')) ?></span>
                                            <b class="approval-pill <?= e($approvalHistoryClass((string) $event['decision'])) ?>"><?= e($approvalHistoryDecision((string) $event['decision'])) ?></b>
                                            <small class="approval-event__comment" title="<?= e(trim((string) ($event['comment'] ?? ''))) ?>"><?= e(trim((string) ($event['comment'] ?? '')) !== '' ? $event['comment'] : '—') ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$approvalMovements): ?>
                                        <div class="empty-state empty-state--compact"><span class="empty-state__icon">—</span><strong>Движения пока нет</strong><span>Здесь появятся подачи на согласование и решения по задаче.</span></div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canRequestCloseCycle): ?>
                                    <form class="task-route-close-action" method="post" action="<?= url('/tasks/' . $task['id'] . '/close-request') ?>">
                                        <?= csrf_field() ?>
                                        <div>
                                            <strong>Сдать результат проверяющему</strong>
                                            <span>Эта же задача появится у <?= e($task['reviewer_name'] ?: 'назначенного проверяющего') ?> в проверке. Если проверяющий вернёт работу, задача перейдёт в корректировку.</span>
                                        </div>
                                        <button class="btn btn--red" type="submit">Сдать результат</button>
                                    </form>
                                <?php endif; ?>
                            </details>

                            <?php $regulationRefs = task_regulation_refs($task['task_type'] ?? 'work'); ?>
                            <p class="approval-regulation muted">
                                По регламенту:
                                <?php foreach ($regulationRefs as $i => $ref): ?><?= $i > 0 ? ' · ' : ' ' ?><a href="<?= url('/manual/regulation') ?>#reg-<?= e($ref['no']) ?>" target="_blank" rel="noopener">§<?= e($ref['no']) ?> <?= e($ref['title']) ?></a><?php endforeach; ?>
                            </p>

                            <?php if ($closeFoldOpen): ?>
                                <details class="panel task-panel-fold task-close-acceptance"<?= $closeFoldOpen ? ' open' : '' ?>>
                                    <summary class="panel__head task-panel-fold__summary"><h2>Приёмка закрытия</h2><span><?= $closeRequiresGip ? 'РП опционально + ГИП' : 'постановщик' ?></span></summary>
                                    <div class="approval-chain">
                                        <?php foreach ($closeSteps as $index => $step): ?>
                                            <?php
                                            $state = !empty($step['done']) ? 'done' : (!empty($step['active']) ? 'active' : ((($step['event']['decision'] ?? '') === 'rejected') ? 'rejected' : 'pending'));
                                            $event = $step['event'] ?? null;
                                            $icon = $state === 'done' ? '✓' : ($state === 'rejected' ? '✗' : '');
                                            $meta = $state === 'active'
                                                ? 'текущий этап'
                                                : (($event && in_array($state, ['done', 'rejected'], true))
                                                    ? format_day_month($event['created_at']) . ' · ' . ($event['approved_by_name'] ?: 'сотрудник')
                                                    : 'ожидает');
                                            ?>
                                            <div class="approval-step approval-step--<?= e($state) ?>">
                                                <strong><?= $icon !== '' ? '<span class="approval-step__icon">' . e($icon) . '</span>' : '' ?><?= e($step['label']) ?><?= !empty($step['optional']) ? ' <small>опционально</small>' : '' ?></strong>
                                                <small><?= e($meta) ?></small>
                                            </div>
                                            <?php if ($index < count($closeSteps) - 1): ?><span class="approval-chain__arrow">›</span><?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (in_array($task['status'], ['review', 'pending_close'], true)): ?>
                                        <div class="approval-actions">
                                            <?php if (!$closeAuthorAccepted && (!$closeRequiresGip || $canAcceptCloseByAuthor)): ?>
                                                <p class="muted"><?= $closeRequiresGip ? 'РП/постановщик может принять закрытие, но этот этап не обязателен перед ГИПом.' : 'Закрытие принимает постановщик задачи: ' . e($task['author_name'] ?: 'не указан') ?></p>
                                                <?php if ($canAcceptCloseByAuthor): ?>
                                                    <?php if (!$closeRequiresGip || $lastIssuanceAccepted): ?>
                                                        <form method="post" action="<?= url('/tasks/' . $task['id'] . '/accept-close') ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="decision" value="approved">
                                                            <button class="btn btn--red" type="submit">Принять работу</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <p class="muted">Для тома сначала нужна последняя выдача со статусом «Принята».</p>
                                                        <button class="btn btn--red" type="button" disabled>Принять работу</button>
                                                    <?php endif; ?>
                                                    <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/accept-close') ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="decision" value="rejected">
                                                        <textarea name="comment" rows="3" placeholder="Что нужно исправить" required></textarea>
                                                        <button class="btn btn-outline" type="submit">Не принять</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($closeRequiresGip && !$closeGipAccepted): ?>
                                                <p class="muted"><?= $closeAuthorAccepted ? 'РП/постановщик принял работу. ' : '' ?>Закрытие тома ждёт ГИПа: <?= e($task['project_gip_name'] ?: 'не назначен') ?>.</p>
                                                <?php if ($canAcceptCloseByGip): ?>
                                                    <form method="post" action="<?= url('/tasks/' . $task['id'] . '/accept-close') ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="decision" value="approved">
                                                        <button class="btn btn--red" type="submit">Принять как ГИП</button>
                                                    </form>
                                                    <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/accept-close') ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="decision" value="rejected">
                                                        <textarea name="comment" rows="3" placeholder="Комментарий ГИПа" required></textarea>
                                                        <button class="btn btn-outline" type="submit">Вернуть исполнителю</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="task-exchange-actions task-mail-actions">
                    <div class="task-exchange-actions__title">
                        <strong>Письмо по задаче</strong>
                        <span><?= e(task_status_label($task['status']) . ' · ' . $taskMailAttachmentSummary) ?></span>
                    </div>
                    <div class="task-exchange-actions__buttons">
                        <button class="btn btn-outline" type="button" data-copy-link="<?= e($taskPublicUrl) ?>">Скопировать ссылку</button>
                        <button class="btn btn-outline" type="button" data-copy="#<?= e($taskMailBodyId) ?>" data-copy-label="Скопировать текст письма">Скопировать текст письма</button>
                    </div>
                </div>
                <?php if ($canAdminCloseTask || $canAdminDeleteTask): ?>
                    <section class="panel form-stack task-admin-actions">
                        <div class="panel__head">
                            <h2>Административные действия</h2>
                            <span>директор / администратор / ГИП проекта</span>
                        </div>
                        <?php if ($canAdminCloseTask): ?>
                            <form method="post" action="<?= url('/tasks/' . $task['id'] . '/admin-close') ?>" onsubmit="return confirm('Закрыть задачу административно? Действие изменит статус задачи.')">
                                <?= csrf_field() ?>
                                <textarea name="comment" rows="2" placeholder="Причина закрытия"></textarea>
                                <button class="btn btn--red" type="submit">Закрыть задачу</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canAdminDeleteTask): ?>
                            <form class="approval-reject-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/delete') ?>" onsubmit="return confirm('Удалить задачу безвозвратно? Действие нельзя отменить.')">
                                <?= csrf_field() ?>
                                <textarea name="comment" rows="2" placeholder="Причина удаления"></textarea>
                                <button class="btn btn--red" type="submit">Удалить задачу</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if (!$isArchivedTask && !$isReviewTask && $canEdit && $canCreateTasks): ?>
                    <div class="task-exchange-actions" data-tour="task-exchange-actions">
                        <div class="task-exchange-actions__title">
                            <strong>Обмен заданиями</strong>
                            <span><?= e($exchangeLabel) ?></span>
                        </div>
                        <div class="task-exchange-actions__buttons">
                            <a class="btn btn--red" href="<?= e($issueAssignmentUrl) ?>">Выдать задание</a>
                            <a class="btn btn-outline" href="<?= e($requestAssignmentUrl) ?>">Запросить задание</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLaborEstimateTask): ?>
            <?php
            $laborSectionLabel = trim((string) (($laborEstimate['section_code'] ?? '') . ' · ' . ($laborEstimate['section_title'] ?? '')));
            $laborStageLabel = labor_estimate_status_label($laborEstimate['status'] ?? 'assigned');
            $laborWorkTitle = trim((string) ($laborEstimate['work_title'] ?? '')) ?: (string) ($task['title'] ?? '');
            $laborWorkDescription = trim((string) ($laborEstimate['work_description'] ?? ''));
            $laborAllocations = is_array($laborEstimate['allocations'] ?? null) ? $laborEstimate['allocations'] : [];
            $gipDefaultHours = ($laborEstimate['gip_hours'] ?? '') !== '' && $laborEstimate['gip_hours'] !== null
                ? $laborEstimate['gip_hours']
                : ($laborEstimate['executor_hours'] ?? '');
            $gipDefaultDays = ($laborEstimate['gip_days'] ?? '') !== '' && $laborEstimate['gip_days'] !== null
                ? $laborEstimate['gip_days']
                : (($gipDefaultHours ?? '') !== '' ? ((float) $gipDefaultHours / 8) : '');
            $directorDefaultHours = ($laborEstimate['director_hours'] ?? '') !== '' && $laborEstimate['director_hours'] !== null
                ? $laborEstimate['director_hours']
                : (($laborEstimate['gip_hours'] ?? '') !== '' && $laborEstimate['gip_hours'] !== null ? $laborEstimate['gip_hours'] : ($laborEstimate['executor_hours'] ?? ''));
            $directorDefaultDays = ($laborEstimate['director_days'] ?? '') !== '' && $laborEstimate['director_days'] !== null
                ? $laborEstimate['director_days']
                : (($directorDefaultHours ?? '') !== '' ? ((float) $directorDefaultHours / 8) : '');
            $sbcBasis = trim((string) (($laborEstimate['sbc_table_code'] ?? '') . ' ' . ($laborEstimate['sbc_item_code'] ?? '') . ' ' . ($laborEstimate['sbc_work_name'] ?? '')));
            ?>
            <details class="task-fold" open>
                <summary class="task-fold__summary">
                    <span>Оценка трудозатрат</span>
                    <strong><?= e($laborStageLabel) ?></strong>
                </summary>
                <div class="task-fold__body">
                    <div class="custom-field-grid">
                        <div class="custom-field">
                            <span>Предпроект</span>
                            <strong>
                                <?php if ($canSeeLaborEstimateMoney): ?>
                                    <a class="task-link" href="<?= url('/cost-estimates/' . $laborEstimate['project_id']) ?>"><?= e($laborEstimate['project_code'] . ' · ' . $laborEstimate['project_title']) ?></a>
                                <?php else: ?>
                                    <?= e($laborEstimate['project_code'] . ' · ' . $laborEstimate['project_title']) ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div class="custom-field">
                            <span>Раздел</span>
                            <strong><?= e($laborSectionLabel !== '·' ? $laborSectionLabel : 'Раздел не указан') ?></strong>
                        </div>
                        <div class="custom-field">
                            <span>Задача / вид работ</span>
                            <strong><?= e($laborWorkTitle) ?></strong>
                            <?php if ($laborWorkDescription !== ''): ?><small><?= e($laborWorkDescription) ?></small><?php endif; ?>
                        </div>
                        <div class="custom-field">
                            <span>Статус оценки</span>
                            <strong><span class="status status--<?= e(labor_estimate_status_class($laborEstimate['status'] ?? 'assigned')) ?>"><?= e($laborStageLabel) ?></span></strong>
                            <?php if (($laborEstimate['return_comment'] ?? '') !== ''): ?><small><?= e($laborEstimate['return_comment']) ?></small><?php endif; ?>
                        </div>
                        <div class="custom-field">
                            <span>Исполнитель</span>
                            <strong><?= e($laborEstimate['executor_name'] ?? $task['assignee_name'] ?? '—') ?></strong>
                        </div>
                        <?php if (($laborEstimate['executor_hours'] ?? '') !== '' && $laborEstimate['executor_hours'] !== null): ?>
                            <div class="custom-field">
                                <span>Оценка исполнителя</span>
                                <strong><?= e($laborNumber($laborEstimate['executor_hours'])) ?> ч / <?= e($laborNumber($laborEstimate['executor_days'] ?? ((float) $laborEstimate['executor_hours'] / 8))) ?> дн.</strong>
                                <small><?= e($laborEstimate['executor_comment'] ?? '') ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($canSeeLaborEstimateMoney && ($laborEstimate['gip_hours'] ?? '') !== '' && $laborEstimate['gip_hours'] !== null): ?>
                            <div class="custom-field">
                                <span>Корректировка ГИПа</span>
                                <strong><?= e($laborNumber($laborEstimate['gip_hours'])) ?> ч / <?= e($laborNumber($laborEstimate['gip_days'] ?? ((float) $laborEstimate['gip_hours'] / 8))) ?> дн.</strong>
                                <small><?= e($laborEstimate['gip_comment'] ?? '') ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($canSeeLaborEstimateMoney && ($laborEstimate['director_hours'] ?? '') !== '' && $laborEstimate['director_hours'] !== null): ?>
                            <div class="custom-field">
                                <span>Утверждено директором</span>
                                <strong><?= e($laborNumber($laborEstimate['director_hours'])) ?> ч / <?= e($laborNumber($laborEstimate['director_days'] ?? ((float) $laborEstimate['director_hours'] / 8))) ?> дн.</strong>
                                <small><?= e($laborEstimate['director_comment'] ?? '') ?></small>
                            </div>
                        <?php endif; ?>
                        <div class="custom-field">
                            <span>Конкретные исполнители</span>
                            <?php if ($laborAllocations): ?>
                                <?php foreach ($laborAllocations as $allocation): ?>
                                    <strong><?= e($allocation['user_name']) ?> · <?= e($laborNumber($allocation['hours'])) ?> ч / <?= e($laborNumber($allocation['days'])) ?> дн.</strong>
                                    <?php if (($allocation['comment'] ?? '') !== ''): ?><small><?= e($allocation['comment']) ?></small><?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <strong>Не назначены</strong>
                            <?php endif; ?>
                        </div>
                        <?php if ($canSeeLaborEstimateMoney): ?>
                            <div class="custom-field">
                                <span>Расчёт по ставке</span>
                                <strong><?= e(number_format((float) ($laborEstimate['money_thousand'] ?? 0), 2, '.', ' ')) ?> тыс. руб.</strong>
                                <?php if ($canSeeLaborEstimateRate): ?><small><?= e($laborNumber($laborEstimate['hourly_rate'] ?? 0)) ?> руб./ч</small><?php endif; ?>
                            </div>
                            <div class="custom-field">
                                <span>СБЦ справочно</span>
                                <strong><?= e(number_format((float) ($laborEstimate['sbc_reference_cost'] ?? 0), 2, '.', ' ')) ?> тыс. руб.</strong>
                                <small><?= e($sbcBasis !== '' ? $sbcBasis : ($laborEstimate['sbc_comment'] ?? 'основание не задано')) ?></small>
                            </div>
                            <div class="custom-field">
                                <span>Отклонение</span>
                                <strong><?= e(number_format((float) ($laborEstimate['delta_thousand'] ?? 0), 2, '.', ' ')) ?> тыс. руб.</strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($canSubmitLaborEstimate): ?>
                        <form class="form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/labor-estimate/submit') ?>">
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <label><span>Оценка, ч</span><input data-labor-hours type="number" min="0.01" step="0.01" name="executor_hours" value="<?= e($laborNumber($laborEstimate['executor_hours'] ?? '')) ?>"></label>
                                <label><span>Оценка, дн.</span><input data-labor-days type="number" min="0.01" step="0.01" name="executor_days" value="<?= e($laborNumber($laborEstimate['executor_days'] ?? (($laborEstimate['executor_hours'] ?? '') !== '' ? ((float) $laborEstimate['executor_hours'] / 8) : ''))) ?>"></label>
                            </div>
                            <details class="task-fold">
                                <summary class="task-fold__summary"><span>Конкретные исполнители</span><strong>необязательно</strong></summary>
                                <div class="task-fold__body form-stack">
                                    <?php $allocationRows = $laborAllocations ?: [['user_id' => '', 'hours' => '', 'days' => '', 'comment' => ''], ['user_id' => '', 'hours' => '', 'days' => '', 'comment' => '']]; ?>
                                    <?php foreach ($allocationRows as $allocation): ?>
                                        <div class="form-grid labor-allocation-row">
                                            <label><span>Исполнитель</span><?php $laborUserSelect('allocation_user_id[]', $allocation['user_id'] ?? ''); ?></label>
                                            <label><span>Часы</span><input data-labor-hours type="number" min="0" step="0.01" name="allocation_hours[]" value="<?= e($laborNumber($allocation['hours'] ?? '')) ?>"></label>
                                            <label><span>Дни</span><input data-labor-days type="number" min="0" step="0.01" name="allocation_days[]" value="<?= e($laborNumber($allocation['days'] ?? '')) ?>"></label>
                                            <label class="form-grid__full"><span>Комментарий</span><input name="allocation_comment[]" value="<?= e($allocation['comment'] ?? '') ?>"></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <label><span>Комментарий / обоснование</span><textarea name="executor_comment" rows="3"><?= e($laborEstimate['executor_comment'] ?? '') ?></textarea></label>
                            <button class="btn btn--red" type="submit">Подать оценку</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canGipApproveLaborEstimate): ?>
                        <form class="form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/labor-estimate/gip') ?>">
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <label><span>Часы ГИПа</span><input data-labor-hours type="number" min="0.01" step="0.01" name="gip_hours" value="<?= e($laborNumber($gipDefaultHours)) ?>"></label>
                                <label><span>Дни ГИПа</span><input data-labor-days type="number" min="0.01" step="0.01" name="gip_days" value="<?= e($laborNumber($gipDefaultDays)) ?>"></label>
                            </div>
                            <label><span>Комментарий ГИПа</span><textarea name="gip_comment" rows="3"><?= e($laborEstimate['gip_comment'] ?? '') ?></textarea></label>
                            <button class="btn btn--red" type="submit">Отправить директору</button>
                        </form>
                        <form class="form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/labor-estimate/gip-return') ?>">
                            <?= csrf_field() ?>
                            <label><span>Причина возврата ответственному</span><textarea name="return_comment" rows="2"></textarea></label>
                            <button class="btn btn-outline" type="submit">Вернуть ответственному</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canDirectorApproveLaborEstimate): ?>
                        <form class="form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/labor-estimate/director') ?>">
                            <?= csrf_field() ?>
                            <div class="form-grid">
                                <label><span>Финальные часы</span><input data-labor-hours type="number" min="0.01" step="0.01" name="director_hours" value="<?= e($laborNumber($directorDefaultHours)) ?>"></label>
                                <label><span>Финальные дни</span><input data-labor-days type="number" min="0.01" step="0.01" name="director_days" value="<?= e($laborNumber($directorDefaultDays)) ?>"></label>
                            </div>
                            <label><span>Комментарий директора</span><textarea name="director_comment" rows="3"><?= e($laborEstimate['director_comment'] ?? '') ?></textarea></label>
                            <button class="btn btn--red" type="submit">Утвердить оценку</button>
                        </form>
                        <form class="form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/labor-estimate/director-return') ?>">
                            <?= csrf_field() ?>
                            <label><span>Причина возврата ГИПу</span><textarea name="return_comment" rows="2"></textarea></label>
                            <button class="btn btn-outline" type="submit">Вернуть ГИПу</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php
        $ppLabel = trim((string) ($task['pp_code'] ?? ''));
        if ($ppLabel !== '' && trim((string) ($task['pp_title'] ?? '')) !== '') {
            $ppLabel .= ' · ' . trim((string) $task['pp_title']);
        }
        $btpLabel = trim((string) ($task['btp_code'] ?? ''));
        if ($btpLabel !== '' && trim((string) ($task['btp_title'] ?? '')) !== '') {
            $btpLabel .= ' · ' . trim((string) $task['btp_title']);
        }
        if ($btpLabel === '') {
            $btpLabel = trim((string) ($task['btp'] ?? ''));
        }
        ?>
        <?php if ($ppLabel !== '' || $btpLabel !== ''): ?>
            <details class="task-fold">
                <summary class="task-fold__summary"><span>ПП / БТП</span></summary>
                <div class="task-fold__body">
                    <?php if ($ppLabel !== ''): ?><p><strong>ПП:</strong> <?= e($ppLabel) ?></p><?php endif; ?>
                    <?php if ($btpLabel !== ''): ?><p><strong>БТП:</strong> <?= e($btpLabel) ?></p><?php endif; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if ((!$editMode || !$canEdit) && $visibleCustomFields): ?>
            <details class="task-fold">
                <summary class="task-fold__summary"><span>Кастомные поля</span><strong><?= count($visibleCustomFields) ?></strong></summary>
                <div class="task-fold__body">
                    <div class="custom-field-grid">
                        <?php foreach ($visibleCustomFields as $field): ?>
                            <?php
                            $value = (string) ($customValues[$field['id']] ?? '');
                            ?>
                            <div class="custom-field">
                                <span><?= e($field['label']) ?></span>
                                <?php if (in_array($field['type'], ['link', 'links'], true)): ?>
                                    <?php $entries = custom_link_entries($value); ?>
                                    <?php if ($entries): ?>
                                        <div class="file-link-list">
                                            <?php foreach ($entries as $entry): ?>
                                                <a class="file-link" href="<?= e(file_link_href($entry['url'])) ?>" target="_blank" rel="noreferrer"><?= e($entry['label'] !== '' ? $entry['label'] : $entry['url']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <strong>Не указано</strong>
                                    <?php endif; ?>
                                <?php elseif ($field['type'] === 'bool'): ?>
                                    <strong><?= $value === '1' ? 'Да' : 'Нет' ?></strong>
                                <?php else: ?>
                                    <strong><?= nl2br(e($value)) ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        <?php endif; ?>

        <details class="task-fold"<?= $children ? ' open' : '' ?>>
            <summary class="task-fold__summary"><span><?= $isDelegationTask ? 'Распределённые задачи' : 'Подзадачи' ?></span><strong><?= count($children) ?></strong></summary>
            <div class="task-fold__body">
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                        <?php foreach ($children as $child): ?>
                            <tr class="clickable" data-href="<?= url('/tasks/' . $child['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $child['id']) ?>">
                                <td>#<?= (int) $child['id'] ?></td>
                                <td><?= e($child['title']) ?></td>
                                <td><span class="status status--<?= e($child['status']) ?>"><?= e(task_status_label($child['status'])) ?></span></td>
                                <td><?= (int) $child['progress'] ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$children): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state empty-state--compact">
                                        <span class="empty-state__icon">+</span>
                                        <strong><?= $isDelegationTask ? 'Работа ещё не распределена' : 'Подзадач пока нет' ?></strong>
                                        <span><?= $isDelegationTask ? 'Создайте дочерние задачи для исполнителей отдела или возьмите работу на себя.' : 'Разбейте задачу, если нужно отдать часть работы отдельно.' ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$isArchivedTask && $canCreateTasks): ?>
                    <div class="task-fold__actions"><a class="btn" href="<?= url('/tasks/new') ?>?project_id=<?= (int) $task['project_id'] ?>&parent_id=<?= (int) $task['id'] ?>"><?= $isDelegationTask ? '+ Задача исполнителю' : '+ Подзадача' ?></a></div>
                <?php endif; ?>
            </div>
        </details>

        <details class="task-fold task-attachments"<?= $attachments ? ' open' : '' ?>>
            <summary class="task-fold__summary"><span>Файлы и фото</span><strong><?= count($attachments) ?></strong></summary>
            <div class="task-fold__body">
                <?php if ($attachments): ?>
                    <div class="task-attachment-grid">
                        <?php foreach ($attachments as $attachment): ?>
                            <?php
                            $attachmentUrl = url('/tasks/' . (int) $task['id'] . '/attachments/' . (int) $attachment['id']);
                            $canDeleteAttachment = !$isArchivedTask && (
                                (int) ($attachment['user_id'] ?? 0) === $currentUserId
                                || $canEdit
                                || $canAdminDeleteTask
                            );
                            ?>
                            <article class="task-attachment-card">
                                <a class="task-attachment-card__preview" href="<?= $attachmentUrl ?>"<?= !empty($attachment['is_image']) ? ' target="_blank" rel="noreferrer"' : '' ?>>
                                    <?php if (!empty($attachment['is_image'])): ?>
                                        <img src="<?= $attachmentUrl ?>" alt="<?= e((string) $attachment['filename']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <span><?= e(strtoupper((string) pathinfo((string) $attachment['filename'], PATHINFO_EXTENSION)) ?: 'FILE') ?></span>
                                    <?php endif; ?>
                                </a>
                                <div class="task-attachment-card__body">
                                    <a href="<?= $attachmentUrl ?>"><strong><?= e((string) $attachment['filename']) ?></strong></a>
                                    <small><?= e($formatAttachmentSize($attachment['size'] ?? 0)) ?><?= !empty($attachment['user_name']) ? ' · ' . e((string) $attachment['user_name']) : '' ?></small>
                                </div>
                                <?php if ($canDeleteAttachment): ?>
                                    <form method="post" action="<?= url('/tasks/' . (int) $task['id'] . '/attachments/' . (int) $attachment['id'] . '/delete') ?>" onsubmit="return confirm('Удалить этот файл из задачи?')">
                                        <?= csrf_field() ?>
                                        <button class="task-attachment-card__delete" type="submit" aria-label="Удалить <?= e((string) $attachment['filename']) ?>">×</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state empty-state--compact">
                        <span class="empty-state__icon">+</span>
                        <strong>Вложений пока нет</strong>
                        <span>Добавьте фотографию с площадки, PDF или рабочий файл.</span>
                    </div>
                <?php endif; ?>

                <?php if ($canUploadAttachments): ?>
                    <form class="task-attachment-upload" method="post" enctype="multipart/form-data" action="<?= url('/tasks/' . (int) $task['id'] . '/attachments') ?>" data-task-attachment-picker>
                        <?= csrf_field() ?>
                        <label>
                            <span class="task-attachment-picker__control">
                                <strong>Добавить файлы</strong>
                                <small>Можно выбрать несколько фото или документов</small>
                            </span>
                            <input type="file" name="attachments[]" multiple required accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.7z,.dwg,.dxf,.ifc,.ifczip,.frag,.nwc,.nwd,.nwf,.rvt" data-task-attachment-input>
                        </label>
                        <span class="task-attachment-picker__selection" data-task-attachment-selection>Файлы не выбраны</span>
                        <span class="task-attachment-picker__preview" data-task-attachment-preview aria-live="polite"></span>
                        <button class="btn" type="submit">Прикрепить</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>

        <details class="task-fold" data-tour="task-comments" open>
            <summary class="task-fold__summary"><span>Чат</span><strong><?= count($comments) ?></strong></summary>
            <div class="task-fold__body">
                <div class="comments">
                    <?php foreach ($comments as $comment): ?>
                        <article class="comment">
                            <div><strong><?= e($comment['user_name']) ?></strong><span><?= e($comment['created_at']) ?></span></div>
                            <p><?= nl2br(e($comment['body'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isArchivedTask): ?>
                    <form class="comment-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/comments') ?>">
                        <?= csrf_field() ?>
                        <textarea name="body" rows="3" placeholder="@ID пользователя для упоминания" required></textarea>
                        <button class="btn" type="submit">Отправить</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>

        <details class="task-fold">
            <summary class="task-fold__summary"><span>История</span><strong><?= count($logs) ?></strong></summary>
            <div class="task-fold__body">
                <div class="history">
                    <?php foreach ($logs as $log): ?>
                        <div><?= e($log['created_at']) ?> · <?= e($log['user_name']) ?> · <?= e(task_log_field_label($log['field'])) ?>: <?= e(task_log_value_label($log['field'], $log['old_val'])) ?> → <?= e(task_log_value_label($log['field'], $log['new_val'])) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
    </section>

    <section class="task-detail-secondary" id="task-detail-secondary">
        <details class="panel task-panel-fold task-stats" open>
            <summary class="panel__head task-panel-fold__summary"><h2>Статистика задачи</h2></summary>
            <div class="stat-ring">
                <strong class="<?= $progress > 0 ? '' : 'progress-placeholder progress-placeholder--large' ?>"><?= $progress > 0 ? $progress . '%' : '—' ?></strong>
                <span>Прогресс</span>
                <?php if ($progress > 0): ?>
                    <div class="progress progress--wide"><span class="prog-fill <?= e($progressTone) ?>" style="width: <?= $progress ?>%"></span></div>
                <?php endif; ?>
            </div>
            <dl class="stat-list">
                <div><dt>План</dt><dd><?= e($plannedHoursDisplay) ?></dd></div>
                <div><dt>Факт</dt><dd><?= e($actualHoursDisplay) ?></dd></div>
                <div><dt>Дельта</dt><dd class="<?= $delta !== null && $delta < 0 ? 'metric-value--bad' : ($delta !== null && $delta > 0 ? 'metric-value--good' : '') ?>"><?= e($deltaHoursDisplay) ?></dd></div>
                <div><dt>Дата начала</dt><dd><?= e(format_date($task['date_start'])) ?></dd></div>
                <div><dt>Срок</dt><dd class="<?= e($deadlineState) ?>"><?= e($deadlineDisplay) ?></dd></div>
                <div><dt>Исходный срок</dt><dd><?= e(format_date($task['date_end_original'])) ?></dd></div>
                <div><dt>Важность</dt><dd><span class="priority-dot priority-dot--<?= e($task['priority']) ?>"></span><?= e(priority_label($task['priority'])) ?></dd></div>
                <div><dt>Срочность</dt><dd><span class="priority-dot priority-dot--<?= e($task['urgency']) ?>"></span><?= e(priority_label($task['urgency'])) ?></dd></div>
            </dl>
            <?php if ($canLogTime): ?>
                <form class="task-time-form" method="post" action="<?= url('/time/task') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <input type="hidden" name="back" value="<?= e('/tasks/' . (int) $task['id']) ?>">
                    <label class="task-time-form__date"><span>Дата</span><input type="date" name="work_date" value="<?= e(date('Y-m-d')) ?>"></label>
                    <label><span>Часы</span><input type="number" min="0.25" max="24" step="0.25" name="hours" value="1"></label>
                    <label class="task-time-form__phase">
                        <span>Фаза</span>
                        <select name="phase">
                            <?php foreach (\App\Services\TimeService::PHASES as $phase => $label): ?>
                                <option value="<?= e($phase) ?>"<?= selected(\App\Services\TimeService::phaseForTask($task), $phase) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn--red" type="submit">Списать</button>
                    <a class="btn btn-outline task-time-form__week" href="<?= url('/time?task_id=' . (int) $task['id']) ?>">Табель</a>
                </form>
            <?php endif; ?>
        </details>

        <details class="panel task-panel-fold task-sections"<?= $linkedSections ? ' open' : '' ?>>
            <summary class="panel__head task-panel-fold__summary"><h2>Связанный раздел</h2><span><?= count($linkedSections) ?></span></summary>
            <?php if ($linkedSections): ?>
                <div class="linked-list">
                    <?php foreach ($linkedSections as $section): ?>
                        <a class="linked-row" href="<?= url('/projects/' . $section['project_id'] . '/sections') ?>">
                            <span>
                                <strong><?= e(($section['volume'] ?: 'Том') . ' · ' . ($section['code'] ?: 'Без шифра')) ?></strong>
                                <small><?= e($section['title'] ?: 'Без наименования') ?></small>
                            </span>
                            <span class="status status--<?= e($section['linked_task_status'] ?? $task['status']) ?>"><?= e($section['status'] ?: task_status_label($task['status'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact"><span class="empty-state__icon">—</span><strong>Раздел не привязан</strong><span>Свяжите строку разделов проекта с этой задачей.</span></div>
            <?php endif; ?>
        </details>

        <details class="panel task-panel-fold task-blocking-data"<?= $blockingData ? ' open' : '' ?>>
            <summary class="panel__head task-panel-fold__summary"><h2>Блокирующие ИД</h2><span><?= count($blockingData) ?></span></summary>
            <?php if ($blockingData): ?>
                <div class="linked-list">
                    <?php foreach ($blockingData as $row): ?>
                        <?php $planDisplay = (string) ($row['date_received_plan'] ?? '') !== '' ? format_date($row['date_received_plan']) : '—'; ?>
                        <a class="linked-row" href="<?= url('/projects/' . $task['project_id'] . '/data') ?>">
                            <span>
                                <strong><?= e($row['missing_data'] ?: 'Исходные данные') ?></strong>
                                <small><?= e(($row['section_code'] ?: 'Без раздела') . ' · план ' . $planDisplay) ?></small>
                            </span>
                            <span class="status status--<?= e(data_status_class($row['status'] ?? '')) ?>"><?= e(data_status_label($row['status'] ?? '')) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact"><span class="empty-state__icon">✓</span><strong>ИД не блокируют</strong><span>Связанных строк реестра ИД нет.</span></div>
            <?php endif; ?>
        </details>

        <details class="panel task-panel-fold task-linked-issues"<?= $linkedIssues ? ' open' : '' ?>>
            <summary class="panel__head task-panel-fold__summary"><h2>Вопросы</h2><span><?= count($linkedIssues) ?></span></summary>
            <?php if ($linkedIssues): ?>
                <div class="linked-list">
                    <?php foreach ($linkedIssues as $issue): ?>
                        <div class="linked-row">
                            <span>
                                <strong><?= e($issue['issue']) ?></strong>
                                <small><?= e(issue_status_label($issue['status']) . ' · ' . ($issue['assignee_name'] ?: 'не назначен')) ?></small>
                            </span>
                            <?php if (!$isArchivedTask && $issue['status'] !== 'done'): ?>
                                <form method="post" action="<?= url('/tasks/' . $task['id'] . '/issues/' . $issue['id'] . '/close') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-outline" type="submit">Закрыть</button>
                                </form>
                            <?php else: ?>
                                <span class="status status--done">Закрыт</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!$isArchivedTask): ?>
                <form class="form-stack task-issue-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/issues') ?>">
                    <?= csrf_field() ?>
                    <label><span>Вопрос</span><textarea name="issue" rows="3" required></textarea></label>
                    <label>
                        <span>Ответственный</span>
                        <select name="assignee_id">
                            <option value=""></option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn--red" type="submit">+ Открыть вопрос</button>
                </form>
            <?php endif; ?>
        </details>

        <details class="panel task-panel-fold task-links">
            <summary class="panel__head task-panel-fold__summary"><h2>Интеграции</h2></summary>
            <div class="integrations">
                <?php if ($projectFolderUrl): ?>
                    <a class="integration-row" href="<?= e(file_link_href($projectFolderUrl)) ?>" target="_blank" rel="noreferrer">
                        <span class="integration-icon integration-icon--folder">F</span>
                        <span><strong>Папка проекта</strong><small><?= e($task['project_code']) ?></small></span>
                    </a>
                <?php else: ?>
                    <span class="integration-row is-disabled"><span class="integration-icon integration-icon--folder">F</span><span><strong>Папка проекта</strong><small>не подключено</small></span></span>
                <?php endif; ?>
                <span class="integration-row <?= $task['msp_task_uid'] ? '' : 'is-disabled' ?>">
                    <span class="integration-icon integration-icon--msp">M</span>
                    <span><strong>MSP</strong><small><?= e($task['msp_task_uid'] ?: 'нет UID') ?></small></span>
                </span>
            </div>
        </details>

        <?php if (!$isLaborEstimateTask && !$isReviewTask): ?>
        <details class="panel task-panel-fold task-issuances" data-tour="task-issuances"<?= $issuanceFoldOpen ? ' open' : '' ?>>
            <summary class="panel__head task-panel-fold__summary"><h2>Выдачи</h2><span><?= count($issuances) ?></span></summary>
            <?php if ($issuances): ?>
                <div class="issuance-list">
                    <?php foreach ($issuances as $issuance): ?>
                        <?php
                        $comment = trim((string) ($issuance['comment'] ?? ''));
                        $commentDisplay = $comment !== ''
                            ? $comment
                            : ((string) $issuance['status'] === 'accepted' ? '✓' : '—');
                        ?>
                        <div class="issuance-row">
                            <strong>Выдача №<?= (int) $issuance['issue_number'] ?></strong>
                            <span><?= e(format_date($issuance['issued_at'])) ?></span>
                            <span class="issuance-status issuance-status--<?= e($issuance['status']) ?>"><?= e(task_issuance_status_label($issuance['status'])) ?></span>
                            <small title="<?= e($commentDisplay) ?>"><?= e($commentDisplay) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact"><span class="empty-state__icon">+</span><strong>Выдач пока нет</strong><span>Зафиксируйте первую выдачу перед закрытием задачи.</span></div>
            <?php endif; ?>

            <?php if ($documentRevisions): ?>
                <div class="revision-list">
                    <?php foreach ($documentRevisions as $revision): ?>
                        <div class="revision-row">
                            <strong>Изм. <?= (int) $revision['revision_no'] ?></strong>
                            <span>Выдача №<?= (int) $revision['issue_number'] ?> · <?= e(format_date($revision['issued_at'])) ?></span>
                            <p><?= e($revision['reason']) ?></p>
                            <?php if (trim((string) ($revision['summary'] ?? '')) !== ''): ?>
                                <small><?= e($revision['summary']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canManageIssuances && $approvalStage === 'approved'): ?>
                <form class="issuance-form" method="post" action="<?= url('/tasks/' . $task['id'] . '/issuances') ?>">
                    <?= csrf_field() ?>
                    <label><span>Дата</span><input type="date" name="issued_at" value="<?= e(date('Y-m-d')) ?>" required></label>
                    <label>
                        <span>Статус</span>
                        <select name="status" required>
                            <option value="issued">Выдана</option>
                            <option value="remarks">Замечания</option>
                            <option value="accepted">Принята</option>
                        </select>
                    </label>
                    <label class="issuance-form__comment"><span>Комментарий</span><textarea name="comment" rows="3"></textarea></label>
                    <label class="issuance-form__comment">
                        <span>Основание изменения<?= $issuances ? '' : ' · для изм. 0 можно оставить пустым' ?></span>
                        <input name="revision_reason" maxlength="255"<?= $issuances ? ' required' : '' ?> placeholder="<?= $issuances ? 'Например: замечания заказчика, изменение ТЗ' : 'Первичная выдача' ?>">
                    </label>
                    <label class="issuance-form__comment">
                        <span>Состав изменения</span>
                        <textarea name="revision_summary" rows="2" placeholder="Кратко: какие листы, разделы или решения изменились"></textarea>
                    </label>
                    <button class="btn btn--red" type="submit">+ Зафиксировать выдачу</button>
                </form>
            <?php elseif ($canManageIssuances && $approvalStage !== 'approved'): ?>
                <p class="muted issuance-gate">Фиксация выдачи доступна после статуса «Согласована».</p>
            <?php endif; ?>
        </details>
        <?php endif; ?>

        <?php if ($canReview && in_array($task['status'], ['review', 'pending_close'], true)): ?>
            <form class="panel form-stack" method="post" action="<?= url('/tasks/' . $task['id'] . '/shift-deadline') ?>">
                <?= csrf_field() ?>
                <div class="panel__head"><h2>Сдвинуть срок</h2></div>
                <label><span>Новая дата</span><input type="date" name="date_new" required></label>
                <label>
                    <span>Причина</span>
                    <select name="reason_code" required>
                        <option value=""></option>
                        <?php foreach ($reasons as $reason): ?>
                            <option value="<?= e($reason['code']) ?>"><?= e($reason['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Комментарий</span><textarea name="reason_text" minlength="20" rows="4" required></textarea></label>
                <button class="btn" type="submit">Вернуть в работу</button>
            </form>
        <?php endif; ?>

        <?php if ($shifts): ?>
            <details class="panel task-panel-fold">
                <summary class="panel__head task-panel-fold__summary"><h2>Сдвиги сроков</h2><span><?= count($shifts) ?></span></summary>
                <?php foreach ($shifts as $shift): ?>
                    <div class="shift">
                        <strong><?= e(format_date($shift['date_old'])) ?> → <?= e(format_date($shift['date_new'])) ?></strong>
                        <?php
                        $shiftStatus = (string) ($shift['status'] ?? 'approved');
                        $shiftStatusLabel = ['pending' => 'на проверке', 'approved' => 'подтверждён', 'rejected' => 'возвращён'][$shiftStatus] ?? $shiftStatus;
                        ?>
                        <span><?= e($shiftStatusLabel) ?> · <?= e($shift['reason_label']) ?></span>
                        <p><?= e($shift['reason_text']) ?></p>
                        <?php if (trim((string) ($shift['review_comment'] ?? '')) !== ''): ?>
                            <small><?= e($shift['review_comment']) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
    </section>
</div>
