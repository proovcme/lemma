INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
SELECT t.id, NULL, 'status', t.status, 'in_progress'
FROM tasks t
WHERE t.status IN ('review','pending_close')
  AND t.close_requested_at IS NULL
  AND COALESCE(t.approval_stage, 'draft') NOT IN ('review_lead','review_gip')
  AND COALESCE(t.task_type, 'work') NOT IN ('issuance','review');

UPDATE tasks
SET status = 'in_progress', updated_at = CURRENT_TIMESTAMP
WHERE status IN ('review','pending_close')
  AND close_requested_at IS NULL
  AND COALESCE(approval_stage, 'draft') NOT IN ('review_lead','review_gip')
  AND COALESCE(task_type, 'work') NOT IN ('issuance','review');

INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
SELECT t.id, NULL, 'approval_stage', t.approval_stage, 'approved'
FROM tasks t
WHERE t.status = 'done'
  AND t.approval_stage IN ('review_lead','review_gip','review_task');

UPDATE tasks
SET approval_stage = CASE
        WHEN approval_stage IN ('review_lead','review_gip','review_task') THEN 'approved'
        ELSE approval_stage
    END,
    progress = 100,
    closed_at = COALESCE(closed_at, updated_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE status = 'done'
  AND (approval_stage IN ('review_lead','review_gip','review_task')
       OR progress <> 100
       OR closed_at IS NULL);

UPDATE notifications n
INNER JOIN tasks t ON t.id = n.task_id
SET n.read_at = COALESCE(n.read_at, CURRENT_TIMESTAMP)
WHERE n.read_at IS NULL
  AND n.type IN (
      'review_task_created',
      'approval_review_lead',
      'approval_review_gip',
      'close_gip_requested',
      'deadline_shift_requested'
  )
  AND (
      t.status = 'done'
      OR t.closed_at IS NOT NULL
      OR (t.status NOT IN ('review','pending_close')
          AND COALESCE(t.approval_stage, 'draft') NOT IN ('review_lead','review_gip'))
  );

UPDATE performance_review_templates
SET is_active = 0, updated_at = CURRENT_TIMESTAMP
WHERE title = 'Performance Review v1'
  AND is_active <> 0;
