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

UPDATE users
SET role = 'engineer'
WHERE role = 'designer';

UPDATE users
SET role = 'group_lead'
WHERE role = 'lead';

UPDATE users
SET role = 'department_head'
WHERE role = 'head';
