-- Must precede custom/legacy position keys below. Older standalone databases
-- still use the original role ENUM; waiting until migration 081 makes a
-- cumulative 077+ update fail while assigning generated position keys.
ALTER TABLE users
    MODIFY COLUMN role VARCHAR(100) NOT NULL DEFAULT 'engineer';

ALTER TABLE positions
    ADD COLUMN role_key VARCHAR(100) NULL AFTER id;

ALTER TABLE positions
    ADD COLUMN base_role VARCHAR(50) NOT NULL DEFAULT 'engineer' AFTER role_key;

ALTER TABLE positions
    ADD COLUMN description TEXT NULL AFTER grade;

ALTER TABLE positions
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order;

ALTER TABLE positions
    ADD COLUMN is_protected TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system;

ALTER TABLE positions
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_protected;

ALTER TABLE positions
    ADD UNIQUE KEY uq_positions_role_key (role_key);

CREATE TABLE IF NOT EXISTS position_access_permissions (
    position_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id, capability),
    CONSTRAINT fk_position_access_position
        FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT fk_position_access_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE positions
SET role_key = CONCAT('position_', id)
WHERE role_key IS NULL OR role_key = '';

UPDATE positions SET role_key = 'engineer', base_role = 'engineer', is_system = 1 WHERE title = 'Инженер-проектировщик';
UPDATE positions SET role_key = 'chief_specialist', base_role = 'chief_specialist', is_system = 1 WHERE title = 'Главный специалист';
UPDATE positions SET role_key = 'group_lead', base_role = 'group_lead', is_system = 1 WHERE title = 'Руководитель группы';
UPDATE positions SET role_key = 'department_head', base_role = 'department_head', is_system = 1 WHERE title = 'Начальник отдела';
UPDATE positions SET role_key = 'gip', base_role = 'gip', is_system = 1 WHERE title = 'Главный инженер проекта';
UPDATE positions SET role_key = 'bim_manager', base_role = 'bim_manager', is_system = 1 WHERE title = 'Менеджер ТИМ';
UPDATE positions SET role_key = 'deputy_director', base_role = 'deputy_director', is_system = 1 WHERE title = 'Зам. директора департамента / нач. бюро ГИП';
UPDATE positions SET role_key = 'director', base_role = 'director', is_system = 1, is_protected = 1 WHERE title = 'Директор департамента';

INSERT IGNORE INTO positions (role_key, base_role, title, grade, sort_order, is_system)
VALUES
    ('deputy_department_head', 'deputy_department_head', 'Зам. начальника отдела', 'N-6', 145, 1),
    ('project_manager', 'project_manager', 'Руководитель проекта', 'N-5', 165, 1),
    ('adjacent_director', 'adjacent_director', 'Директор смежного направления', 'N-3', 195, 1),
    ('hr', 'hr', 'Специалист HR', 'N-7', 155, 1);

INSERT IGNORE INTO positions (role_key, base_role, title, grade, description, sort_order, is_system, is_protected, is_active)
SELECT DISTINCT
    CONCAT('legacy_', p.id, '_', u.role),
    u.role,
    CONCAT(p.title, ' — ', CASE u.role
        WHEN 'engineer' THEN 'инженер'
        WHEN 'chief_specialist' THEN 'главный специалист'
        WHEN 'group_lead' THEN 'руководитель группы'
        WHEN 'department_head' THEN 'начальник отдела'
        WHEN 'deputy_department_head' THEN 'зам. начальника отдела'
        WHEN 'gip' THEN 'ГИП'
        WHEN 'bim_manager' THEN 'BIM-менеджер'
        WHEN 'project_manager' THEN 'руководитель проекта'
        WHEN 'deputy_director' THEN 'зам. директора'
        WHEN 'adjacent_director' THEN 'директор смежного направления'
        WHEN 'hr' THEN 'HR'
        WHEN 'director' THEN 'директор'
        ELSE u.role END),
    p.grade,
    'Автоматически создано миграцией для сохранения прежних полномочий.',
    p.sort_order + 1,
    0,
    0,
    1
FROM users u
JOIN positions p ON p.id = u.position_id
WHERE u.role <> 'admin' AND u.role <> p.base_role;

UPDATE users u
JOIN positions old_position ON old_position.id = u.position_id
JOIN positions migrated_position ON migrated_position.role_key = CONCAT('legacy_', old_position.id, '_', u.role)
SET u.position_id = migrated_position.id
WHERE u.role <> 'admin' AND u.role <> old_position.base_role;

UPDATE users u
JOIN positions p ON p.role_key = u.role
SET u.position_id = p.id
WHERE u.position_id IS NULL AND u.role <> 'admin';

UPDATE users u
JOIN positions p ON p.id = u.position_id
SET u.role = p.role_key
WHERE u.role <> 'admin' AND p.role_key IS NOT NULL AND p.role_key <> '';

INSERT INTO position_access_permissions (position_id, capability, enabled)
SELECT p.id, rap.capability, rap.enabled
FROM positions p
JOIN role_access_permissions rap ON rap.role = p.base_role
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled);
