ALTER TABLE tasks
    MODIFY task_type ENUM('work','assignment','issuance') NOT NULL DEFAULT 'work';
