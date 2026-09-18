CREATE TABLE IF NOT EXISTS project_model_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    model_url TEXT NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'json',
    model_scope VARCHAR(20) NOT NULL DEFAULT 'project',
    discipline VARCHAR(80) NULL,
    revision VARCHAR(80) NULL,
    notes TEXT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_model_links_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_model_links_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_model_links_project (project_id, is_primary, created_at),
    INDEX idx_project_model_links_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
