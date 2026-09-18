ALTER TABLE tasks
    ADD COLUMN msp_task_id INT NULL AFTER msp_task_uid,
    ADD COLUMN msp_outline_level INT NULL AFTER msp_task_id;

CREATE INDEX idx_tasks_project_msp_task_id ON tasks(project_id, msp_task_id);
