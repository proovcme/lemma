ALTER TABLE project_labor_estimates
    ADD COLUMN work_title VARCHAR(255) NULL AFTER section_id;

ALTER TABLE project_labor_estimates
    ADD COLUMN work_description TEXT NULL AFTER work_title;

ALTER TABLE project_labor_estimates
    ADD COLUMN executor_days DECIMAL(10,2) NULL AFTER executor_hours;

ALTER TABLE project_labor_estimates
    ADD COLUMN gip_days DECIMAL(10,2) NULL AFTER gip_hours;

ALTER TABLE project_labor_estimates
    ADD COLUMN director_days DECIMAL(10,2) NULL AFTER director_hours;

ALTER TABLE project_labor_estimates
    ADD COLUMN returned_by BIGINT UNSIGNED NULL AFTER director_approved_at;

ALTER TABLE project_labor_estimates
    ADD COLUMN returned_at DATETIME NULL AFTER returned_by;

ALTER TABLE project_labor_estimates
    ADD COLUMN return_comment TEXT NULL AFTER returned_at;

ALTER TABLE project_labor_estimates
    MODIFY status ENUM('assigned','submitted','returned_to_responsible','gip_approved','returned_to_gip','director_approved') NOT NULL DEFAULT 'assigned';

CREATE TABLE IF NOT EXISTS project_labor_estimate_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    labor_estimate_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    days DECIMAL(10,2) NOT NULL DEFAULT 0,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_labor_allocations_estimate FOREIGN KEY (labor_estimate_id) REFERENCES project_labor_estimates(id) ON DELETE CASCADE,
    CONSTRAINT fk_labor_allocations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_labor_allocations_estimate (labor_estimate_id),
    INDEX idx_labor_allocations_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
