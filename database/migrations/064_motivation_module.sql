CREATE TABLE IF NOT EXISTS motivation_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value DECIMAL(14,4) NOT NULL DEFAULT 0,
    label VARCHAR(255) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_motivation_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS motivation_grade_coefficients (
    grade VARCHAR(50) PRIMARY KEY,
    coefficient DECIMAL(10,4) NOT NULL DEFAULT 1,
    label VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_motivation_grade_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_motivation_settings (
    project_id BIGINT UNSIGNED PRIMARY KEY,
    project_fund DECIMAL(14,2) NOT NULL DEFAULT 0,
    budget_hours DECIMAL(12,2) NULL,
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
    paid_at DATE NULL,
    comment TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_motivation_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_motivation_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS motivation_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    state ENUM('draft','locked') NOT NULL DEFAULT 'draft',
    settings_snapshot TEXT NULL,
    totals_json TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by BIGINT UNSIGNED NULL,
    locked_at TIMESTAMP NULL,
    UNIQUE KEY uq_motivation_run_period_state (period_start, state),
    KEY idx_motivation_runs_period (period_start, period_end),
    CONSTRAINT fk_motivation_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_motivation_runs_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS motivation_run_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(120) NULL,
    grade VARCHAR(50) NULL,
    grade_coefficient DECIMAL(10,4) NOT NULL DEFAULT 1,
    employment_ratio DECIMAL(10,4) NOT NULL DEFAULT 1,
    locked_hours DECIMAL(12,2) NOT NULL DEFAULT 0,
    expected_hours DECIMAL(12,2) NOT NULL DEFAULT 0,
    kpi_score DECIMAL(10,4) NOT NULL DEFAULT 0,
    kpi_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    project_bonus_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    basis_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_motivation_run_user (run_id, user_id),
    KEY idx_motivation_rows_user (user_id),
    CONSTRAINT fk_motivation_rows_run FOREIGN KEY (run_id) REFERENCES motivation_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_motivation_rows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO motivation_settings (setting_key, setting_value, label) VALUES
('monthly_kpi_max', 60000, 'Максимальная месячная KPI-премия, ₽'),
('weight_timesheet_locked', 0.25, 'Вес KPI: закрытый табель'),
('weight_timesheet_completeness', 0.25, 'Вес KPI: полнота списаний'),
('weight_deadline', 0.20, 'Вес KPI: сроки'),
('weight_rework', 0.15, 'Вес KPI: возвраты'),
('weight_plan_fact', 0.15, 'Вес KPI: план/факт')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO motivation_grade_coefficients (grade, coefficient, label) VALUES
('N-12', 1.0, 'N-12'), ('N-11', 1.0, 'N-11'), ('N-10', 1.0, 'N-10'), ('N-9', 1.0, 'N-9'), ('N-8', 1.0, 'N-8'),
('N-7', 1.2, 'N-7'), ('N-6', 1.4, 'N-6'), ('N-5', 1.6, 'N-5'), ('N-4', 1.8, 'N-4'),
('N-3', 0.0, 'N-3'), ('H', 0.0, 'H'), ('Н', 0.0, 'Н'), ('0', 0.0, 'Собственник')
ON DUPLICATE KEY UPDATE label = VALUES(label);
