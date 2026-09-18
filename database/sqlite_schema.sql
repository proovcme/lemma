PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_key TEXT UNIQUE,
    base_role TEXT NOT NULL DEFAULT 'engineer',
    title TEXT NOT NULL UNIQUE,
    grade TEXT,
    competency_position_index INTEGER,
    description TEXT,
    sort_order INTEGER NOT NULL DEFAULT 100,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_protected INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tab_number TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'engineer',
    department TEXT,
    group_id INTEGER,
    position_id INTEGER,
    manager_id INTEGER,
    kimai_user_id TEXT,
    must_change_password INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TEXT,
    password_reset_at TEXT,
    password_reset_by INTEGER,
    credentials_mail_marked_sent_at TEXT,
    credentials_mail_marked_sent_by INTEGER,
    FOREIGN KEY (group_id) REFERENCES department_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (password_reset_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (credentials_mail_marked_sent_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    head_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (head_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS calculator_portfolio_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    amount_thousand REAL NOT NULL DEFAULT 0,
    area_m2 REAL,
    start_date TEXT,
    finish_date TEXT,
    status TEXT NOT NULL DEFAULT 'expected',
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS department_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    department_code TEXT NOT NULL,
    name TEXT NOT NULL,
    lead_user_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(department_code, name),
    FOREIGN KEY (department_code) REFERENCES departments(code) ON DELETE CASCADE,
    FOREIGN KEY (lead_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS role_access_permissions (
    role TEXT NOT NULL,
    capability TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role, capability),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS position_access_permissions (
    position_id INTEGER NOT NULL,
    capability TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id, capability),
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL DEFAULT 'project' CHECK (kind IN ('project', 'preproject')),
    code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    object TEXT,
    address TEXT,
    object_type TEXT,
    area_m2 REAL,
    stages_text TEXT,
    pp TEXT,
    stage TEXT NOT NULL DEFAULT 'РД',
    start_date TEXT,
    finish_date TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    archived_at TEXT,
    color TEXT,
    kimai_project_id TEXT,
    speckle_stream_url TEXT,
    file_folder_url TEXT NOT NULL DEFAULT '',
    model_folder_url TEXT NOT NULL DEFAULT '',
    budget_manual_thousand REAL,
    budget_cost_thousand REAL NOT NULL DEFAULT 0,
    budget_profit_thousand REAL NOT NULL DEFAULT 0,
    budget_bonus_thousand REAL NOT NULL DEFAULT 0,
    budget_comment TEXT,
    gip_user_id INTEGER,
    rp_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gip_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rp_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    contact TEXT,
    organization TEXT,
    position TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    project_role TEXT,
    allocation_percent REAL,
    date_start TEXT,
    date_end TEXT,
    notes TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_stages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    title TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_model_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    model_url TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'json',
    model_scope TEXT NOT NULL DEFAULT 'project',
    discipline TEXT,
    revision TEXT,
    notes TEXT,
    is_primary INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS public_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    project_id INTEGER,
    task_id INTEGER,
    model_link_id INTEGER,
    model_path TEXT,
    label TEXT NOT NULL,
    created_by INTEGER,
    access_count INTEGER NOT NULL DEFAULT 0,
    last_accessed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (model_link_id) REFERENCES project_model_links(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_pp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    title TEXT,
    notes TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_btp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    pp_code_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    title TEXT,
    notes TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, pp_code_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_uts_facts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    pp_code_id INTEGER NOT NULL,
    btp_code_id INTEGER,
    fact_date TEXT,
    amount REAL NOT NULL DEFAULT 0,
    description TEXT,
    document_ref TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE CASCADE,
    FOREIGN KEY (btp_code_id) REFERENCES project_btp_codes(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    task_type TEXT NOT NULL DEFAULT 'work',
    project_id INTEGER NOT NULL,
    project_section_id INTEGER,
    parent_id INTEGER,
    assignee_id INTEGER,
    author_id INTEGER,
    reviewer_id INTEGER,
    discipline TEXT,
    volume TEXT,
    section TEXT,
    cost_group_code TEXT,
    status TEXT NOT NULL DEFAULT 'new',
    approval_stage TEXT NOT NULL DEFAULT 'draft' CHECK (approval_stage IN ('draft', 'review_lead', 'review_gip', 'review_task', 'approved', 'issued')),
    priority TEXT NOT NULL DEFAULT 'mid',
    urgency TEXT NOT NULL DEFAULT 'mid',
    date_start TEXT,
    date_end TEXT,
    date_end_original TEXT,
    planned_hours REAL,
    actual_hours REAL,
    progress INTEGER NOT NULL DEFAULT 0,
    close_requested_at TEXT,
    closed_at TEXT,
    closed_by INTEGER,
    msp_task_uid TEXT,
    msp_task_id INTEGER,
    msp_outline_level INTEGER,
    pp_code_id INTEGER,
    btp_code_id INTEGER,
    btp TEXT,
    speckle_stream_url TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (project_section_id) REFERENCES project_sections(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (pp_code_id) REFERENCES project_pp_codes(id) ON DELETE SET NULL,
    FOREIGN KEY (btp_code_id) REFERENCES project_btp_codes(id) ON DELETE SET NULL,
    UNIQUE (project_id, msp_task_uid)
);

CREATE TABLE IF NOT EXISTS task_smart (
    task_id INTEGER PRIMARY KEY,
    what TEXT NOT NULL,
    when_due TEXT NOT NULL,
    why TEXT,
    depends_on TEXT,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS personal_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id INTEGER NOT NULL,
    project_id INTEGER,
    converted_task_id INTEGER,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    color TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived','converted')),
    pinned INTEGER NOT NULL DEFAULT 0,
    converted_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (converted_task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS task_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('assignee','coauthor','observer')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (task_id, user_id, role),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_atlas_refs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    atlas_url TEXT NOT NULL,
    model_id TEXT,
    model_label TEXT,
    element_id TEXT,
    element_name TEXT,
    context_json TEXT,
    viewpoint_json TEXT,
    overlay_json TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS task_issuances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    issue_number INTEGER NOT NULL,
    issued_at TEXT NOT NULL,
    issued_by INTEGER,
    comment TEXT,
    status TEXT NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'remarks', 'accepted')),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (task_id, issue_number)
);

CREATE TABLE IF NOT EXISTS document_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    issuance_id INTEGER NOT NULL,
    revision_no INTEGER NOT NULL,
    reason TEXT NOT NULL,
    summary TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (issuance_id) REFERENCES task_issuances(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (task_id, revision_no)
);

CREATE TABLE IF NOT EXISTS task_approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    stage TEXT NOT NULL CHECK (stage IN ('review_lead', 'review_gip', 'review_task', 'issued', 'close_author', 'close_gip')),
    approved_by INTEGER NOT NULL,
    decision TEXT NOT NULL CHECK (decision IN ('approved', 'rejected', 'issued')),
    comment TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS project_schedule (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    task_id INTEGER,
    volume TEXT,
    object TEXT,
    section TEXT,
    object_type TEXT,
    has_id INTEGER NOT NULL DEFAULT 0,
    id_readiness INTEGER NOT NULL DEFAULT 0,
    rd_readiness INTEGER NOT NULL DEFAULT 0,
    rd_readiness_label TEXT,
    rd_date_plan TEXT,
    date_issued TEXT,
    issue_status TEXT,
    rd_correction TEXT,
    assignee_id INTEGER,
    comments TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    task_id INTEGER,
    volume TEXT,
    code TEXT,
    title TEXT,
    status TEXT,
    date_start TEXT,
    date_end TEXT,
    assignee_id INTEGER,
    reviewer_id INTEGER,
    stage_id INTEGER,
    work_kind TEXT NOT NULL DEFAULT 'section',
    sort_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    sbc_item_id INTEGER,
    sbc_quantity REAL NOT NULL DEFAULT 1,
    sbc_stage_percent REAL NOT NULL DEFAULT 100,
    sbc_deflator_coeff REAL NOT NULL DEFAULT 1,
    sbc_adjustment_coeff REAL NOT NULL DEFAULT 1,
    sbc_comment TEXT,
    comments TEXT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (stage_id) REFERENCES project_stages(id) ON DELETE CASCADE,
    FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_project_sections_reviewer ON project_sections(reviewer_id);
CREATE INDEX IF NOT EXISTS idx_project_sections_stage ON project_sections(project_id, stage_id, work_kind, sort_order);

CREATE TABLE IF NOT EXISTS project_section_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_section_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    assignment_role TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_section_id, user_id, assignment_role),
    FOREIGN KEY (project_section_id) REFERENCES project_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_section_assignments_role ON project_section_assignments(project_section_id, assignment_role, sort_order);

CREATE TABLE IF NOT EXISTS project_health_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL DEFAULT 0,
    comment_text TEXT NOT NULL,
    author_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, date_from, date_to, entity_type, entity_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS project_issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    blocking_task_id INTEGER,
    num INTEGER,
    section_code TEXT,
    issue TEXT NOT NULL,
    assignee_id INTEGER,
    stage TEXT,
    date_raised TEXT,
    answer TEXT,
    notes TEXT,
    status TEXT NOT NULL DEFAULT 'open',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (blocking_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_data_registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    blocking_task_ids TEXT,
    num INTEGER,
    section_code TEXT,
    missing_data TEXT,
    responsible TEXT,
    status TEXT NOT NULL DEFAULT 'waiting',
    date_requested TEXT,
    date_received_plan TEXT,
    impact TEXT,
    comments TEXT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS counterparties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company TEXT NOT NULL,
    role TEXT,
    representative TEXT,
    contact TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (company, role, representative)
);

CREATE TABLE IF NOT EXISTS exchange_template_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    scope_section TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS exchange_template_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_set_id INTEGER NOT NULL,
    item_code TEXT NOT NULL,
    direction TEXT NOT NULL DEFAULT 'incoming',
    from_section TEXT,
    to_section TEXT,
    assignment TEXT NOT NULL,
    default_status TEXT NOT NULL DEFAULT 'pending',
    comments TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (template_set_id, item_code),
    FOREIGN KEY (template_set_id) REFERENCES exchange_template_sets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_task_exchange (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    task_id INTEGER,
    template_item_id INTEGER,
    direction TEXT NOT NULL DEFAULT 'outgoing',
    from_user_id INTEGER,
    from_counterparty_id INTEGER,
    from_external_name TEXT,
    to_user_id INTEGER,
    to_counterparty_id INTEGER,
    to_external_name TEXT,
    num INTEGER,
    assignment TEXT,
    from_section TEXT,
    to_section TEXT,
    file_url TEXT,
    date_issued TEXT,
    deadline TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    comments TEXT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (template_item_id) REFERENCES exchange_template_items(id) ON DELETE SET NULL,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (from_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (to_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sbc_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference_hash TEXT NOT NULL UNIQUE,
    collection_code TEXT NOT NULL DEFAULT '',
    collection_name TEXT NOT NULL,
    edition TEXT,
    table_code TEXT,
    item_code TEXT NOT NULL DEFAULT '',
    work_name TEXT NOT NULL,
    unit TEXT,
    base_price REAL NOT NULL DEFAULT 0,
    price_level TEXT,
    default_labor_hours REAL NOT NULL DEFAULT 0,
    formula TEXT,
    note TEXT,
    source_ref TEXT,
    justification_template TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sbc_indices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    index_value REAL NOT NULL DEFAULT 1,
    source_ref TEXT,
    source_date TEXT,
    comment TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    updated_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_cost_plan (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    num INTEGER,
    section_code TEXT,
    sbc_item_id INTEGER,
    sbc_collection TEXT,
    sbc_table TEXT,
    work_name TEXT NOT NULL,
    unit TEXT,
    labor_hours REAL NOT NULL DEFAULT 0,
    labor_estimate_method TEXT NOT NULL DEFAULT 'manual',
    labor_executor_hours REAL,
    labor_gip_hours REAL,
    labor_adjustment_hours REAL,
    labor_directive_hours REAL,
    labor_norm_hours REAL,
    labor_productivity_rate REAL,
    labor_productivity_coeff REAL NOT NULL DEFAULT 1,
    labor_basis TEXT,
    labor_approval_status TEXT NOT NULL DEFAULT 'pending_director',
    labor_submitted_at TEXT,
    labor_approved_by INTEGER,
    labor_approved_at TEXT,
    labor_approval_comment TEXT,
    quantity REAL NOT NULL DEFAULT 1,
    base_price REAL NOT NULL DEFAULT 0,
    stage_percent REAL NOT NULL DEFAULT 100,
    complexity_coeff REAL NOT NULL DEFAULT 1,
    deflator_coeff REAL NOT NULL DEFAULT 1,
    adjustment_coeff REAL NOT NULL DEFAULT 1,
    planned_cost REAL NOT NULL DEFAULT 0,
    price_level TEXT,
    justification TEXT,
    comments TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL,
    FOREIGN KEY (labor_approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_labor_estimates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    section_id INTEGER NOT NULL,
    work_title TEXT,
    work_description TEXT,
    task_id INTEGER,
    executor_id INTEGER NOT NULL,
    department_code TEXT,
    model_object_type TEXT,
    model_stage TEXT,
    model_area_m2 REAL,
    model_quantity REAL NOT NULL DEFAULT 1,
    model_complexity_coeff REAL NOT NULL DEFAULT 1,
    model_typicality_coeff REAL NOT NULL DEFAULT 1,
    model_bim_coeff REAL NOT NULL DEFAULT 1,
    model_urgency_coeff REAL NOT NULL DEFAULT 1,
    model_input_quality_coeff REAL NOT NULL DEFAULT 1,
    model_suggested_hours REAL NOT NULL DEFAULT 0,
    model_basis TEXT,
    sbc_item_id INTEGER,
    sbc_quantity REAL NOT NULL DEFAULT 1,
    sbc_stage_percent REAL NOT NULL DEFAULT 100,
    sbc_index_id INTEGER,
    sbc_adjustment_coeff REAL NOT NULL DEFAULT 1,
    sbc_cost_snapshot REAL NOT NULL DEFAULT 0,
    sbc_basis_snapshot TEXT,
    requested_by INTEGER,
    executor_hours REAL,
    executor_days REAL,
    executor_comment TEXT,
    executor_submitted_at TEXT,
    department_submitted_by INTEGER,
    department_submitted_at TEXT,
    gip_hours REAL,
    gip_days REAL,
    gip_comment TEXT,
    gip_approved_by INTEGER,
    gip_approved_at TEXT,
    gip_adjusted_at TEXT,
    director_hours REAL,
    director_days REAL,
    director_comment TEXT,
    director_approved_by INTEGER,
    director_approved_at TEXT,
    director_cost_thousand REAL NOT NULL DEFAULT 0,
    director_money_snapshot TEXT,
    returned_by INTEGER,
    returned_at TEXT,
    return_comment TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','department_submitted','gip_adjusted','returned_to_department','director_approved','assigned','submitted','returned_to_responsible','gip_approved','returned_to_gip')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES project_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (executor_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL,
    FOREIGN KEY (sbc_index_id) REFERENCES sbc_indices(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (gip_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (director_approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_labor_estimate_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    labor_estimate_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    hours REAL NOT NULL DEFAULT 0,
    days REAL NOT NULL DEFAULT 0,
    comment TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (labor_estimate_id) REFERENCES project_labor_estimates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS employee_rates (
    user_id INTEGER PRIMARY KEY,
    hourly_rate REAL NOT NULL DEFAULT 0,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS staffing_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    month_start TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'locked', 'superseded')),
    working_days REAL NOT NULL DEFAULT 21,
    working_hours REAL NOT NULL DEFAULT 168,
    payroll_burden_pct REAL NOT NULL DEFAULT 0,
    overhead_pct REAL NOT NULL DEFAULT 0,
    note TEXT,
    created_by INTEGER,
    locked_by INTEGER,
    locked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE(month_start, revision)
);

CREATE TABLE IF NOT EXISTS staffing_plan_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_id INTEGER NOT NULL,
    department_code TEXT NOT NULL,
    department_name TEXT NOT NULL,
    group_id INTEGER,
    group_name TEXT,
    position_id INTEGER,
    position_title TEXT NOT NULL,
    user_id INTEGER,
    employee_name TEXT NOT NULL,
    tab_number TEXT,
    fte REAL NOT NULL DEFAULT 1,
    monthly_fot REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'occupied' CHECK (status IN ('occupied', 'vacancy', 'hiring', 'transfer', 'reduction')),
    change_type TEXT NOT NULL DEFAULT 'none' CHECK (change_type IN ('none', 'hire', 'transfer', 'reduction', 'other')),
    change_amount REAL,
    comment TEXT,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(period_id, user_id),
    FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES department_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_staffing_rows_period_department
    ON staffing_plan_rows(period_id, department_code, sort_order);

CREATE INDEX IF NOT EXISTS idx_staffing_period_month_status
    ON staffing_periods(month_start, status, revision);

CREATE TABLE IF NOT EXISTS staffing_personal_rates (
    period_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    hourly_rate REAL NOT NULL,
    PRIMARY KEY (period_id, user_id),
    FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS staffing_group_rates (
    period_id INTEGER NOT NULL,
    department_code TEXT NOT NULL,
    hourly_rate REAL NOT NULL,
    total_fte REAL NOT NULL DEFAULT 0,
    total_fot REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (period_id, department_code),
    FOREIGN KEY (period_id) REFERENCES staffing_periods(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cost_estimates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER,
    code TEXT NOT NULL,
    title TEXT NOT NULL,
    object TEXT,
    object_type TEXT,
    region_code TEXT,
    object_class TEXT,
    work_type TEXT,
    area_m2 REAL,
    floors REAL,
    stage TEXT,
    start_date TEXT,
    finish_date TEXT,
    duration_months REAL,
    default_stage_percent REAL,
    default_deflator_coeff REAL,
    sections_text TEXT,
    customer TEXT,
    status TEXT NOT NULL DEFAULT 'preproject',
    price_level TEXT,
    notes TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS cost_estimate_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estimate_id INTEGER NOT NULL,
    num INTEGER,
    section_code TEXT,
    sbc_item_id INTEGER,
    sbc_collection TEXT,
    sbc_table TEXT,
    work_name TEXT NOT NULL,
    unit TEXT,
    labor_hours REAL NOT NULL DEFAULT 0,
    labor_estimate_method TEXT NOT NULL DEFAULT 'manual',
    labor_executor_hours REAL,
    labor_gip_hours REAL,
    labor_adjustment_hours REAL,
    labor_directive_hours REAL,
    labor_norm_hours REAL,
    labor_productivity_rate REAL,
    labor_productivity_coeff REAL NOT NULL DEFAULT 1,
    labor_basis TEXT,
    labor_approval_status TEXT NOT NULL DEFAULT 'pending_director',
    labor_submitted_at TEXT,
    labor_approved_by INTEGER,
    labor_approved_at TEXT,
    labor_approval_comment TEXT,
    quantity REAL NOT NULL DEFAULT 1,
    base_price REAL NOT NULL DEFAULT 0,
    stage_percent REAL NOT NULL DEFAULT 100,
    complexity_coeff REAL NOT NULL DEFAULT 1,
    deflator_coeff REAL NOT NULL DEFAULT 1,
    adjustment_coeff REAL NOT NULL DEFAULT 1,
    planned_cost REAL NOT NULL DEFAULT 0,
    price_level TEXT,
    justification TEXT,
    comments TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (estimate_id) REFERENCES cost_estimates(id) ON DELETE CASCADE,
    FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL,
    FOREIGN KEY (labor_approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS custom_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    label TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'text',
    project_id INTEGER,
    options TEXT,
    required INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS custom_values (
    task_id INTEGER NOT NULL,
    field_id INTEGER NOT NULL,
    value TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, field_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#64748b',
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS task_tags (
    task_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, tag_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    user_id INTEGER,
    body TEXT NOT NULL,
    mention_ids TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS comment_reads (
    task_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    last_read_at TEXT NOT NULL,
    PRIMARY KEY (task_id, user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    user_id INTEGER,
    field TEXT NOT NULL,
    old_val TEXT,
    new_val TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope TEXT NOT NULL DEFAULT 'locia' CHECK (scope IN ('locia','project','task')),
    project_id INTEGER,
    task_id INTEGER,
    user_id INTEGER,
    action TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT,
    meta_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    user_id INTEGER,
    filename TEXT NOT NULL,
    path TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS deadline_shift_reasons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS task_deadline_shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    shifted_by INTEGER,
    date_old TEXT,
    date_new TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    reason_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'approved' CHECK (status IN ('pending','approved','rejected')),
    reviewed_by INTEGER,
    reviewed_at TEXT,
    review_comment TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (shifted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS time_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'manual_week' CHECK (mode IN ('manual_week','distribute_day','repeat_day','task_quick')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved','locked')),
    total_minutes INTEGER NOT NULL DEFAULT 0,
    comment TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS time_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER,
    user_id INTEGER NOT NULL,
    project_id INTEGER,
    task_id INTEGER,
    work_date TEXT NOT NULL,
    minutes INTEGER NOT NULL,
    category TEXT NOT NULL DEFAULT 'task' CHECK (category IN ('task','meeting','admin','learning','vacation','sick_leave','business_trip','day_off','idle','absence','overtime','other')),
    phase TEXT NOT NULL DEFAULT 'execution' CHECK (phase IN ('execution','review','correction','repeat_review','management','other')),
    comment TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved','locked')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES time_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS employee_vacations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    substitute_user_id INTEGER NOT NULL,
    note TEXT,
    created_by INTEGER NOT NULL,
    cancelled_at TEXT,
    cancelled_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (substitute_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_employee_vacations_user_dates
    ON employee_vacations(user_id, date_from, date_to);
CREATE INDEX IF NOT EXISTS idx_employee_vacations_substitute_dates
    ON employee_vacations(substitute_user_id, date_from, date_to);

CREATE TABLE IF NOT EXISTS time_month_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','gip_approved','returned','director_approved','locked')),
    submitted_at TEXT,
    submitted_by INTEGER,
    gip_approved_at TEXT,
    gip_approved_by INTEGER,
    department_approved_at TEXT,
    department_approved_by INTEGER,
    director_approved_at TEXT,
    director_approved_by INTEGER,
    returned_at TEXT,
    returned_by INTEGER,
    return_comment TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, period_start),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (gip_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (director_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    task_id INTEGER,
    type TEXT NOT NULL,
    body TEXT NOT NULL,
    target_url TEXT,
    read_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notification_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    entity_id INTEGER,
    recipient_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    dedupe_key TEXT NOT NULL UNIQUE,
    last_error TEXT,
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    endpoint_hash TEXT NOT NULL UNIQUE,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth_token TEXT NOT NULL,
    content_encoding TEXT NOT NULL DEFAULT 'aes128gcm',
    user_agent TEXT,
    device_label TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_seen_at TEXT,
    last_success_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_push_subscription_user_active ON push_subscriptions(user_id, is_active);

CREATE TABLE IF NOT EXISTS push_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    entity_id INTEGER,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    target_url TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    dedupe_key TEXT NOT NULL UNIQUE,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_push_outbox_pending ON push_outbox(status, available_at, attempts, id);
CREATE INDEX IF NOT EXISTS idx_push_outbox_user ON push_outbox(user_id, created_at);

CREATE TABLE IF NOT EXISTS deadline_reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reminder_date TEXT NOT NULL,
    kind TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (task_id, user_id, reminder_date, kind)
);

CREATE TABLE IF NOT EXISTS notification_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mail_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS dictionary_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER,
    scope_project_id INTEGER NOT NULL DEFAULT 0,
    kind TEXT NOT NULL,
    value TEXT NOT NULL,
    label TEXT,
    discipline TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE (scope_project_id, kind, value)
);

INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
VALUES
    (NULL, 0, 'section', 'ГП', 'Генеральный план', 'ГП', 1, 10),
    (NULL, 0, 'section', 'АР', 'Архитектурные решения', 'АР', 1, 20),
    (NULL, 0, 'section', 'КР', 'Конструктивные решения', 'КР', 1, 30),
    (NULL, 0, 'section', 'ОВ', 'Отопление и вентиляция', 'ОВ', 1, 40),
    (NULL, 0, 'section', 'ВК', 'Водоснабжение и канализация', 'ВК', 1, 50),
    (NULL, 0, 'section', 'НВК', 'Наружные сети водоснабжения и канализации', 'НВК', 1, 60),
    (NULL, 0, 'section', 'ЭОМ', 'Электрооборудование и освещение', 'ЭОМ', 1, 70),
    (NULL, 0, 'section', 'КС-СКС', 'Комплексные слаботочные системы', 'КС-СКС', 1, 80),
    (NULL, 0, 'section', 'СС', 'Системы связи', 'СС', 1, 90),
    (NULL, 0, 'section', 'ТХ', 'Технологические решения', 'ТХ', 1, 100),
    (NULL, 0, 'section', 'АТХ', 'Автоматизация технологических процессов', 'АТХ', 1, 110),
    (NULL, 0, 'section', 'АОВ', 'Автоматизация отопления и вентиляции', 'АОВ', 1, 120),
    (NULL, 0, 'section', 'АСУ', 'Автоматизированные системы управления', 'АСУ', 1, 125),
    (NULL, 0, 'section', 'ПБ', 'Пожарная безопасность', 'ПБ', 1, 130),
    (NULL, 0, 'section', 'ТИМ', 'Технологии информационного моделирования', 'ТИМ', 1, 140)
ON CONFLICT(scope_project_id, kind, value) DO UPDATE SET
    label = excluded.label,
    discipline = excluded.discipline,
    active = 1,
    sort_order = excluded.sort_order,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
VALUES
    (NULL, 0, 'project_stage', 'ОТР', 'Основные технические решения', NULL, 1, 10),
    (NULL, 0, 'project_stage', 'ПД', 'Проектная документация', NULL, 1, 20),
    (NULL, 0, 'project_stage', 'РД', 'Рабочая документация', NULL, 1, 30),
    (NULL, 0, 'project_activity', 'ТИМ', 'ТИМ-координация', NULL, 1, 10),
    (NULL, 0, 'project_activity', 'УПРАВЛЕНИЕ', 'Управление проектом', NULL, 1, 20),
    (NULL, 0, 'section_pp87', 'ПЗ', 'Пояснительная записка', NULL, 1, 10),
    (NULL, 0, 'section_pp87', 'СПОЗУ', 'Схема планировочной организации земельного участка', NULL, 1, 20),
    (NULL, 0, 'section_pp87', 'АР', 'Архитектурные решения', NULL, 1, 30),
    (NULL, 0, 'section_pp87', 'КР', 'Конструктивные и объемно-планировочные решения', NULL, 1, 40),
    (NULL, 0, 'section_pp87', 'ИОС', 'Сведения об инженерном оборудовании и сетях', NULL, 1, 50),
    (NULL, 0, 'section_pp87', 'ПОС', 'Проект организации строительства', NULL, 1, 60),
    (NULL, 0, 'section_pp87', 'ООС', 'Перечень мероприятий по охране окружающей среды', NULL, 1, 70),
    (NULL, 0, 'section_pp87', 'ПБ', 'Мероприятия по обеспечению пожарной безопасности', NULL, 1, 80),
    (NULL, 0, 'section_pp87', 'ОДИ', 'Мероприятия по обеспечению доступа инвалидов', NULL, 1, 90),
    (NULL, 0, 'section_pp87', 'ЭЭ', 'Требования к энергетической эффективности', NULL, 1, 100),
    (NULL, 0, 'section_rd', 'ГП', 'Генеральный план', NULL, 1, 10),
    (NULL, 0, 'section_rd', 'АР', 'Архитектурные решения', NULL, 1, 20),
    (NULL, 0, 'section_rd', 'КР', 'Конструктивные решения', NULL, 1, 30),
    (NULL, 0, 'section_rd', 'ОВ', 'Отопление и вентиляция', NULL, 1, 40),
    (NULL, 0, 'section_rd', 'ВК', 'Водоснабжение и канализация', NULL, 1, 50),
    (NULL, 0, 'section_rd', 'ЭОМ', 'Электрооборудование и освещение', NULL, 1, 60),
    (NULL, 0, 'section_rd', 'КС-СКС', 'Комплексные слаботочные системы', NULL, 1, 70),
    (NULL, 0, 'section_rd', 'АТХ', 'Автоматизация технологических процессов', NULL, 1, 80)
ON CONFLICT(scope_project_id, kind, value) DO UPDATE SET
    label = excluded.label,
    active = 1,
    sort_order = excluded.sort_order,
    updated_at = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS weekly_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id INTEGER NOT NULL,
    recipient TEXT,
    period_type TEXT NOT NULL DEFAULT 'week',
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    portfolio_status TEXT NOT NULL DEFAULT 'yellow',
    previous_status TEXT,
    title TEXT NOT NULL,
    summary TEXT,
    finances_text TEXT,
    conclusions_text TEXT,
    notes_text TEXT,
    state TEXT NOT NULL DEFAULT 'draft',
    locked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS weekly_report_projects (
    report_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (report_id, project_id),
    FOREIGN KEY (report_id) REFERENCES weekly_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS weekly_report_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL,
    section_key TEXT NOT NULL,
    project_id INTEGER,
    source_type TEXT NOT NULL DEFAULT 'manual',
    source_id INTEGER,
    item_title TEXT NOT NULL,
    plan_text TEXT,
    fact_text TEXT,
    deviation_text TEXT,
    comment_text TEXT,
    severity TEXT NOT NULL DEFAULT 'info',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES weekly_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS cfo_rates (
    dept_code TEXT PRIMARY KEY,
    hourly_rate REAL NOT NULL DEFAULT 0,
    label TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS motivation_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value REAL NOT NULL DEFAULT 0,
    label TEXT NOT NULL,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS motivation_grade_coefficients (
    grade TEXT PRIMARY KEY,
    coefficient REAL NOT NULL DEFAULT 1,
    label TEXT,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_motivation_settings (
    project_id INTEGER PRIMARY KEY,
    project_fund REAL NOT NULL DEFAULT 0,
    budget_hours REAL,
    is_paid INTEGER NOT NULL DEFAULT 0,
    paid_at TEXT,
    comment TEXT,
    updated_by INTEGER,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS motivation_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'draft' CHECK (state IN ('draft','locked')),
    settings_snapshot TEXT,
    totals_json TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by INTEGER,
    locked_at TEXT,
    UNIQUE(period_start, state),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS motivation_run_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    department TEXT,
    grade TEXT,
    grade_coefficient REAL NOT NULL DEFAULT 1,
    employment_ratio REAL NOT NULL DEFAULT 1,
    locked_hours REAL NOT NULL DEFAULT 0,
    expected_hours REAL NOT NULL DEFAULT 0,
    kpi_score REAL NOT NULL DEFAULT 0,
    kpi_amount REAL NOT NULL DEFAULT 0,
    project_bonus_amount REAL NOT NULL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0,
    basis_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(run_id, user_id),
    FOREIGN KEY (run_id) REFERENCES motivation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO motivation_settings (setting_key, setting_value, label) VALUES
    ('monthly_kpi_max', 60000, 'Максимальная месячная KPI-премия, ₽'),
    ('weight_timesheet_locked', 0.25, 'Вес KPI: закрытый табель'),
    ('weight_timesheet_completeness', 0.25, 'Вес KPI: полнота списаний'),
    ('weight_deadline', 0.20, 'Вес KPI: сроки'),
    ('weight_rework', 0.15, 'Вес KPI: возвраты'),
    ('weight_plan_fact', 0.15, 'Вес KPI: план/факт');

INSERT OR IGNORE INTO motivation_grade_coefficients (grade, coefficient, label) VALUES
    ('N-12', 1.0, 'N-12'), ('N-11', 1.0, 'N-11'), ('N-10', 1.0, 'N-10'), ('N-9', 1.0, 'N-9'), ('N-8', 1.0, 'N-8'),
    ('N-7', 1.2, 'N-7'), ('N-6', 1.4, 'N-6'), ('N-5', 1.6, 'N-5'), ('N-4', 1.8, 'N-4'),
    ('N-3', 0.0, 'N-3'), ('H', 0.0, 'H'), ('Н', 0.0, 'Н'), ('0', 0.0, 'Собственник');

CREATE TABLE IF NOT EXISTS performance_review_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    is_builtin INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performance_review_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    question_key TEXT NOT NULL,
    section_key TEXT,
    section_label TEXT,
    label TEXT NOT NULL,
    question_type TEXT NOT NULL DEFAULT 'textarea' CHECK (question_type IN ('text','textarea','rating_1_5','yes_no')),
    answer_scope TEXT NOT NULL DEFAULT 'both' CHECK (answer_scope IN ('self','manager','hr','both')),
    is_required INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(template_id, question_key),
    FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS performance_review_cycles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    cycle_kind TEXT NOT NULL DEFAULT 'annual' CHECK (cycle_kind IN ('annual','test')),
    review_year INTEGER,
    period_start TEXT,
    period_end TEXT,
    response_deadline TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','closed','cancelled')),
    questionnaire_snapshot_json TEXT,
    competency_snapshot_json TEXT,
    audience_opened_at TEXT,
    audience_opened_by INTEGER,
    created_by INTEGER,
    closed_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TEXT,
    FOREIGN KEY (template_id) REFERENCES performance_review_templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_pr_cycles_year_kind ON performance_review_cycles(review_year, cycle_kind);

CREATE TABLE IF NOT EXISTS performance_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cycle_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    manager_id INTEGER,
    position_title_snapshot TEXT,
    position_grade_snapshot TEXT,
    competency_position_index INTEGER,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','self_review','manager_review','hr_review','closed','cancelled')),
    launch_batch_no INTEGER,
    launched_at TEXT,
    self_submitted_at TEXT,
    self_questionnaire_submitted_at TEXT,
    self_matrix_submitted_at TEXT,
    manager_submitted_at TEXT,
    manager_matrix_submitted_at TEXT,
    hr_closed_at TEXT,
    meeting_completed_at TEXT,
    meeting_completed_by INTEGER,
    meeting_notes TEXT,
    next_year_actions TEXT,
    hr_closed_by INTEGER,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(cycle_id, user_id),
    FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (hr_closed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_pr_reviews_cycle_batch ON performance_reviews(cycle_id, launch_batch_no, launched_at);

CREATE TABLE IF NOT EXISTS performance_review_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    review_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    answer_scope TEXT NOT NULL CHECK (answer_scope IN ('self','manager','hr')),
    answer_value TEXT,
    question_label_snapshot TEXT NOT NULL,
    question_type_snapshot TEXT NOT NULL,
    answered_by INTEGER,
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, question_id, answer_scope),
    FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES performance_review_questions(id) ON DELETE RESTRICT,
    FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performance_review_competency_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    review_id INTEGER NOT NULL,
    competency_key TEXT NOT NULL,
    answer_scope TEXT NOT NULL CHECK (answer_scope IN ('self','manager')),
    score INTEGER CHECK (score BETWEEN 1 AND 5),
    comment TEXT,
    competency_name_snapshot TEXT NOT NULL,
    competency_description_snapshot TEXT,
    level_1_snapshot TEXT,
    level_2_snapshot TEXT,
    level_3_snapshot TEXT,
    level_4_snapshot TEXT,
    level_5_snapshot TEXT,
    required_level_snapshot INTEGER,
    answered_by INTEGER,
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, competency_key, answer_scope),
    FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performance_review_cycle_notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cycle_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    notification_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(cycle_id, user_id),
    FOREIGN KEY (cycle_id) REFERENCES performance_review_cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performance_review_stage_notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    review_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    stage TEXT NOT NULL,
    notification_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, user_id, stage),
    FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_department ON users(department);
CREATE INDEX IF NOT EXISTS idx_projects_stage ON projects(stage);
CREATE INDEX IF NOT EXISTS idx_projects_status ON projects(status);
CREATE INDEX IF NOT EXISTS idx_projects_kind_status ON projects(kind, status);
CREATE INDEX IF NOT EXISTS idx_projects_gip ON projects(gip_user_id);
CREATE INDEX IF NOT EXISTS idx_projects_rp ON projects(rp_user_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_tasks_project_msp_uid ON tasks(project_id, msp_task_uid);
CREATE INDEX IF NOT EXISTS idx_tasks_project_msp_task_id ON tasks(project_id, msp_task_id);
CREATE INDEX IF NOT EXISTS idx_personal_notes_author_status ON personal_notes(author_id, status, pinned, updated_at);
CREATE INDEX IF NOT EXISTS idx_personal_notes_project ON personal_notes(project_id);
CREATE INDEX IF NOT EXISTS idx_tasks_status_end ON tasks(status, date_end);
CREATE INDEX IF NOT EXISTS idx_tasks_assignee_status ON tasks(assignee_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_author_status ON tasks(author_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_reviewer_status ON tasks(reviewer_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_parent ON tasks(parent_id);
CREATE INDEX IF NOT EXISTS idx_tasks_discipline ON tasks(discipline);
CREATE INDEX IF NOT EXISTS idx_tasks_section ON tasks(section);
CREATE INDEX IF NOT EXISTS idx_tasks_project_section ON tasks(project_section_id);
CREATE INDEX IF NOT EXISTS idx_tasks_approval_stage ON tasks(approval_stage);
CREATE INDEX IF NOT EXISTS idx_tasks_pp_code ON tasks(pp_code_id);
CREATE INDEX IF NOT EXISTS idx_tasks_btp_code ON tasks(btp_code_id);
CREATE INDEX IF NOT EXISTS idx_tasks_cost_group_code ON tasks(cost_group_code);
CREATE INDEX IF NOT EXISTS idx_task_participants_task ON task_participants(task_id);
CREATE INDEX IF NOT EXISTS idx_task_participants_user_role ON task_participants(user_id, role);
CREATE INDEX IF NOT EXISTS idx_task_atlas_refs_task ON task_atlas_refs(task_id);
CREATE INDEX IF NOT EXISTS idx_task_atlas_refs_project ON task_atlas_refs(project_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_task_issuances_task_number ON task_issuances(task_id, issue_number);
CREATE INDEX IF NOT EXISTS idx_task_issuances_task_status ON task_issuances(task_id, status, issued_at);
CREATE INDEX IF NOT EXISTS idx_document_revisions_project ON document_revisions(project_id, revision_no);
CREATE INDEX IF NOT EXISTS idx_document_revisions_issuance ON document_revisions(issuance_id);
CREATE INDEX IF NOT EXISTS idx_task_approvals_task_created ON task_approvals(task_id, created_at);
CREATE INDEX IF NOT EXISTS idx_task_approvals_stage_decision ON task_approvals(stage, decision);
CREATE INDEX IF NOT EXISTS idx_schedule_project_date ON project_schedule(project_id, rd_date_plan);
CREATE INDEX IF NOT EXISTS idx_schedule_status ON project_schedule(rd_readiness, rd_date_plan);
CREATE UNIQUE INDEX IF NOT EXISTS uq_schedule_project_task ON project_schedule(project_id, task_id);
CREATE INDEX IF NOT EXISTS idx_schedule_task ON project_schedule(task_id);
CREATE INDEX IF NOT EXISTS idx_sections_project ON project_sections(project_id);
CREATE INDEX IF NOT EXISTS idx_sections_task ON project_sections(task_id);
CREATE INDEX IF NOT EXISTS idx_sections_code ON project_sections(code);
CREATE INDEX IF NOT EXISTS idx_sections_sbc_item ON project_sections(sbc_item_id);
CREATE INDEX IF NOT EXISTS idx_issues_project_status ON project_issues(project_id, status);
CREATE INDEX IF NOT EXISTS idx_issues_blocking_task ON project_issues(blocking_task_id);
CREATE INDEX IF NOT EXISTS idx_issues_status ON project_issues(status);
CREATE INDEX IF NOT EXISTS idx_issues_assignee_status_project_date ON project_issues(assignee_id, status, project_id, date_raised);
CREATE INDEX IF NOT EXISTS idx_data_registry_project_status ON project_data_registry(project_id, status);
CREATE INDEX IF NOT EXISTS idx_data_registry_status_date ON project_data_registry(status, date_received_plan);
CREATE UNIQUE INDEX IF NOT EXISTS uq_counterparties_identity ON counterparties(company, role, representative);
CREATE INDEX IF NOT EXISTS idx_counterparties_company ON counterparties(company);
CREATE INDEX IF NOT EXISTS idx_counterparties_role ON counterparties(role);
CREATE INDEX IF NOT EXISTS idx_exchange_template_sets_active ON exchange_template_sets(is_active, sort_order, name);
CREATE UNIQUE INDEX IF NOT EXISTS uq_exchange_template_items_code ON exchange_template_items(template_set_id, item_code);
CREATE INDEX IF NOT EXISTS idx_exchange_template_items_set_order ON exchange_template_items(template_set_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_exchange_project_status ON project_task_exchange(project_id, status);
CREATE INDEX IF NOT EXISTS idx_exchange_deadline ON project_task_exchange(deadline);
CREATE UNIQUE INDEX IF NOT EXISTS uq_exchange_project_task ON project_task_exchange(project_id, task_id);
CREATE INDEX IF NOT EXISTS idx_exchange_task ON project_task_exchange(task_id);
CREATE INDEX IF NOT EXISTS idx_exchange_template_item ON project_task_exchange(template_item_id);
CREATE INDEX IF NOT EXISTS idx_exchange_direction ON project_task_exchange(direction);
CREATE INDEX IF NOT EXISTS idx_exchange_from_user ON project_task_exchange(from_user_id);
CREATE INDEX IF NOT EXISTS idx_exchange_to_user ON project_task_exchange(to_user_id);
CREATE INDEX IF NOT EXISTS idx_exchange_from_counterparty ON project_task_exchange(from_counterparty_id);
CREATE INDEX IF NOT EXISTS idx_exchange_to_counterparty ON project_task_exchange(to_counterparty_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sbc_reference_hash ON sbc_items(reference_hash);
CREATE INDEX IF NOT EXISTS idx_sbc_collection ON sbc_items(collection_code, edition);
CREATE INDEX IF NOT EXISTS idx_sbc_table_item ON sbc_items(table_code, item_code);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sbc_indices_period ON sbc_indices(period_key);
CREATE INDEX IF NOT EXISTS idx_sbc_indices_active ON sbc_indices(is_active, period_key);
CREATE INDEX IF NOT EXISTS idx_cost_plan_project_num ON project_cost_plan(project_id, num);
CREATE INDEX IF NOT EXISTS idx_cost_plan_section ON project_cost_plan(project_id, section_code);
CREATE INDEX IF NOT EXISTS idx_cost_plan_sbc_item ON project_cost_plan(sbc_item_id);
CREATE INDEX IF NOT EXISTS idx_cost_plan_labor_approval ON project_cost_plan(labor_approval_status, labor_approved_at);
CREATE INDEX IF NOT EXISTS idx_project_labor_project_status ON project_labor_estimates(project_id, status);
CREATE INDEX IF NOT EXISTS idx_calculator_portfolio_status_finish ON calculator_portfolio_entries(status, finish_date);
CREATE INDEX IF NOT EXISTS idx_calculator_portfolio_creator ON calculator_portfolio_entries(created_by);
CREATE INDEX IF NOT EXISTS idx_project_labor_section ON project_labor_estimates(section_id);
CREATE INDEX IF NOT EXISTS idx_project_labor_task ON project_labor_estimates(task_id);
CREATE INDEX IF NOT EXISTS idx_project_labor_executor ON project_labor_estimates(executor_id);
CREATE INDEX IF NOT EXISTS idx_project_labor_department ON project_labor_estimates(department_code);
CREATE INDEX IF NOT EXISTS idx_project_labor_sbc_item ON project_labor_estimates(sbc_item_id);
CREATE INDEX IF NOT EXISTS idx_project_labor_sbc_index ON project_labor_estimates(sbc_index_id);
CREATE INDEX IF NOT EXISTS idx_labor_allocations_estimate ON project_labor_estimate_allocations(labor_estimate_id);
CREATE INDEX IF NOT EXISTS idx_labor_allocations_user ON project_labor_estimate_allocations(user_id);
CREATE INDEX IF NOT EXISTS idx_cost_estimates_status ON cost_estimates(status);
CREATE INDEX IF NOT EXISTS idx_cost_estimates_project ON cost_estimates(project_id);
CREATE INDEX IF NOT EXISTS idx_cost_estimates_updated ON cost_estimates(updated_at);
CREATE INDEX IF NOT EXISTS idx_cost_estimate_items_num ON cost_estimate_items(estimate_id, num);
CREATE INDEX IF NOT EXISTS idx_cost_estimate_items_section ON cost_estimate_items(estimate_id, section_code);
CREATE INDEX IF NOT EXISTS idx_cost_estimate_items_sbc ON cost_estimate_items(sbc_item_id);
CREATE INDEX IF NOT EXISTS idx_cost_estimate_items_labor_approval ON cost_estimate_items(labor_approval_status, labor_approved_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_custom_field_scope_name ON custom_fields(project_id, name);
CREATE INDEX IF NOT EXISTS idx_custom_fields_scope_sort ON custom_fields(project_id, sort_order);
CREATE UNIQUE INDEX IF NOT EXISTS uq_tags_project_slug ON tags(project_id, slug);
CREATE INDEX IF NOT EXISTS idx_tags_project ON tags(project_id);
CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name);
CREATE INDEX IF NOT EXISTS idx_task_tags_tag ON task_tags(tag_id);
CREATE INDEX IF NOT EXISTS idx_comments_task_created ON comments(task_id, created_at);
CREATE INDEX IF NOT EXISTS idx_task_logs_task_created ON task_logs(task_id, created_at);
CREATE INDEX IF NOT EXISTS idx_task_logs_task_field_newval_id ON task_logs(task_id, field, new_val, id);
CREATE INDEX IF NOT EXISTS idx_activity_scope_created ON activity_logs(scope, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_project_created ON activity_logs(project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_task_created ON activity_logs(task_id, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_action_created ON activity_logs(action, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_user_created ON activity_logs(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_attachments_task ON attachments(task_id);
CREATE INDEX IF NOT EXISTS idx_deadline_shifts_task ON task_deadline_shifts(task_id);
CREATE INDEX IF NOT EXISTS idx_deadline_shifts_reason ON task_deadline_shifts(reason_code);
CREATE INDEX IF NOT EXISTS idx_deadline_shifts_status ON task_deadline_shifts(task_id, status);
CREATE INDEX IF NOT EXISTS idx_time_batches_user_period ON time_batches(user_id, period_start, period_end);
CREATE INDEX IF NOT EXISTS idx_time_batches_status ON time_batches(status, created_at);
CREATE INDEX IF NOT EXISTS idx_time_entries_user_date ON time_entries(user_id, work_date);
CREATE INDEX IF NOT EXISTS idx_time_entries_task_date ON time_entries(task_id, work_date);
CREATE INDEX IF NOT EXISTS idx_time_entries_project_date ON time_entries(project_id, work_date);
CREATE INDEX IF NOT EXISTS idx_time_entries_batch ON time_entries(batch_id);
CREATE INDEX IF NOT EXISTS idx_time_month_reviews_status ON time_month_reviews(status, period_start);
CREATE INDEX IF NOT EXISTS idx_time_month_reviews_period ON time_month_reviews(period_start, period_end);
CREATE INDEX IF NOT EXISTS idx_motivation_runs_period ON motivation_runs(period_start, period_end);
CREATE UNIQUE INDEX IF NOT EXISTS uq_motivation_run_period_state ON motivation_runs(period_start, state);
CREATE INDEX IF NOT EXISTS idx_motivation_rows_user ON motivation_run_rows(user_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_motivation_run_user ON motivation_run_rows(run_id, user_id);
CREATE INDEX IF NOT EXISTS idx_pr_questions_template ON performance_review_questions(template_id, sort_order);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pr_question_key ON performance_review_questions(template_id, question_key);
CREATE INDEX IF NOT EXISTS idx_pr_cycles_status ON performance_review_cycles(status, period_start);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pr_review_user_cycle ON performance_reviews(cycle_id, user_id);
CREATE INDEX IF NOT EXISTS idx_pr_reviews_user_status ON performance_reviews(user_id, status);
CREATE INDEX IF NOT EXISTS idx_pr_reviews_manager_status ON performance_reviews(manager_id, status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pr_answer_scope ON performance_review_answers(review_id, question_id, answer_scope);
CREATE INDEX IF NOT EXISTS idx_pr_answers_review ON performance_review_answers(review_id, answer_scope);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, read_at);
CREATE INDEX IF NOT EXISTS idx_notification_outbox_status ON notification_outbox(status, attempts, id);
CREATE INDEX IF NOT EXISTS idx_deadline_reminders_user_date ON deadline_reminders(user_id, reminder_date);
CREATE INDEX IF NOT EXISTS idx_deadline_reminders_task_date ON deadline_reminders(task_id, reminder_date);
CREATE INDEX IF NOT EXISTS idx_weekly_reports_period ON weekly_reports(period_type, date_from, date_to);
CREATE INDEX IF NOT EXISTS idx_weekly_reports_state ON weekly_reports(state, date_to);
CREATE INDEX IF NOT EXISTS idx_weekly_reports_author ON weekly_reports(author_id, created_at);
CREATE INDEX IF NOT EXISTS idx_weekly_report_projects_project ON weekly_report_projects(project_id);
CREATE INDEX IF NOT EXISTS idx_weekly_report_items_report_section ON weekly_report_items(report_id, section_key, sort_order);
CREATE INDEX IF NOT EXISTS idx_weekly_report_items_project ON weekly_report_items(project_id);
CREATE INDEX IF NOT EXISTS idx_weekly_report_items_source ON weekly_report_items(source_type, source_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_dictionary_scope_kind_value ON dictionary_items(scope_project_id, kind, value);
CREATE INDEX IF NOT EXISTS idx_dictionary_kind_active ON dictionary_items(kind, active);
CREATE INDEX IF NOT EXISTS idx_dictionary_project_kind ON dictionary_items(scope_project_id, kind);
CREATE INDEX IF NOT EXISTS idx_project_contacts_project ON project_contacts(project_id);
CREATE INDEX IF NOT EXISTS idx_project_contacts_org ON project_contacts(organization);
CREATE INDEX IF NOT EXISTS idx_project_members_project_active ON project_members(project_id, active);
CREATE INDEX IF NOT EXISTS idx_project_members_user_active ON project_members(user_id, active);
CREATE INDEX IF NOT EXISTS idx_project_model_links_project ON project_model_links(project_id, is_primary, created_at);
CREATE INDEX IF NOT EXISTS idx_project_model_links_kind ON project_model_links(kind);
CREATE INDEX IF NOT EXISTS idx_public_links_project ON public_links(project_id);
CREATE INDEX IF NOT EXISTS idx_public_links_task ON public_links(task_id);
CREATE INDEX IF NOT EXISTS idx_public_links_model_link ON public_links(model_link_id);
CREATE INDEX IF NOT EXISTS idx_project_pp_project_active ON project_pp_codes(project_id, active, sort_order);
CREATE INDEX IF NOT EXISTS idx_project_btp_project_active ON project_btp_codes(project_id, active, sort_order);
CREATE INDEX IF NOT EXISTS idx_project_btp_pp ON project_btp_codes(pp_code_id);
CREATE INDEX IF NOT EXISTS idx_project_uts_project_date ON project_uts_facts(project_id, fact_date);
CREATE INDEX IF NOT EXISTS idx_project_uts_pp ON project_uts_facts(pp_code_id);
CREATE INDEX IF NOT EXISTS idx_project_uts_btp ON project_uts_facts(btp_code_id);
CREATE INDEX IF NOT EXISTS idx_users_manager ON users(manager_id);
CREATE INDEX IF NOT EXISTS idx_users_position ON users(position_id);

-- Фаза 1 ФОТ: справочники и настройка (SQLite-паритет миграции 050)
CREATE TABLE IF NOT EXISTS legal_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    full_name TEXT,
    inn TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS writeoff_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'nonproject',
    maps_category TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO writeoff_articles (code, name, kind, maps_category, sort_order) VALUES
    ('00', 'Проектная задача', 'project', 'task', 10),
    ('02', 'Отчётность отсутствует', 'nonproject', NULL, 20),
    ('92', 'Обучение внешнее', 'nonproject', 'learning', 30),
    ('93', 'Очередной отпуск', 'nonproject', 'vacation', 40),
    ('94', 'Болезнь', 'nonproject', 'sick_leave', 50),
    ('98', 'Простои по вине рук-ва', 'nonproject', 'idle', 60),
    ('99', 'Отпуск за свой счёт', 'nonproject', 'day_off', 70);

CREATE TABLE IF NOT EXISTS employee_legal_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    legal_entity_id INTEGER NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    daily_hours REAL NOT NULL DEFAULT 0,
    position TEXT,
    cost_group TEXT,
    base_oklad REAL NOT NULL DEFAULT 0,
    base_nadbavka REAL NOT NULL DEFAULT 0,
    premium REAL NOT NULL DEFAULT 0,
    project_nadbavka REAL NOT NULL DEFAULT 0,
    is_piecework INTEGER NOT NULL DEFAULT 0,
    rate_override REAL,
    is_active INTEGER NOT NULL DEFAULT 1,
    updated_by INTEGER,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, legal_entity_id)
);
CREATE INDEX IF NOT EXISTS idx_ele_entity ON employee_legal_entities(legal_entity_id);

-- База знаний: папки, безопасный черновик, публикации и история версий.
CREATE TABLE IF NOT EXISTS knowledge_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_key TEXT,
    parent_id INTEGER,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_by INTEGER,
    updated_by INTEGER,
    archived_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES knowledge_folders(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS knowledge_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_key TEXT,
    folder_id INTEGER,
    title TEXT NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    body_html TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    is_pinned INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 100,
    current_version INTEGER NOT NULL DEFAULT 0,
    draft_folder_id INTEGER,
    draft_title TEXT NOT NULL,
    draft_summary TEXT NOT NULL DEFAULT '',
    draft_body_html TEXT NOT NULL,
    draft_is_pinned INTEGER NOT NULL DEFAULT 0,
    draft_updated_at TEXT,
    published_at TEXT,
    created_by INTEGER,
    updated_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    FOREIGN KEY (draft_folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS knowledge_document_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    version_no INTEGER NOT NULL,
    folder_id INTEGER,
    title TEXT NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    body_html TEXT NOT NULL,
    is_pinned INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (document_id, version_no),
    FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES knowledge_folders(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_knowledge_folders_parent_order ON knowledge_folders(parent_id, sort_order, name);
CREATE UNIQUE INDEX IF NOT EXISTS uq_knowledge_folders_source_key ON knowledge_folders(source_key);
CREATE INDEX IF NOT EXISTS idx_knowledge_documents_folder_status ON knowledge_documents(folder_id, status, is_pinned, sort_order);
CREATE INDEX IF NOT EXISTS idx_knowledge_documents_updated ON knowledge_documents(updated_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_knowledge_documents_source_key ON knowledge_documents(source_key);
CREATE INDEX IF NOT EXISTS idx_knowledge_revisions_document ON knowledge_document_revisions(document_id, version_no);

CREATE TABLE IF NOT EXISTS project_payment_schedule (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    payment_name TEXT NOT NULL,
    planned_date TEXT NOT NULL,
    planned_amount REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'planned',
    invoice_date TEXT,
    actual_date TEXT,
    actual_amount REAL NOT NULL DEFAULT 0,
    comment TEXT,
    sort_order INTEGER NOT NULL DEFAULT 100,
    created_by INTEGER,
    updated_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_schedule_project_date ON project_payment_schedule(project_id, planned_date, status);
CREATE INDEX IF NOT EXISTS idx_payment_schedule_actual_date ON project_payment_schedule(actual_date, status);

CREATE TABLE IF NOT EXISTS revit_activation_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_revit_activation_user_expiry ON revit_activation_codes(user_id, expires_at);

CREATE TABLE IF NOT EXISTS revit_api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    device_name TEXT NOT NULL DEFAULT '',
    plugin_version TEXT NOT NULL DEFAULT '',
    last_used_at TEXT,
    revoked_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_revit_token_user_active ON revit_api_tokens(user_id, revoked_at);

CREATE TABLE IF NOT EXISTS project_model_series (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    discipline TEXT NOT NULL DEFAULT '',
    next_version_number INTEGER NOT NULL DEFAULT 1,
    current_version_id INTEGER,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (project_id, name),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_model_series_current ON project_model_series(current_version_id);

CREATE TABLE IF NOT EXISTS project_model_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_series_id INTEGER NOT NULL,
    version_number INTEGER NOT NULL,
    file_relative_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    comment TEXT,
    revit_version TEXT NOT NULL DEFAULT '',
    document_title TEXT NOT NULL DEFAULT '',
    document_unique_id TEXT NOT NULL DEFAULT '',
    view_name TEXT NOT NULL DEFAULT '',
    view_unique_id TEXT NOT NULL DEFAULT '',
    ifc_profile TEXT NOT NULL DEFAULT '',
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (model_series_id, version_number),
    FOREIGN KEY (model_series_id) REFERENCES project_model_series(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_model_version_created ON project_model_versions(model_series_id, created_at);

CREATE TABLE IF NOT EXISTS monthly_work_ledger_preferences (
    user_id INTEGER PRIMARY KEY,
    visible_fields TEXT NOT NULL,
    field_order TEXT NOT NULL,
    default_group_by TEXT NOT NULL DEFAULT 'general',
    filters_json TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS revit_upload_sessions (
    id TEXT PRIMARY KEY,
    model_series_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    idempotency_key TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    expected_size INTEGER NOT NULL,
    expected_sha256 TEXT NOT NULL,
    metadata_json TEXT NOT NULL,
    chunk_size INTEGER NOT NULL,
    chunk_count INTEGER NOT NULL,
    received_chunks_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'uploading',
    completed_version_id INTEGER,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, idempotency_key),
    FOREIGN KEY (model_series_id) REFERENCES project_model_series(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (completed_version_id) REFERENCES project_model_versions(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_revit_upload_expiry ON revit_upload_sessions(status, expires_at);

INSERT OR IGNORE INTO knowledge_folders (id, parent_id, name, sort_order) VALUES
    (1, NULL, 'Лемма', 10),
    (2, NULL, 'Атлас', 20),
    (3, 1, 'Начало работы', 10),
    (4, 1, 'Задачи', 20),
    (5, 1, 'Проекты', 30),
    (6, 1, 'Время и отчёты', 40),
    (7, 2, 'Навигация', 10),
    (8, 2, 'Загрузка моделей', 20),
    (9, 2, 'Структура и свойства', 30),
    (10, 2, 'Виды и сечения', 40);

INSERT OR IGNORE INTO knowledge_documents (
    id, folder_id, title, summary, body_html, status, is_pinned, sort_order, current_version,
    draft_folder_id, draft_title, draft_summary, draft_body_html, draft_is_pinned,
    draft_updated_at, published_at
) VALUES
    (1, 1, 'Руководство по Лемме', 'Задачи, проекты, время, согласования и отчёты.',
     '<h2>Начало работы</h2><p>После входа Лемма открывает рабочий экран вашей роли. В разделе «Мой день» собраны ближайшие задачи, проверки и действия, которые требуют внимания сейчас.</p><h2>Задачи</h2><p>Новая задача содержит результат, исполнителя, срок, плановые часы и проверяющего. Исполнитель принимает задачу, ведёт работу и отправляет результат на проверку. Закрытие выполняется через проверку, а не ручной сменой статуса.</p><blockquote>Срок, исполнитель и ожидаемый результат должны быть понятны до начала работы.</blockquote><h2>Проекты</h2><p>Карточка проекта объединяет паспорт, задачи, календарь, историю, команду и модели. Рабочая информация находится сверху, служебные настройки и справочники — в нижних раскрываемых разделах.</p><h2>Время и отчёты</h2><p>Фактическое время списывается на конкретные задачи. Руководитель принимает время в отдельном управленческом контуре, а отчёты показывают личный план, личный факт и общий результат задачи раздельно.</p><h2>Проверка результата</h2><ol><li>Исполнитель отправляет работу на проверку.</li><li>Проверяющий принимает результат или возвращает его с замечанием.</li><li>Все решения и изменения срока сохраняются в журнале задачи.</li></ol>',
     'published', 1, 10, 1, 1, 'Руководство по Лемме', 'Задачи, проекты, время, согласования и отчёты.',
     '<h2>Начало работы</h2><p>После входа Лемма открывает рабочий экран вашей роли. В разделе «Мой день» собраны ближайшие задачи, проверки и действия, которые требуют внимания сейчас.</p><h2>Задачи</h2><p>Новая задача содержит результат, исполнителя, срок, плановые часы и проверяющего. Исполнитель принимает задачу, ведёт работу и отправляет результат на проверку. Закрытие выполняется через проверку, а не ручной сменой статуса.</p><blockquote>Срок, исполнитель и ожидаемый результат должны быть понятны до начала работы.</blockquote><h2>Проекты</h2><p>Карточка проекта объединяет паспорт, задачи, календарь, историю, команду и модели. Рабочая информация находится сверху, служебные настройки и справочники — в нижних раскрываемых разделах.</p><h2>Время и отчёты</h2><p>Фактическое время списывается на конкретные задачи. Руководитель принимает время в отдельном управленческом контуре, а отчёты показывают личный план, личный факт и общий результат задачи раздельно.</p><h2>Проверка результата</h2><ol><li>Исполнитель отправляет работу на проверку.</li><li>Проверяющий принимает результат или возвращает его с замечанием.</li><li>Все решения и изменения срока сохраняются в журнале задачи.</li></ol>',
     1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (2, 2, 'Руководство по Атласу', 'Навигация, модели, структура, свойства, виды и сечения.',
     '<h2>Открытие моделей</h2><p>Атлас открывается из главной страницы, бокового меню или карточки проекта. Проект может содержать IFC, IFCZIP и готовые FRAG-модели. Папка проекта сканируется автоматически.</p><h2>Навигация</h2><p>Мышь или жесты вращают, приближают и перемещают модель. Режим ходьбы работает постоянно. Навигационный куб возвращает стандартные виды сверху, спереди, слева и в изометрию.</p><h2>Структура модели</h2><p>Дерево показывает модели, категории и элементы. Выбор категории подсвечивает все входящие элементы. Выбор отдельного элемента автоматически приближает камеру и открывает его свойства.</p><h2>Виды и сечения</h2><p>Верхняя панель содержит стандартные 2D-виды, плоскость подрезки и подрезку кубиком. Состояние сцены можно сохранить в задачу вместе с камерой и выбранным элементом.</p><h2>Работа на телефоне</h2><p>На мобильном устройстве основные команды остаются в верхней панели, а структура и свойства открываются как компактные рабочие панели. Для точного выбора элемента увеличьте нужный фрагмент модели.</p><blockquote>Если модель обновилась, используйте команду «Обновить папку» в карточке проекта, чтобы сбросить старый FRAG-кеш.</blockquote>',
     'published', 1, 20, 1, 2, 'Руководство по Атласу', 'Навигация, модели, структура, свойства, виды и сечения.',
     '<h2>Открытие моделей</h2><p>Атлас открывается из главной страницы, бокового меню или карточки проекта. Проект может содержать IFC, IFCZIP и готовые FRAG-модели. Папка проекта сканируется автоматически.</p><h2>Навигация</h2><p>Мышь или жесты вращают, приближают и перемещают модель. Режим ходьбы работает постоянно. Навигационный куб возвращает стандартные виды сверху, спереди, слева и в изометрию.</p><h2>Структура модели</h2><p>Дерево показывает модели, категории и элементы. Выбор категории подсвечивает все входящие элементы. Выбор отдельного элемента автоматически приближает камеру и открывает его свойства.</p><h2>Виды и сечения</h2><p>Верхняя панель содержит стандартные 2D-виды, плоскость подрезки и подрезку кубиком. Состояние сцены можно сохранить в задачу вместе с камерой и выбранным элементом.</p><h2>Работа на телефоне</h2><p>На мобильном устройстве основные команды остаются в верхней панели, а структура и свойства открываются как компактные рабочие панели. Для точного выбора элемента увеличьте нужный фрагмент модели.</p><blockquote>Если модель обновилась, используйте команду «Обновить папку» в карточке проекта, чтобы сбросить старый FRAG-кеш.</blockquote>',
     1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT OR IGNORE INTO knowledge_document_revisions (document_id, version_no, folder_id, title, summary, body_html, is_pinned)
SELECT id, 1, folder_id, title, summary, body_html, is_pinned
FROM knowledge_documents
WHERE id IN (1, 2) AND current_version = 1;
