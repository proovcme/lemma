CREATE TABLE IF NOT EXISTS task_issuances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    issue_number INT UNSIGNED NOT NULL,
    issued_at DATE NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    comment TEXT NULL,
    status ENUM('issued','remarks','accepted') NOT NULL DEFAULT 'issued',
    CONSTRAINT fk_task_issuances_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_issuances_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_task_issuances_task_number (task_id, issue_number),
    INDEX idx_task_issuances_task_status (task_id, status, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
