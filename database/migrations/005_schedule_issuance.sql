ALTER TABLE project_schedule
    ADD COLUMN volume VARCHAR(80) NULL AFTER project_id,
    ADD COLUMN date_issued DATE NULL AFTER rd_date_plan,
    ADD COLUMN issue_status VARCHAR(50) NULL AFTER date_issued;
