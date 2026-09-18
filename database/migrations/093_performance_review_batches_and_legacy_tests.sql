-- Все Performance Review, существовавшие до этого обновления, были пробными
-- прогонами на production. Меняем только тип цикла: ответы, статусы, snapshots,
-- уведомления и комментарии очной встречи остаются без изменений.
UPDATE performance_review_cycles
SET cycle_kind = 'test'
WHERE cycle_kind <> 'test';

ALTER TABLE performance_reviews
    ADD COLUMN IF NOT EXISTS launch_batch_no INT NULL AFTER status;

ALTER TABLE performance_reviews
    ADD COLUMN IF NOT EXISTS launched_at TIMESTAMP NULL AFTER launch_batch_no;

-- Старые уже открытые ревью считаем первой исторической партией своего цикла.
UPDATE performance_reviews r
JOIN performance_review_cycles c ON c.id = r.cycle_id
SET r.launch_batch_no = 1,
    r.launched_at = COALESCE(c.audience_opened_at, r.updated_at, r.created_at)
WHERE r.status <> 'draft'
  AND r.launch_batch_no IS NULL;

ALTER TABLE performance_reviews
    ADD INDEX IF NOT EXISTS idx_pr_reviews_cycle_batch (cycle_id, launch_batch_no, launched_at);
