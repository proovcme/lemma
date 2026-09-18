ALTER TABLE performance_review_questions
    ADD COLUMN IF NOT EXISTS section_key VARCHAR(80) NULL AFTER question_key,
    ADD COLUMN IF NOT EXISTS section_label VARCHAR(255) NULL AFTER section_key;

ALTER TABLE performance_review_cycles
    ADD COLUMN IF NOT EXISTS review_year SMALLINT UNSIGNED NULL AFTER title,
    ADD COLUMN IF NOT EXISTS response_deadline DATE NULL AFTER period_end,
    ADD COLUMN IF NOT EXISTS questionnaire_snapshot_json LONGTEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS competency_snapshot_json LONGTEXT NULL AFTER questionnaire_snapshot_json,
    ADD COLUMN IF NOT EXISTS audience_opened_at TIMESTAMP NULL AFTER competency_snapshot_json,
    ADD COLUMN IF NOT EXISTS audience_opened_by BIGINT UNSIGNED NULL AFTER audience_opened_at,
    ADD UNIQUE INDEX IF NOT EXISTS uq_pr_cycle_review_year (review_year),
    ADD INDEX IF NOT EXISTS idx_pr_cycles_audience (status, audience_opened_at);

ALTER TABLE performance_reviews
    ADD COLUMN IF NOT EXISTS position_title_snapshot VARCHAR(255) NULL AFTER manager_id,
    ADD COLUMN IF NOT EXISTS position_grade_snapshot VARCHAR(40) NULL AFTER position_title_snapshot,
    ADD COLUMN IF NOT EXISTS competency_position_index INT NULL AFTER position_grade_snapshot,
    ADD COLUMN IF NOT EXISTS self_questionnaire_submitted_at TIMESTAMP NULL AFTER self_submitted_at,
    ADD COLUMN IF NOT EXISTS self_matrix_submitted_at TIMESTAMP NULL AFTER self_questionnaire_submitted_at,
    ADD COLUMN IF NOT EXISTS manager_matrix_submitted_at TIMESTAMP NULL AFTER manager_submitted_at,
    ADD COLUMN IF NOT EXISTS meeting_completed_at TIMESTAMP NULL AFTER hr_closed_at,
    ADD COLUMN IF NOT EXISTS meeting_completed_by BIGINT UNSIGNED NULL AFTER meeting_completed_at,
    ADD COLUMN IF NOT EXISTS meeting_notes TEXT NULL AFTER meeting_completed_by,
    ADD COLUMN IF NOT EXISTS next_year_actions TEXT NULL AFTER meeting_notes;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS target_url VARCHAR(500) NULL AFTER body;

CREATE TABLE IF NOT EXISTS performance_review_competency_scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    competency_key VARCHAR(80) NOT NULL,
    answer_scope ENUM('self','manager') NOT NULL,
    score TINYINT UNSIGNED NULL,
    comment TEXT NULL,
    competency_name_snapshot VARCHAR(255) NOT NULL,
    competency_description_snapshot TEXT NULL,
    level_1_snapshot TEXT NULL,
    level_2_snapshot TEXT NULL,
    level_3_snapshot TEXT NULL,
    level_4_snapshot TEXT NULL,
    level_5_snapshot TEXT NULL,
    required_level_snapshot TINYINT UNSIGNED NULL,
    answered_by BIGINT UNSIGNED NULL,
    answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_competency_scope (review_id, competency_key, answer_scope),
    KEY idx_pr_competency_review (review_id, answer_scope),
    CONSTRAINT fk_pr_competency_review FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_competency_answered_by FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_review_cycle_notices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_cycle_notice (cycle_id, user_id),
    CONSTRAINT fk_pr_cycle_notice_cycle FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_cycle_notice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_cycle_notice_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
