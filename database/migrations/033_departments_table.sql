CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    head_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (head_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO departments (code, name) VALUES
('ОВ', 'Отдел отопления и вентиляции'),
('ЭОМ', 'Отдел силового электрооборудования и электроосвещения'),
('СС', 'Отдел систем связи'),
('ГИП', 'Служба главных инженеров проектов (ГИП)'),
('BIM', 'Отдел ТИМ/BIM моделирования'),
('АСУ', 'Отдел автоматизированных систем управления'),
('ВК', 'Отдел водоснабжения и водоотведения'),
('КР', 'Отдел конструктивных решений'),
('АР', 'Отдел архитектурных решений');
