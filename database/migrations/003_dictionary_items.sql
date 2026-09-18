CREATE TABLE IF NOT EXISTS dictionary_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NULL,
    scope_project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    kind ENUM('volume','section_code','section') NOT NULL,
    value VARCHAR(120) NOT NULL,
    label VARCHAR(255) NULL,
    discipline VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dictionary_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY uq_dictionary_scope_kind_value (scope_project_id, kind, value),
    INDEX idx_dictionary_kind_active (kind, active),
    INDEX idx_dictionary_project_kind (scope_project_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dictionary_items (scope_project_id, kind, value, label, discipline, sort_order) VALUES
(0, 'volume', '1', 'Том 1', NULL, 10),
(0, 'volume', '15.6.3.1', '15.6.3.1', NULL, 20),
(0, 'section_code', 'ОВ', 'ОВ — Отопление и вентиляция', 'ОВ', 10),
(0, 'section_code', 'ВК', 'ВК — Водоснабжение и канализация', 'ВК', 20),
(0, 'section_code', 'АР', 'АР — Архитектурные решения', 'АР', 30),
(0, 'section_code', 'КР', 'КР — Конструктивные решения', 'КР', 40),
(0, 'section_code', 'ЭОМ', 'ЭОМ — Электрооборудование', 'ЭОМ', 50),
(0, 'section', 'ОВ', 'ОВ', 'ОВ', 10),
(0, 'section', 'ВК', 'ВК', 'ВК', 20),
(0, 'section', 'АР', 'АР', 'АР', 30)
ON DUPLICATE KEY UPDATE label = VALUES(label), discipline = VALUES(discipline), sort_order = VALUES(sort_order), active = 1;
