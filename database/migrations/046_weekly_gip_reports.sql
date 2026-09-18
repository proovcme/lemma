CREATE TABLE IF NOT EXISTS weekly_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(255) NULL,
    period_type VARCHAR(20) NOT NULL DEFAULT 'week',
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    portfolio_status VARCHAR(20) NOT NULL DEFAULT 'yellow',
    previous_status VARCHAR(20) NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    finances_text TEXT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'draft',
    locked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_weekly_reports_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_weekly_reports_period (period_type, date_from, date_to),
    INDEX idx_weekly_reports_state (state, date_to),
    INDEX idx_weekly_reports_author (author_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_report_projects (
    report_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (report_id, project_id),
    CONSTRAINT fk_weekly_report_projects_report FOREIGN KEY (report_id) REFERENCES weekly_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_weekly_report_projects_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_weekly_report_projects_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_report_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(40) NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
    source_id BIGINT UNSIGNED NULL,
    item_title VARCHAR(500) NOT NULL,
    plan_text TEXT NULL,
    fact_text TEXT NULL,
    deviation_text TEXT NULL,
    comment_text TEXT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_weekly_report_items_report FOREIGN KEY (report_id) REFERENCES weekly_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_weekly_report_items_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    INDEX idx_weekly_report_items_report_section (report_id, section_key, sort_order),
    INDEX idx_weekly_report_items_project (project_id),
    INDEX idx_weekly_report_items_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
