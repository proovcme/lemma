CREATE TABLE IF NOT EXISTS mail_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mail_settings_updated_by (updated_by),
    CONSTRAINT fk_mail_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
