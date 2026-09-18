ALTER TABLE cost_estimates
    ADD COLUMN region_code VARCHAR(80) NULL AFTER object_type;

ALTER TABLE cost_estimates
    ADD COLUMN object_class VARCHAR(40) NULL AFTER region_code;

ALTER TABLE cost_estimates
    ADD COLUMN work_type VARCHAR(40) NULL AFTER object_class;

ALTER TABLE cost_estimates
    ADD COLUMN floors DECIMAL(8,2) NULL AFTER area_m2;
