ALTER TABLE project_schedule
    ADD COLUMN task_id BIGINT UNSIGNED NULL AFTER project_id;

ALTER TABLE project_schedule
    ADD UNIQUE KEY uq_schedule_project_task (project_id, task_id),
    ADD INDEX idx_schedule_task (task_id);

ALTER TABLE project_schedule
    ADD CONSTRAINT fk_schedule_task
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL;
