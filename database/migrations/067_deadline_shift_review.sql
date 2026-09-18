ALTER TABLE task_deadline_shifts
    ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER reason_text,
    ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS review_comment TEXT NULL AFTER reviewed_at,
    ADD CONSTRAINT fk_deadline_shifts_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_deadline_shifts_status (task_id, status);
