ALTER TABLE tasks
    MODIFY task_type ENUM('work','assignment','issuance','labor_estimate','review','bim_family_request','delegation') NOT NULL DEFAULT 'work';
