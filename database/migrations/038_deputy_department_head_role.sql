ALTER TABLE users
    MODIFY role ENUM(
        'engineer',
        'chief_specialist',
        'group_lead',
        'department_head',
        'deputy_department_head',
        'gip',
        'project_manager',
        'deputy_director',
        'adjacent_director',
        'director',
        'admin',
        'designer',
        'lead',
        'head'
    ) NOT NULL DEFAULT 'engineer';

INSERT INTO role_access_permissions (role, capability, enabled)
SELECT 'deputy_department_head', c.capability,
    CASE
        WHEN c.capability IN ('locia', 'projects', 'dpr', 'reports', 'integrations') THEN 1
        ELSE 0
    END AS enabled
FROM (
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
