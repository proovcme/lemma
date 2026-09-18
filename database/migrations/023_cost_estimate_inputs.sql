ALTER TABLE cost_estimates
    ADD COLUMN object_type VARCHAR(80) NULL AFTER object;

ALTER TABLE cost_estimates
    ADD COLUMN area_m2 DECIMAL(14,2) NULL AFTER object_type;

ALTER TABLE cost_estimates
    ADD COLUMN start_date DATE NULL AFTER stage;

ALTER TABLE cost_estimates
    ADD COLUMN finish_date DATE NULL AFTER start_date;

ALTER TABLE cost_estimates
    ADD COLUMN duration_months DECIMAL(8,2) NULL AFTER finish_date;

ALTER TABLE cost_estimates
    ADD COLUMN default_stage_percent DECIMAL(6,2) NULL AFTER duration_months;

ALTER TABLE cost_estimates
    ADD COLUMN default_deflator_coeff DECIMAL(10,4) NULL AFTER default_stage_percent;

ALTER TABLE cost_estimates
    ADD COLUMN sections_text TEXT NULL AFTER default_deflator_coeff;
