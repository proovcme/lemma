CREATE TABLE IF NOT EXISTS time_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    mode ENUM('manual_week','distribute_day','repeat_day','task_quick') NOT NULL DEFAULT 'manual_week',
    status ENUM('draft','submitted','approved','locked') NOT NULL DEFAULT 'draft',
    total_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_time_batches_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_time_batches_user_period (user_id, period_start, period_end),
    INDEX idx_time_batches_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    task_id BIGINT UNSIGNED NULL,
    work_date DATE NOT NULL,
    minutes INT UNSIGNED NOT NULL,
    category ENUM('task','meeting','admin','learning','idle','absence','overtime','other') NOT NULL DEFAULT 'task',
    phase ENUM('execution','review','correction','repeat_review','management','other') NOT NULL DEFAULT 'execution',
    comment VARCHAR(500) NULL,
    status ENUM('draft','submitted','approved','locked') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_time_entries_batch FOREIGN KEY (batch_id) REFERENCES time_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_time_entries_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_entries_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    INDEX idx_time_entries_user_date (user_id, work_date),
    INDEX idx_time_entries_task_date (task_id, work_date),
    INDEX idx_time_entries_project_date (project_id, work_date),
    INDEX idx_time_entries_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
