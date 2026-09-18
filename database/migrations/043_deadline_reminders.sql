CREATE TABLE IF NOT EXISTS deadline_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reminder_date DATE NOT NULL,
    kind VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deadline_reminders_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_deadline_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_deadline_reminder (task_id, user_id, reminder_date, kind),
    INDEX idx_deadline_reminders_user_date (user_id, reminder_date),
    INDEX idx_deadline_reminders_task_date (task_id, reminder_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
