CREATE TABLE IF NOT EXISTS tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#64748b',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tags_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_tags_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tags_project_slug (project_id, slug),
    INDEX idx_tags_project (project_id),
    INDEX idx_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_tags (
    task_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, tag_id),
    CONSTRAINT fk_task_tags_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    INDEX idx_task_tags_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
