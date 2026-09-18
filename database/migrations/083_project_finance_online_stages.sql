ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS budget_cost_thousand DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER budget_manual_thousand,
    ADD COLUMN IF NOT EXISTS budget_profit_thousand DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER budget_cost_thousand,
    ADD COLUMN IF NOT EXISTS budget_bonus_thousand DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER budget_profit_thousand;

UPDATE projects
SET budget_cost_thousand = COALESCE(budget_manual_thousand, 0)
WHERE budget_cost_thousand = 0 AND budget_profit_thousand = 0 AND budget_bonus_thousand = 0 AND COALESCE(budget_manual_thousand, 0) > 0;

-- Older installations may contain any value from the original project-stage
-- ENUM. Normalize every value that is not part of the new contract while the
-- old ENUM still accepts the safe fallback `РД`; otherwise strict MariaDB
-- rejects the following ENUM change with "Data truncated".
UPDATE projects SET stage = 'РД' WHERE stage NOT IN ('ПД', 'РД');

ALTER TABLE projects MODIFY COLUMN stage ENUM('ПД','РД','ПД-РД','АН') NOT NULL DEFAULT 'РД';
