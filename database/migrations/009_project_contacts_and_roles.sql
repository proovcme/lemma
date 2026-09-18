ALTER TABLE projects
    ADD COLUMN gip_user_id BIGINT UNSIGNED NULL AFTER file_folder_url;

ALTER TABLE projects
    ADD COLUMN rp_user_id BIGINT UNSIGNED NULL AFTER gip_user_id;

ALTER TABLE projects
    ADD INDEX idx_projects_gip (gip_user_id),
    ADD INDEX idx_projects_rp (rp_user_id);

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_gip
    FOREIGN KEY (gip_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_rp
    FOREIGN KEY (rp_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS project_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    contact VARCHAR(255) NULL,
    organization VARCHAR(255) NULL,
    position VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_contacts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_contacts_project (project_id),
    INDEX idx_project_contacts_org (organization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
