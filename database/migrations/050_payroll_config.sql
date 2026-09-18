-- Фаза 1 модуля «Свод трудозатрат и ФОТ»: справочники и настройка.
-- Юрлица, статьи списания и назначение сотрудников в юрлица со ставками/окладами.

CREATE TABLE IF NOT EXISTS legal_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    full_name VARCHAR(500) NULL,
    inn VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS writeoff_articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    kind ENUM('project','nonproject') NOT NULL DEFAULT 'nonproject',
    maps_category VARCHAR(40) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO writeoff_articles (code, name, kind, maps_category, sort_order) VALUES
    ('00', 'Проектная задача', 'project', 'task', 10),
    ('02', 'Отчётность отсутствует', 'nonproject', NULL, 20),
    ('92', 'Обучение внешнее', 'nonproject', 'learning', 30),
    ('93', 'Очередной отпуск', 'nonproject', 'vacation', 40),
    ('94', 'Болезнь', 'nonproject', 'sick_leave', 50),
    ('98', 'Простои по вине рук-ва', 'nonproject', 'idle', 60),
    ('99', 'Отпуск за свой счёт', 'nonproject', 'day_off', 70);

CREATE TABLE IF NOT EXISTS employee_legal_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    legal_entity_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    daily_hours DECIMAL(4,2) NOT NULL DEFAULT 0,
    position VARCHAR(255) NULL,
    cost_group VARCHAR(80) NULL,
    base_oklad DECIMAL(12,2) NOT NULL DEFAULT 0,
    base_nadbavka DECIMAL(12,2) NOT NULL DEFAULT 0,
    premium DECIMAL(12,2) NOT NULL DEFAULT 0,
    project_nadbavka DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_piecework TINYINT(1) NOT NULL DEFAULT 0,
    rate_override DECIMAL(12,2) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_entity (user_id, legal_entity_id),
    CONSTRAINT fk_ele_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ele_entity FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE CASCADE,
    CONSTRAINT fk_ele_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ele_entity ON employee_legal_entities(legal_entity_id);
