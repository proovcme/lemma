ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER stage;

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS finish_date DATE NULL AFTER start_date;
