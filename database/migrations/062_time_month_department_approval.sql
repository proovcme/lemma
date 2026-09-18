ALTER TABLE time_month_reviews
    ADD COLUMN department_approved_at TIMESTAMP NULL AFTER gip_approved_by,
    ADD COLUMN department_approved_by BIGINT UNSIGNED NULL AFTER department_approved_at,
    ADD CONSTRAINT fk_time_month_reviews_department_by FOREIGN KEY (department_approved_by) REFERENCES users(id) ON DELETE SET NULL;
