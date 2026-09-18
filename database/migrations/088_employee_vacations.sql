CREATE TABLE IF NOT EXISTS employee_vacations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    substitute_user_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    cancelled_at TIMESTAMP NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_employee_vacations_user_dates (user_id, date_from, date_to),
    KEY idx_employee_vacations_substitute_dates (substitute_user_id, date_from, date_to),
    CONSTRAINT fk_employee_vacations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_vacations_substitute FOREIGN KEY (substitute_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_employee_vacations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_employee_vacations_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
