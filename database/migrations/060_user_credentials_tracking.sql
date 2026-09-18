ALTER TABLE users
    ADD COLUMN password_reset_at TIMESTAMP NULL AFTER last_login,
    ADD COLUMN password_reset_by BIGINT UNSIGNED NULL AFTER password_reset_at,
    ADD COLUMN credentials_mail_marked_sent_at TIMESTAMP NULL AFTER password_reset_by,
    ADD COLUMN credentials_mail_marked_sent_by BIGINT UNSIGNED NULL AFTER credentials_mail_marked_sent_at;

ALTER TABLE users
    ADD INDEX idx_users_password_reset_at (password_reset_at),
    ADD INDEX idx_users_credentials_mail_sent_at (credentials_mail_marked_sent_at),
    ADD CONSTRAINT fk_users_password_reset_by FOREIGN KEY (password_reset_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_credentials_mail_sent_by FOREIGN KEY (credentials_mail_marked_sent_by) REFERENCES users(id) ON DELETE SET NULL;
