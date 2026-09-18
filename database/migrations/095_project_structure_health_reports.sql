ALTER TABLE dictionary_items
    MODIFY kind ENUM('volume','section_code','section','project_stage','project_activity','section_pp87','section_rd') NOT NULL;

CREATE TABLE IF NOT EXISTS project_stages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_stages_code (project_id, code),
    INDEX idx_project_stages_order (project_id, active, sort_order),
    CONSTRAINT fk_project_stages_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_sections
    ADD COLUMN stage_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD COLUMN work_kind ENUM('section','activity') NOT NULL DEFAULT 'section' AFTER stage_id,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER work_kind,
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order,
    ADD INDEX idx_project_sections_stage (project_id, stage_id, work_kind, sort_order),
    ADD CONSTRAINT fk_project_sections_stage FOREIGN KEY (stage_id) REFERENCES project_stages(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS project_section_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_section_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assignment_role ENUM('executor','reviewer') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_section_assignment (project_section_id, user_id, assignment_role),
    INDEX idx_project_section_assignments_role (project_section_id, assignment_role, sort_order),
    CONSTRAINT fk_project_section_assignment_section FOREIGN KEY (project_section_id) REFERENCES project_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_section_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_health_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    entity_type ENUM('project','stage','section','task') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    comment_text TEXT NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_health_comment (project_id, date_from, date_to, entity_type, entity_id),
    INDEX idx_project_health_comment_period (project_id, date_from, date_to),
    CONSTRAINT fk_project_health_comment_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_health_comment_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO project_section_assignments (project_section_id, user_id, assignment_role, sort_order)
SELECT id, assignee_id, 'executor', 10 FROM project_sections WHERE assignee_id IS NOT NULL
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

INSERT INTO project_section_assignments (project_section_id, user_id, assignment_role, sort_order)
SELECT id, reviewer_id, 'reviewer', 10 FROM project_sections WHERE reviewer_id IS NOT NULL
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order) VALUES
(NULL,0,'project_stage','ОТР','Основные технические решения',NULL,1,10),
(NULL,0,'project_stage','ПД','Проектная документация',NULL,1,20),
(NULL,0,'project_stage','РД','Рабочая документация',NULL,1,30),
(NULL,0,'project_activity','ТИМ','ТИМ-координация',NULL,1,10),
(NULL,0,'project_activity','УПРАВЛЕНИЕ','Управление проектом',NULL,1,20),
(NULL,0,'section_pp87','ПЗ','Пояснительная записка',NULL,1,10),
(NULL,0,'section_pp87','СПОЗУ','Схема планировочной организации земельного участка',NULL,1,20),
(NULL,0,'section_pp87','АР','Архитектурные решения',NULL,1,30),
(NULL,0,'section_pp87','КР','Конструктивные и объемно-планировочные решения',NULL,1,40),
(NULL,0,'section_pp87','ИОС','Сведения об инженерном оборудовании и сетях',NULL,1,50),
(NULL,0,'section_pp87','ПОС','Проект организации строительства',NULL,1,60),
(NULL,0,'section_pp87','ООС','Перечень мероприятий по охране окружающей среды',NULL,1,70),
(NULL,0,'section_pp87','ПБ','Мероприятия по обеспечению пожарной безопасности',NULL,1,80),
(NULL,0,'section_pp87','ОДИ','Мероприятия по обеспечению доступа инвалидов',NULL,1,90),
(NULL,0,'section_pp87','ЭЭ','Требования к энергетической эффективности',NULL,1,100),
(NULL,0,'section_rd','ГП','Генеральный план',NULL,1,10),
(NULL,0,'section_rd','АР','Архитектурные решения',NULL,1,20),
(NULL,0,'section_rd','КР','Конструктивные решения',NULL,1,30),
(NULL,0,'section_rd','ОВ','Отопление и вентиляция',NULL,1,40),
(NULL,0,'section_rd','ВК','Водоснабжение и канализация',NULL,1,50),
(NULL,0,'section_rd','ЭОМ','Электрооборудование и освещение',NULL,1,60),
(NULL,0,'section_rd','КС-СКС','Комплексные слаботочные системы',NULL,1,70),
(NULL,0,'section_rd','АТХ','Автоматизация технологических процессов',NULL,1,80)
ON DUPLICATE KEY UPDATE label = VALUES(label), active = 1, sort_order = VALUES(sort_order), updated_at = CURRENT_TIMESTAMP;
