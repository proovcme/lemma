CREATE TABLE IF NOT EXISTS personal_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    converted_task_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    color VARCHAR(24) NULL,
    status ENUM('active','archived','converted') NOT NULL DEFAULT 'active',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    converted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_personal_notes_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_personal_notes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_personal_notes_task FOREIGN KEY (converted_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    INDEX idx_personal_notes_author_status (author_id, status, pinned, updated_at),
    INDEX idx_personal_notes_project (project_id)
);
