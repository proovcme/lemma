ALTER TABLE task_approvals
    MODIFY stage ENUM('review_lead','review_gip','issued','close_author','close_gip') NOT NULL;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_approval_status ENUM('draft','pending_director','approved','rejected') NOT NULL DEFAULT 'pending_director' AFTER labor_basis;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_submitted_at DATETIME NULL AFTER labor_approval_status;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_approved_by BIGINT UNSIGNED NULL AFTER labor_submitted_at;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_approved_at DATETIME NULL AFTER labor_approved_by;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_approval_comment TEXT NULL AFTER labor_approved_at;

ALTER TABLE project_cost_plan
    ADD CONSTRAINT fk_project_cost_plan_labor_approved_by FOREIGN KEY (labor_approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE project_cost_plan
    ADD INDEX idx_cost_plan_labor_approval (labor_approval_status, labor_approved_at);

UPDATE project_cost_plan
SET labor_approval_status = 'pending_director',
    labor_submitted_at = COALESCE(labor_submitted_at, CURRENT_TIMESTAMP)
WHERE labor_approval_status IN ('draft', 'pending_director');

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_approval_status ENUM('draft','pending_director','approved','rejected') NOT NULL DEFAULT 'pending_director' AFTER labor_basis;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_submitted_at DATETIME NULL AFTER labor_approval_status;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_approved_by BIGINT UNSIGNED NULL AFTER labor_submitted_at;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_approved_at DATETIME NULL AFTER labor_approved_by;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_approval_comment TEXT NULL AFTER labor_approved_at;

ALTER TABLE cost_estimate_items
    ADD CONSTRAINT fk_cost_estimate_items_labor_approved_by FOREIGN KEY (labor_approved_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE cost_estimate_items
    ADD INDEX idx_cost_estimate_items_labor_approval (labor_approval_status, labor_approved_at);

UPDATE cost_estimate_items
SET labor_approval_status = 'pending_director',
    labor_submitted_at = COALESCE(labor_submitted_at, CURRENT_TIMESTAMP)
WHERE labor_approval_status IN ('draft', 'pending_director');
