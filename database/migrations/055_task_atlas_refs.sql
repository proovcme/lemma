CREATE TABLE IF NOT EXISTS task_atlas_refs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    atlas_url TEXT NOT NULL,
    model_id VARCHAR(255) NULL,
    model_label VARCHAR(255) NULL,
    element_id VARCHAR(255) NULL,
    element_name VARCHAR(255) NULL,
    context_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_atlas_refs_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_atlas_refs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_atlas_refs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_atlas_refs_task (task_id),
    INDEX idx_task_atlas_refs_project (project_id)
);
