CREATE TABLE IF NOT EXISTS performance_review_stage_notices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(40) NOT NULL,
    notification_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_stage_notice (review_id, user_id, stage),
    KEY idx_pr_stage_notice_user (user_id, stage),
    CONSTRAINT fk_pr_stage_notice_review FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_stage_notice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_stage_notice_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `.175` notified managers immediately when an employee entered a cycle. Remove
-- only those misleading manager notices; an employee who is also a participant
-- keeps the personal cycle notification.
DELETE n
FROM notifications n
JOIN performance_review_cycle_notices cn ON cn.notification_id = n.id
WHERE n.type = 'performance_review_opened'
  AND EXISTS (
      SELECT 1 FROM performance_reviews manager_review
      WHERE manager_review.cycle_id = cn.cycle_id AND manager_review.manager_id = cn.user_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM performance_reviews own_review
      WHERE own_review.cycle_id = cn.cycle_id AND own_review.user_id = cn.user_id
  );

DELETE cn
FROM performance_review_cycle_notices cn
WHERE cn.notification_id IS NULL
  AND EXISTS (
      SELECT 1 FROM performance_reviews manager_review
      WHERE manager_review.cycle_id = cn.cycle_id AND manager_review.manager_id = cn.user_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM performance_reviews own_review
      WHERE own_review.cycle_id = cn.cycle_id AND own_review.user_id = cn.user_id
  );

-- Preserve an already reached manager stage when upgrading an active test cycle.
INSERT IGNORE INTO performance_review_stage_notices (review_id, user_id, stage)
SELECT r.id, r.manager_id, 'manager_ready'
FROM performance_reviews r
JOIN performance_review_cycles c ON c.id = r.cycle_id
WHERE c.status = 'active'
  AND r.manager_id IS NOT NULL
  AND r.self_matrix_submitted_at IS NOT NULL
  AND r.manager_matrix_submitted_at IS NULL;

INSERT INTO notifications (user_id, task_id, type, body, target_url)
SELECT sn.user_id, NULL, 'performance_review_manager_ready',
       CONCAT('Можно оценивать сотрудника ', employee.name, ': самооценка завершена. Ответы и баллы сотрудника будут скрыты до отправки вашей оценки.'),
       '/performance-review/manager'
FROM performance_review_stage_notices sn
JOIN performance_reviews r ON r.id = sn.review_id
JOIN users employee ON employee.id = r.user_id
WHERE sn.stage = 'manager_ready' AND sn.notification_id IS NULL;
