ALTER TABLE project_task_exchange
    ADD COLUMN file_url VARCHAR(1000) NULL AFTER to_section;

ALTER TABLE project_task_exchange
    MODIFY status ENUM('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending';
