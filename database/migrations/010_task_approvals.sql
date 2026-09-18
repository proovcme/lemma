ALTER TABLE tasks
    ADD COLUMN approval_stage ENUM('draft','review_lead','review_gip','approved','issued') NOT NULL DEFAULT 'draft' AFTER status;

ALTER TABLE tasks
    ADD INDEX idx_tasks_approval_stage (approval_stage);

CREATE TABLE IF NOT EXISTS task_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    stage ENUM('review_lead','review_gip','issued') NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected','issued') NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_approvals_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_approvals_user FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_task_approvals_task_created (task_id, created_at),
    INDEX idx_task_approvals_stage_decision (stage, decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE tasks t
SET approval_stage = 'issued'
WHERE approval_stage = 'draft'
  AND EXISTS (
      SELECT 1
      FROM task_issuances i
      WHERE i.task_id = t.id
  );
