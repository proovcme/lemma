CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tab_number VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(200) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('engineer','chief_specialist','group_lead','department_head','deputy_department_head','gip','bim_manager','project_manager','deputy_director','adjacent_director','director','admin','designer','lead','head') NOT NULL DEFAULT 'engineer',
    department VARCHAR(120) NULL,
    kimai_user_id VARCHAR(80) NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    `object` VARCHAR(255) NULL,
    stage ENUM('ПД','РД','РА','ИД') NOT NULL DEFAULT 'РД',
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    color VARCHAR(20) NULL,
    kimai_project_id VARCHAR(80) NULL,
    speckle_stream_url VARCHAR(1000) NULL,
    pp VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_stage (stage),
    INDEX idx_projects_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    assignee_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NULL,
    reviewer_id BIGINT UNSIGNED NULL,
    discipline VARCHAR(50) NULL,
    volume VARCHAR(80) NULL,
    section VARCHAR(120) NULL,
    status ENUM('new','in_progress','review','correction','done','blocked','overdue','pending_close') NOT NULL DEFAULT 'new',
    priority ENUM('low','mid','high') NOT NULL DEFAULT 'mid',
    urgency ENUM('low','mid','high') NOT NULL DEFAULT 'mid',
    date_start DATE NULL,
    date_end DATE NULL,
    date_end_original DATE NULL,
    planned_hours DECIMAL(8,2) NULL,
    actual_hours DECIMAL(8,2) NULL,
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    close_requested_at DATETIME NULL,
    closed_at DATETIME NULL,
    closed_by BIGINT UNSIGNED NULL,
    msp_task_uid VARCHAR(80) NULL,
    btp TEXT NULL,
    speckle_stream_url VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tasks_project_msp_uid (project_id, msp_task_uid),
    INDEX idx_tasks_status_end (status, date_end),
    INDEX idx_tasks_assignee_status (assignee_id, status),
    INDEX idx_tasks_reviewer_status (reviewer_id, status),
    INDEX idx_tasks_parent (parent_id),
    INDEX idx_tasks_discipline (discipline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_smart (
    task_id BIGINT UNSIGNED PRIMARY KEY,
    what TEXT NOT NULL,
    when_due TEXT NOT NULL,
    why TEXT NULL,
    depends_on TEXT NULL,
    CONSTRAINT fk_task_smart_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_schedule (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    `object` VARCHAR(200) NULL,
    section VARCHAR(50) NULL,
    object_type ENUM('Малая','Средняя','Большая') NULL,
    has_id TINYINT(1) NOT NULL DEFAULT 0,
    id_readiness INT NOT NULL DEFAULT 0,
    rd_readiness INT NOT NULL DEFAULT 0,
    rd_readiness_label VARCHAR(50) NULL,
    rd_date_plan DATE NULL,
    rd_correction VARCHAR(100) NULL,
    assignee_id BIGINT UNSIGNED NULL,
    comments TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_schedule_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_schedule_project_date (project_id, rd_date_plan),
    INDEX idx_schedule_status (rd_readiness, rd_date_plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    volume VARCHAR(50) NULL,
    code VARCHAR(100) NULL,
    title TEXT NULL,
    status VARCHAR(50) NULL,
    date_start DATE NULL,
    date_end DATE NULL,
    assignee_id BIGINT UNSIGNED NULL,
    comments TEXT NULL,
    CONSTRAINT fk_sections_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_sections_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_sections_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sections_project (project_id),
    INDEX idx_sections_task (task_id),
    INDEX idx_sections_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    blocking_task_id BIGINT UNSIGNED NULL,
    num INT NULL,
    section_code VARCHAR(100) NULL,
    issue TEXT NOT NULL,
    assignee_id BIGINT UNSIGNED NULL,
    stage VARCHAR(100) NULL,
    date_raised DATE NULL,
    answer TEXT NULL,
    notes TEXT NULL,
    status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_issues_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_issues_blocking_task FOREIGN KEY (blocking_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_issues_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_issues_project_status (project_id, status),
    INDEX idx_issues_blocking_task (blocking_task_id),
    INDEX idx_issues_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_data_registry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    blocking_task_ids TEXT NULL,
    num INT NULL,
    section_code VARCHAR(100) NULL,
    missing_data TEXT NULL,
    responsible VARCHAR(200) NULL,
    status ENUM('waiting','received','not_needed') NOT NULL DEFAULT 'waiting',
    date_requested DATE NULL,
    date_received_plan DATE NULL,
    impact TEXT NULL,
    comments TEXT NULL,
    CONSTRAINT fk_data_registry_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_data_registry_project_status (project_id, status),
    INDEX idx_data_registry_status_date (status, date_received_plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS counterparties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(255) NOT NULL,
    role VARCHAR(120) NULL,
    representative VARCHAR(160) NULL,
    contact VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_counterparties_identity (company, role, representative),
    INDEX idx_counterparties_company (company),
    INDEX idx_counterparties_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_template_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    scope_section VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exchange_template_sets_code (code),
    INDEX idx_exchange_template_sets_active (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_template_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_set_id BIGINT UNSIGNED NOT NULL,
    item_code VARCHAR(120) NOT NULL,
    direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'incoming',
    from_section VARCHAR(50) NULL,
    to_section VARCHAR(50) NULL,
    assignment TEXT NOT NULL,
    default_status ENUM('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending',
    comments TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_exchange_template_items_set FOREIGN KEY (template_set_id) REFERENCES exchange_template_sets(id) ON DELETE CASCADE,
    UNIQUE KEY uq_exchange_template_items_code (template_set_id, item_code),
    INDEX idx_exchange_template_items_set_order (template_set_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_task_exchange (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    template_item_id BIGINT UNSIGNED NULL,
    direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
    from_user_id BIGINT UNSIGNED NULL,
    from_counterparty_id BIGINT UNSIGNED NULL,
    from_external_name VARCHAR(255) NULL,
    to_user_id BIGINT UNSIGNED NULL,
    to_counterparty_id BIGINT UNSIGNED NULL,
    to_external_name VARCHAR(255) NULL,
    num INT NULL,
    assignment TEXT NULL,
    from_section VARCHAR(50) NULL,
    to_section VARCHAR(50) NULL,
    file_url VARCHAR(1000) NULL,
    date_issued DATE NULL,
    deadline DATE NULL,
    status ENUM('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending',
    comments TEXT NULL,
    CONSTRAINT fk_task_exchange_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_exchange_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_exchange_template_item FOREIGN KEY (template_item_id) REFERENCES exchange_template_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_exchange_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_exchange_from_counterparty FOREIGN KEY (from_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    CONSTRAINT fk_exchange_to_user FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_exchange_to_counterparty FOREIGN KEY (to_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    INDEX idx_exchange_project_status (project_id, status),
    INDEX idx_exchange_deadline (deadline),
    UNIQUE KEY uq_exchange_project_task (project_id, task_id),
    INDEX idx_exchange_task (task_id),
    INDEX idx_exchange_template_item (template_item_id),
    INDEX idx_exchange_direction (direction),
    INDEX idx_exchange_from_user (from_user_id),
    INDEX idx_exchange_to_user (to_user_id),
    INDEX idx_exchange_from_counterparty (from_counterparty_id),
    INDEX idx_exchange_to_counterparty (to_counterparty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    label VARCHAR(160) NOT NULL,
    type ENUM('text','select','date','number','user','bool','link','links') NOT NULL DEFAULT 'text',
    project_id BIGINT UNSIGNED NULL,
    options JSON NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_custom_fields_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY uq_custom_field_scope_name (project_id, name),
    INDEX idx_custom_fields_scope_sort (project_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_values (
    task_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    value VARCHAR(1000) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, field_id),
    CONSTRAINT fk_custom_values_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_custom_values_field FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    mention_ids JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    CONSTRAINT fk_comments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_comments_task_created (task_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_reads (
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_read_at DATETIME NOT NULL,
    PRIMARY KEY (task_id, user_id),
    CONSTRAINT fk_comment_reads_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    field VARCHAR(64) NOT NULL,
    old_val TEXT NULL,
    new_val TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_logs_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_logs_task_created (task_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    filename VARCHAR(255) NOT NULL,
    path VARCHAR(1000) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_attachments_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deadline_shift_reasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_deadline_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    shifted_by BIGINT UNSIGNED NULL,
    date_old DATE NULL,
    date_new DATE NOT NULL,
    reason_code VARCHAR(50) NOT NULL,
    reason_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deadline_shifts_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_deadline_shifts_user FOREIGN KEY (shifted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_deadline_shifts_task (task_id),
    INDEX idx_deadline_shifts_reason (reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    type VARCHAR(80) NOT NULL,
    body VARCHAR(500) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO deadline_shift_reasons (code, label, active) VALUES
('client_data', 'Задержка исходных данных от заказчика', 1),
('interdep_data', 'Задержка задания от смежного раздела', 1),
('tech_change', 'Изменение технического задания', 1),
('resource', 'Недостаток ресурса исполнителя', 1),
('expert', 'Замечания экспертизы', 1),
('other', 'Иное (требует развёрнутого комментария)', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), active = VALUES(active);
