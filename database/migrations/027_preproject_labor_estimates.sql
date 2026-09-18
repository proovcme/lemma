ALTER TABLE projects
    ADD COLUMN kind ENUM('project','preproject') NOT NULL DEFAULT 'project' AFTER id;

ALTER TABLE projects
    ADD INDEX idx_projects_kind_status (kind, status);

ALTER TABLE projects
    ADD COLUMN address VARCHAR(500) NULL AFTER `object`;

ALTER TABLE projects
    ADD COLUMN object_type VARCHAR(120) NULL AFTER address;

ALTER TABLE projects
    ADD COLUMN area_m2 DECIMAL(12,2) NULL AFTER object_type;

ALTER TABLE projects
    ADD COLUMN stages_text TEXT NULL AFTER area_m2;

ALTER TABLE projects
    MODIFY stage VARCHAR(120) NOT NULL DEFAULT 'РД';

ALTER TABLE project_sections
    ADD COLUMN sbc_item_id BIGINT UNSIGNED NULL AFTER assignee_id;

ALTER TABLE project_sections
    ADD COLUMN sbc_quantity DECIMAL(12,4) NOT NULL DEFAULT 1 AFTER sbc_item_id;

ALTER TABLE project_sections
    ADD COLUMN sbc_stage_percent DECIMAL(8,2) NOT NULL DEFAULT 100 AFTER sbc_quantity;

ALTER TABLE project_sections
    ADD COLUMN sbc_deflator_coeff DECIMAL(12,4) NOT NULL DEFAULT 1 AFTER sbc_stage_percent;

ALTER TABLE project_sections
    ADD COLUMN sbc_adjustment_coeff DECIMAL(12,4) NOT NULL DEFAULT 1 AFTER sbc_deflator_coeff;

ALTER TABLE project_sections
    ADD COLUMN sbc_comment TEXT NULL AFTER sbc_adjustment_coeff;

ALTER TABLE project_sections
    ADD INDEX idx_sections_sbc_item (sbc_item_id);

ALTER TABLE project_sections
    ADD CONSTRAINT fk_sections_sbc_item FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL;

ALTER TABLE tasks
    MODIFY task_type ENUM('work','assignment','issuance','labor_estimate') NOT NULL DEFAULT 'work';

ALTER TABLE tasks
    ADD COLUMN project_section_id BIGINT UNSIGNED NULL AFTER project_id;

ALTER TABLE tasks
    ADD INDEX idx_tasks_project_section (project_section_id);

ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_project_section FOREIGN KEY (project_section_id) REFERENCES project_sections(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS project_labor_estimates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    executor_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NULL,
    executor_hours DECIMAL(10,2) NULL,
    executor_comment TEXT NULL,
    executor_submitted_at DATETIME NULL,
    gip_hours DECIMAL(10,2) NULL,
    gip_comment TEXT NULL,
    gip_approved_by BIGINT UNSIGNED NULL,
    gip_approved_at DATETIME NULL,
    director_hours DECIMAL(10,2) NULL,
    director_comment TEXT NULL,
    director_approved_by BIGINT UNSIGNED NULL,
    director_approved_at DATETIME NULL,
    status ENUM('assigned','submitted','gip_approved','director_approved') NOT NULL DEFAULT 'assigned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_labor_estimates_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_labor_estimates_section FOREIGN KEY (section_id) REFERENCES project_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_labor_estimates_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_labor_estimates_executor FOREIGN KEY (executor_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_project_labor_estimates_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_project_labor_estimates_gip_by FOREIGN KEY (gip_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_project_labor_estimates_director_by FOREIGN KEY (director_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_labor_project_status (project_id, status),
    INDEX idx_project_labor_section (section_id),
    INDEX idx_project_labor_task (task_id),
    INDEX idx_project_labor_executor (executor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_rates (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employee_rates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_rates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
