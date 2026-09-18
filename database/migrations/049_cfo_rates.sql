CREATE TABLE IF NOT EXISTS cfo_rates (
    dept_code VARCHAR(40) PRIMARY KEY,
    hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    label VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cfo_rates (dept_code, hourly_rate, label) VALUES
    ('АР', 358, 'Архитектурный отдел (медиана ЦФО)'),
    ('КР', 690, 'Конструкции (медиана ДПР)'),
    ('ОВ', 690, 'ОВиК (медиана ДПР)'),
    ('ВК', 951, 'Водоснабжение и водоотведение (медиана ЦФО)'),
    ('ЭОМ', 609, 'Электрооборудование (медиана ЦФО)'),
    ('СС', 571, 'Системы связи / СКС (медиана ЦФО)'),
    ('АСУ', 781, 'АСУЗ (медиана ЦФО)'),
    ('BIM', 431, 'ТИМ / BIM (медиана ЦФО)'),
    ('ГИП', 848, 'Бюро ГИП (медиана ЦФО)'),
    ('ДПР', 690, 'Департамент проектирования (медиана)');
