CREATE TABLE IF NOT EXISTS monthly_work_ledger_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    visible_fields LONGTEXT NOT NULL,
    field_order LONGTEXT NOT NULL,
    default_group_by VARCHAR(32) NOT NULL DEFAULT 'general',
    filters_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_monthly_work_ledger_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
