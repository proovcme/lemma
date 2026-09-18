CREATE TABLE IF NOT EXISTS document_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    issuance_id BIGINT UNSIGNED NOT NULL,
    revision_no INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_document_revisions_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_revisions_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_revisions_issuance FOREIGN KEY (issuance_id) REFERENCES task_issuances(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_revisions_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_document_revisions_task_revision (task_id, revision_no),
    INDEX idx_document_revisions_project (project_id, revision_no),
    INDEX idx_document_revisions_issuance (issuance_id)
);
