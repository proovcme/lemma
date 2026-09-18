ALTER TABLE project_sections
    ADD COLUMN IF NOT EXISTS reviewer_id BIGINT UNSIGNED NULL AFTER assignee_id;

CREATE INDEX IF NOT EXISTS idx_project_sections_reviewer
    ON project_sections (reviewer_id);

ALTER TABLE project_sections
    ADD CONSTRAINT fk_project_sections_reviewer
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL;
