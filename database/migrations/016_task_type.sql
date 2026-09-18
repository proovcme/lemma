ALTER TABLE tasks
    ADD COLUMN task_type ENUM('work','assignment') NOT NULL DEFAULT 'work' AFTER title;

ALTER TABLE tasks
    ADD INDEX idx_tasks_type_project (task_type, project_id);

UPDATE tasks t
INNER JOIN project_task_exchange x ON x.task_id = t.id
SET t.task_type = 'assignment';
