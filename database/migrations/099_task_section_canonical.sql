-- New task input uses tasks.section. Keep existing history and legacy reports intact.
UPDATE tasks
SET section = discipline
WHERE (section IS NULL OR section = '')
  AND discipline IS NOT NULL
  AND discipline <> '';

CREATE INDEX idx_tasks_section ON tasks(section);
