ALTER TABLE project_cost_plan
    ADD COLUMN labor_estimate_method VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER labor_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_executor_hours DECIMAL(10,2) NULL AFTER labor_estimate_method;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_gip_hours DECIMAL(10,2) NULL AFTER labor_executor_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_adjustment_hours DECIMAL(10,2) NULL AFTER labor_gip_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_directive_hours DECIMAL(10,2) NULL AFTER labor_adjustment_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_norm_hours DECIMAL(10,2) NULL AFTER labor_directive_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_productivity_rate DECIMAL(12,4) NULL AFTER labor_norm_hours;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_productivity_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER labor_productivity_rate;

ALTER TABLE project_cost_plan
    ADD COLUMN labor_basis TEXT NULL AFTER labor_productivity_coeff;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_estimate_method VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER labor_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_executor_hours DECIMAL(10,2) NULL AFTER labor_estimate_method;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_gip_hours DECIMAL(10,2) NULL AFTER labor_executor_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_adjustment_hours DECIMAL(10,2) NULL AFTER labor_gip_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_directive_hours DECIMAL(10,2) NULL AFTER labor_adjustment_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_norm_hours DECIMAL(10,2) NULL AFTER labor_directive_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_productivity_rate DECIMAL(12,4) NULL AFTER labor_norm_hours;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_productivity_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER labor_productivity_rate;

ALTER TABLE cost_estimate_items
    ADD COLUMN labor_basis TEXT NULL AFTER labor_productivity_coeff;
