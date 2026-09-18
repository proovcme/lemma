INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
VALUES
    (NULL, 0, 'section', 'ГП', 'Генеральный план', 'ГП', 1, 10),
    (NULL, 0, 'section', 'АР', 'Архитектурные решения', 'АР', 1, 20),
    (NULL, 0, 'section', 'КР', 'Конструктивные решения', 'КР', 1, 30),
    (NULL, 0, 'section', 'ОВ', 'Отопление и вентиляция', 'ОВ', 1, 40),
    (NULL, 0, 'section', 'ВК', 'Водоснабжение и канализация', 'ВК', 1, 50),
    (NULL, 0, 'section', 'НВК', 'Наружные сети водоснабжения и канализации', 'НВК', 1, 60),
    (NULL, 0, 'section', 'ЭОМ', 'Электрооборудование и освещение', 'ЭОМ', 1, 70),
    (NULL, 0, 'section', 'КС-СКС', 'Комплексные слаботочные системы', 'КС-СКС', 1, 80),
    (NULL, 0, 'section', 'СС', 'Системы связи', 'СС', 1, 90),
    (NULL, 0, 'section', 'ТХ', 'Технологические решения', 'ТХ', 1, 100),
    (NULL, 0, 'section', 'АТХ', 'Автоматизация технологических процессов', 'АТХ', 1, 110),
    (NULL, 0, 'section', 'АОВ', 'Автоматизация отопления и вентиляции', 'АОВ', 1, 120),
    (NULL, 0, 'section', 'ПБ', 'Пожарная безопасность', 'ПБ', 1, 130),
    (NULL, 0, 'section', 'ТИМ', 'Технологии информационного моделирования', 'ТИМ', 1, 140)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    discipline = VALUES(discipline),
    active = 1,
    sort_order = VALUES(sort_order),
    updated_at = CURRENT_TIMESTAMP;
