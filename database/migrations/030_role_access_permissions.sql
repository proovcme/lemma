CREATE TABLE IF NOT EXISTS role_access_permissions (
    role VARCHAR(64) NOT NULL,
    capability VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, capability),
    CONSTRAINT fk_role_access_permissions_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_access_permissions (role, capability, enabled)
SELECT r.role, c.capability,
    CASE
        WHEN r.role IN ('director', 'admin') THEN 1
        WHEN r.role IN ('gip', 'project_manager', 'deputy_director', 'adjacent_director')
            AND c.capability IN ('locia', 'projects', 'projects_all', 'projects_create', 'tasks_edit_all', 'dpr', 'reports', 'integrations') THEN 1
        WHEN r.role = 'department_head'
            AND c.capability IN ('locia', 'projects', 'dpr', 'reports', 'integrations') THEN 1
        WHEN r.role IN ('engineer', 'chief_specialist', 'group_lead')
            AND c.capability IN ('locia', 'projects') THEN 1
        ELSE 0
    END AS enabled
FROM (
    SELECT 'engineer' AS role UNION ALL
    SELECT 'chief_specialist' UNION ALL
    SELECT 'group_lead' UNION ALL
    SELECT 'department_head' UNION ALL
    SELECT 'gip' UNION ALL
    SELECT 'project_manager' UNION ALL
    SELECT 'deputy_director' UNION ALL
    SELECT 'adjacent_director' UNION ALL
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
    SELECT 'delete'
) c
WHERE TRUE
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled);
