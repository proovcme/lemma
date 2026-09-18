ALTER TABLE project_model_links
    ADD COLUMN IF NOT EXISTS model_scope VARCHAR(20) NOT NULL DEFAULT 'project' AFTER kind;

UPDATE project_model_links
SET model_scope = 'project'
WHERE model_scope IS NULL OR model_scope = '';
