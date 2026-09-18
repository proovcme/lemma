CREATE TABLE IF NOT EXISTS project_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    project_role VARCHAR(128) NULL,
    allocation_percent DECIMAL(5,2) NULL,
    date_start DATE NULL,
    date_end DATE NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_members_user (project_id, user_id),
    KEY idx_project_members_project_active (project_id, active),
    KEY idx_project_members_user_active (user_id, active),
    CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_pp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(128) NOT NULL,
    title VARCHAR(255) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_pp_code (project_id, code),
    KEY idx_project_pp_project_active (project_id, active, sort_order),
    CONSTRAINT fk_project_pp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_btp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    pp_code_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(128) NOT NULL,
    title VARCHAR(255) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_btp_code (project_id, pp_code_id, code),
    KEY idx_project_btp_project_active (project_id, active, sort_order),
    KEY idx_project_btp_pp (pp_code_id),
    CONSTRAINT fk_project_btp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_btp_pp FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_uts_facts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    pp_code_id BIGINT UNSIGNED NOT NULL,
    btp_code_id BIGINT UNSIGNED NULL,
    fact_date DATE NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    document_ref VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_project_uts_project_date (project_id, fact_date),
    KEY idx_project_uts_pp (pp_code_id),
    KEY idx_project_uts_btp (btp_code_id),
    CONSTRAINT fk_project_uts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_uts_pp FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_uts_btp FOREIGN KEY (btp_code_id) REFERENCES project_btp_codes(id) ON DELETE SET NULL,
    CONSTRAINT fk_project_uts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS pp_code_id BIGINT UNSIGNED NULL AFTER msp_outline_level,
    ADD COLUMN IF NOT EXISTS btp_code_id BIGINT UNSIGNED NULL AFTER pp_code_id;

CREATE INDEX IF NOT EXISTS idx_tasks_pp_code ON tasks (pp_code_id);
CREATE INDEX IF NOT EXISTS idx_tasks_btp_code ON tasks (btp_code_id);

ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_pp_code FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tasks_btp_code FOREIGN KEY (btp_code_id) REFERENCES project_btp_codes(id) ON DELETE SET NULL;
