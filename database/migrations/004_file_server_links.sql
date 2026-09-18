ALTER TABLE projects
    ADD COLUMN file_folder_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER speckle_stream_url;

ALTER TABLE custom_fields
    MODIFY type ENUM('text','select','date','number','user','bool','link','links') NOT NULL DEFAULT 'text';

ALTER TABLE custom_values
    MODIFY value TEXT NULL;

INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
SELECT 'file_links', 'Файлы задачи', 'links', NULL, NULL, 0, 90
WHERE NOT EXISTS (
    SELECT 1 FROM custom_fields WHERE project_id IS NULL AND name = 'file_links'
);
