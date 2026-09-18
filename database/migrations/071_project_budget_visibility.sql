ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS budget_manual_thousand DECIMAL(14,2) NULL AFTER model_folder_url;

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS budget_comment TEXT NULL AFTER budget_manual_thousand;
