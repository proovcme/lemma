ALTER TABLE tasks
    ADD COLUMN cost_group_code VARCHAR(80) NULL AFTER section;

CREATE INDEX idx_tasks_cost_group_code ON tasks(cost_group_code);

UPDATE tasks t
LEFT JOIN users u ON u.id = t.assignee_id
SET t.cost_group_code = COALESCE(NULLIF(t.section, ''), NULLIF(u.department, ''))
WHERE t.cost_group_code IS NULL;
