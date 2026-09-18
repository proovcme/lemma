CREATE TABLE IF NOT EXISTS department_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    lead_user_id BIGINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_groups_department_name (department_code, name),
    KEY idx_department_groups_department (department_code),
    KEY idx_department_groups_lead (lead_user_id),
    CONSTRAINT fk_department_groups_department FOREIGN KEY (department_code) REFERENCES departments(code) ON DELETE CASCADE,
    CONSTRAINT fk_department_groups_lead FOREIGN KEY (lead_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER department;

ALTER TABLE users
    ADD INDEX idx_users_group (group_id);

ALTER TABLE users
    ADD CONSTRAINT fk_users_group FOREIGN KEY (group_id) REFERENCES department_groups(id) ON DELETE SET NULL;
