CREATE TABLE IF NOT EXISTS staffing_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month_start DATE NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    working_days DECIMAL(6,2) NOT NULL DEFAULT 21,
    working_hours DECIMAL(8,2) NOT NULL DEFAULT 168,
    payroll_burden_pct DECIMAL(7,3) NOT NULL DEFAULT 0,
    overhead_pct DECIMAL(7,3) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    locked_by BIGINT UNSIGNED NULL,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staffing_period_month_revision (month_start, revision),
    KEY idx_staffing_period_month_status (month_start, status, revision),
    CONSTRAINT fk_staffing_period_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_staffing_period_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staffing_plan_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id BIGINT UNSIGNED NOT NULL,
    department_code VARCHAR(50) NOT NULL,
    department_name VARCHAR(255) NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    group_name VARCHAR(255) NULL,
    position_id BIGINT UNSIGNED NULL,
    position_title VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    employee_name VARCHAR(255) NOT NULL,
    tab_number VARCHAR(100) NULL,
    fte DECIMAL(5,2) NOT NULL DEFAULT 1,
    monthly_fot DECIMAL(15,2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'occupied',
    change_type VARCHAR(30) NOT NULL DEFAULT 'none',
    change_amount DECIMAL(15,2) NULL,
    comment TEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staffing_period_user (period_id, user_id),
    KEY idx_staffing_rows_period_department (period_id, department_code, sort_order),
    CONSTRAINT fk_staffing_row_period FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_staffing_row_group FOREIGN KEY (group_id) REFERENCES department_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_staffing_row_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,
    CONSTRAINT fk_staffing_row_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staffing_personal_rates (
    period_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    hourly_rate DECIMAL(15,4) NOT NULL,
    PRIMARY KEY (period_id, user_id),
    CONSTRAINT fk_staffing_personal_period FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_staffing_personal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staffing_group_rates (
    period_id BIGINT UNSIGNED NOT NULL,
    department_code VARCHAR(50) NOT NULL,
    hourly_rate DECIMAL(15,4) NOT NULL,
    total_fte DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_fot DECIMAL(15,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (period_id, department_code),
    CONSTRAINT fk_staffing_group_period FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
