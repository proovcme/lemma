ALTER TABLE project_cost_plan
    ADD COLUMN labor_hours DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit;

ALTER TABLE project_cost_plan
    ADD COLUMN justification TEXT NULL AFTER price_level;
