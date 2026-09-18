ALTER TABLE users
    MODIFY role ENUM(
        'engineer',
        'chief_specialist',
        'group_lead',
        'department_head',
        'deputy_department_head',
        'gip',
        'bim_manager',
        'project_manager',
        'deputy_director',
        'adjacent_director',
        'hr',
        'director',
        'admin',
        'designer',
        'lead',
        'head'
    ) NOT NULL DEFAULT 'engineer';

CREATE TABLE IF NOT EXISTS performance_review_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_templates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_review_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    question_key VARCHAR(120) NOT NULL,
    label TEXT NOT NULL,
    question_type ENUM('text','textarea','rating_1_5','yes_no') NOT NULL DEFAULT 'textarea',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_question_key (template_id, question_key),
    KEY idx_pr_questions_template (template_id, sort_order),
    CONSTRAINT fk_pr_questions_template FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_review_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    status ENUM('draft','active','closed','cancelled') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    KEY idx_pr_cycles_status (status, period_start),
    CONSTRAINT fk_pr_cycles_template FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_cycles_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_cycles_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NULL,
    status ENUM('draft','self_review','manager_review','hr_review','closed','cancelled') NOT NULL DEFAULT 'draft',
    self_submitted_at TIMESTAMP NULL,
    manager_submitted_at TIMESTAMP NULL,
    hr_closed_at TIMESTAMP NULL,
    hr_closed_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_review_user_cycle (cycle_id, user_id),
    KEY idx_pr_reviews_user_status (user_id, status),
    KEY idx_pr_reviews_manager_status (manager_id, status),
    CONSTRAINT fk_pr_reviews_cycle FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_reviews_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_reviews_closed_by FOREIGN KEY (hr_closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_reviews_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_review_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    answer_scope ENUM('self','manager','hr') NOT NULL,
    answer_value TEXT NULL,
    question_label_snapshot TEXT NOT NULL,
    question_type_snapshot VARCHAR(32) NOT NULL,
    answered_by BIGINT UNSIGNED NULL,
    answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_answer_scope (review_id, question_id, answer_scope),
    KEY idx_pr_answers_review (review_id, answer_scope),
    CONSTRAINT fk_pr_answers_review FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_answers_question FOREIGN KEY (question_id) REFERENCES performance_review_questions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_answers_by FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_access_permissions (role, capability, enabled)
SELECT r.role, c.capability,
    CASE
        WHEN r.role = 'hr' AND c.capability IN ('locia', 'hr') THEN 1
        WHEN r.role IN ('director', 'admin') AND c.capability = 'hr' THEN 1
        ELSE 0
    END AS enabled
FROM (
    SELECT 'engineer' AS role UNION ALL
    SELECT 'chief_specialist' UNION ALL
    SELECT 'group_lead' UNION ALL
    SELECT 'department_head' UNION ALL
    SELECT 'deputy_department_head' UNION ALL
    SELECT 'gip' UNION ALL
    SELECT 'bim_manager' UNION ALL
    SELECT 'project_manager' UNION ALL
    SELECT 'deputy_director' UNION ALL
    SELECT 'adjacent_director' UNION ALL
    SELECT 'hr' UNION ALL
    SELECT 'director' UNION ALL
    SELECT 'admin'
) r
CROSS JOIN (
    SELECT 'locia' AS capability UNION ALL
    SELECT 'projects' UNION ALL
    SELECT 'projects_all' UNION ALL
    SELECT 'projects_create' UNION ALL
    SELECT 'tasks_edit_all' UNION ALL
    SELECT 'dpr' UNION ALL
    SELECT 'reports' UNION ALL
    SELECT 'integrations' UNION ALL
    SELECT 'users' UNION ALL
    SELECT 'settings' UNION ALL
    SELECT 'delete' UNION ALL
    SELECT 'competency' UNION ALL
    SELECT 'bim' UNION ALL
    SELECT 'hr'
) c
WHERE r.role = 'hr' OR c.capability = 'hr'
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled);

INSERT INTO performance_review_templates (title, description, is_builtin, is_active)
SELECT 'Performance Review v1', 'Пустой встроенный шаблон: вопросы добавляет HR.', 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM performance_review_templates WHERE title = 'Performance Review v1'
);
