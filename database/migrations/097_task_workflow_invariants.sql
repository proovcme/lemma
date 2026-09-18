-- Exact-base repair for 2026.06.11.190.
-- Business records and historical decisions are preserved; only objectively
-- impossible active state combinations are retired.

INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
SELECT t.id, NULL, 'approval_stage', t.approval_stage, 'approved'
FROM tasks t
WHERE t.status = 'done'
  AND t.approval_stage IN ('review_lead', 'review_gip');

UPDATE tasks
SET approval_stage = CASE
        WHEN approval_stage IN ('review_lead', 'review_gip') THEN 'approved'
        ELSE approval_stage
    END,
    progress = 100,
    close_requested_at = NULL,
    closed_at = COALESCE(closed_at, updated_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE status = 'done'
  AND (approval_stage IN ('review_lead', 'review_gip')
       OR progress <> 100
       OR close_requested_at IS NOT NULL
       OR closed_at IS NULL);

-- When both chains were started, the later explicit close-review remains the
-- actionable chain. No approval decision row is invented.
INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
SELECT t.id, NULL, 'approval_stage', t.approval_stage, 'draft'
FROM tasks t
WHERE t.status IN ('review', 'pending_close')
  AND t.close_requested_at IS NOT NULL
  AND t.approval_stage IN ('review_lead', 'review_gip');

UPDATE tasks
SET approval_stage = 'draft', updated_at = CURRENT_TIMESTAMP
WHERE status IN ('review', 'pending_close')
  AND close_requested_at IS NOT NULL
  AND approval_stage IN ('review_lead', 'review_gip');

UPDATE task_deadline_shifts s
INNER JOIN tasks t ON t.id = s.task_id
SET s.status = 'rejected',
    s.reviewed_at = CURRENT_TIMESTAMP,
    s.review_comment = 'Заявка закрыта автоматически миграцией 097: задача завершена.'
WHERE s.status = 'pending'
  AND (t.status = 'done' OR t.closed_at IS NOT NULL);

UPDATE task_deadline_shifts s
INNER JOIN tasks t ON t.id = s.task_id
SET s.status = 'rejected',
    s.reviewed_at = CURRENT_TIMESTAMP,
    s.review_comment = 'Заявка закрыта автоматически миграцией 097: исходный срок уже изменён.'
WHERE s.status = 'pending'
  AND t.status <> 'done'
  AND t.closed_at IS NULL
  AND NOT (s.date_old <=> t.date_end);

INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
SELECT t.id, NULL, 'close_requested_at', t.close_requested_at, ''
FROM tasks t
WHERE t.close_requested_at IS NOT NULL
  AND t.status NOT IN ('review', 'pending_close');

UPDATE tasks
SET close_requested_at = NULL, updated_at = CURRENT_TIMESTAMP
WHERE close_requested_at IS NOT NULL
  AND status NOT IN ('review', 'pending_close');

-- The primary assignee is already represented by tasks.assignee_id and must
-- not consume a second participant role.
DELETE tp
FROM task_participants tp
INNER JOIN tasks t ON t.id = tp.task_id AND t.assignee_id = tp.user_id;

-- Backfill only missing task-linked exchange rows. Manually maintained rows
-- with task_id IS NULL are not touched or matched heuristically.
INSERT INTO project_task_exchange (
    project_id, task_id, direction, from_user_id, to_user_id, num,
    assignment, from_section, to_section, file_url, date_issued,
    deadline, status, comments
)
SELECT t.project_id,
       t.id,
       CASE
           WHEN LOWER(TRIM(t.title)) LIKE 'запрос%'
             OR LOWER(t.title) LIKE '%получить задание%'
             OR LOWER(t.title) LIKE '%ждём задание%'
             OR LOWER(t.title) LIKE '%ждем задание%'
           THEN 'incoming'
           ELSE 'outgoing'
       END,
       COALESCE(ptask.assignee_id, t.author_id),
       t.assignee_id,
       t.id,
       COALESCE(NULLIF(TRIM(ts.what), ''), CONCAT('Нет задания: ', TRIM(t.title))),
       COALESCE(NULLIF(TRIM(ptask.section), ''), NULLIF(TRIM(ptask.discipline), ''), NULLIF(TRIM(ptask.volume), ''), NULLIF(TRIM(author_user.department), ''), 'Постановщик'),
       COALESCE(NULLIF(TRIM(ps.code), ''), NULLIF(TRIM(t.section), ''), NULLIF(TRIM(t.discipline), ''), NULLIF(TRIM(ps.volume), ''), NULLIF(TRIM(t.volume), ''), NULLIF(TRIM(assignee_user.department), ''), 'Исполнитель'),
       '',
       COALESCE(t.date_start, DATE(t.created_at)),
       t.date_end,
       CASE
           WHEN COALESCE(NULLIF(TRIM(ts.what), ''), '') = '' THEN 'blocked'
           WHEN t.status IN ('done', 'issued') THEN 'done'
           WHEN t.status IN ('blocked', 'overdue', 'correction') THEN 'blocked'
           WHEN t.status IN ('new', 'review', 'pending_close') THEN 'pending'
           ELSE 'in_progress'
       END,
       CONCAT('Из задачи #', t.id, '; восстановлено миграцией 097')
FROM tasks t
LEFT JOIN task_smart ts ON ts.task_id = t.id
LEFT JOIN tasks ptask ON ptask.id = t.parent_id
LEFT JOIN users author_user ON author_user.id = t.author_id
LEFT JOIN users assignee_user ON assignee_user.id = t.assignee_id
LEFT JOIN (
    SELECT task_id, MIN(code) AS code, MIN(volume) AS volume
    FROM project_sections
    WHERE task_id IS NOT NULL
    GROUP BY task_id
) ps ON ps.task_id = t.id
LEFT JOIN project_task_exchange existing
  ON existing.project_id = t.project_id AND existing.task_id = t.id
WHERE t.task_type = 'assignment'
  AND existing.id IS NULL;

UPDATE notifications n
INNER JOIN tasks t ON t.id = n.task_id
LEFT JOIN task_deadline_shifts pending_shift
  ON pending_shift.task_id = t.id
 AND pending_shift.status = 'pending'
 AND (pending_shift.date_old <=> t.date_end)
SET n.read_at = CURRENT_TIMESTAMP
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
      OR (n.type = 'approval_review_lead'
          AND NOT (t.approval_stage = 'review_lead'
                   AND t.status NOT IN ('review', 'pending_close')
                   AND t.close_requested_at IS NULL))
      OR (n.type = 'approval_review_gip'
          AND NOT (t.approval_stage = 'review_gip'
                   AND t.status NOT IN ('review', 'pending_close')
                   AND t.close_requested_at IS NULL))
      OR (n.type = 'review_task_created'
          AND NOT (t.status IN ('review', 'pending_close')
                   AND t.close_requested_at IS NOT NULL))
      OR (n.type = 'close_gip_requested'
          AND NOT (t.status = 'pending_close'
                   AND t.close_requested_at IS NOT NULL))
      OR (n.type = 'deadline_shift_requested' AND pending_shift.id IS NULL)
  );
