ALTER TABLE users
    MODIFY role ENUM(
        'engineer',
        'chief_specialist',
        'group_lead',
        'department_head',
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
