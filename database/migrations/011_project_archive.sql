ALTER TABLE projects
    MODIFY COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active';

ALTER TABLE projects
    ADD COLUMN archived_at TIMESTAMP NULL AFTER status;
