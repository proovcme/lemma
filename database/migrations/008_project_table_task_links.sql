ALTER TABLE project_data_registry
    ADD COLUMN blocking_task_ids TEXT NULL;

ALTER TABLE project_issues
    ADD COLUMN blocking_task_id BIGINT UNSIGNED NULL;

ALTER TABLE project_sections
    ADD COLUMN task_id BIGINT UNSIGNED NULL;

ALTER TABLE project_issues
    ADD INDEX idx_issues_blocking_task (blocking_task_id);

ALTER TABLE project_sections
    ADD INDEX idx_sections_task (task_id);

ALTER TABLE project_issues
    ADD CONSTRAINT fk_issues_blocking_task
    FOREIGN KEY (blocking_task_id) REFERENCES tasks(id) ON DELETE SET NULL;

ALTER TABLE project_sections
    ADD CONSTRAINT fk_sections_task
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL;
