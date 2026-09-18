CREATE TABLE IF NOT EXISTS exchange_template_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    scope_section VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exchange_template_sets_code (code),
    INDEX idx_exchange_template_sets_active (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_template_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_set_id BIGINT UNSIGNED NOT NULL,
    item_code VARCHAR(120) NOT NULL,
    direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'incoming',
    from_section VARCHAR(50) NULL,
    to_section VARCHAR(50) NULL,
    assignment TEXT NOT NULL,
    default_status ENUM('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending',
    comments TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_exchange_template_items_set FOREIGN KEY (template_set_id) REFERENCES exchange_template_sets(id) ON DELETE CASCADE,
    UNIQUE KEY uq_exchange_template_items_code (template_set_id, item_code),
    INDEX idx_exchange_template_items_set_order (template_set_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_task_exchange
    ADD COLUMN template_item_id BIGINT UNSIGNED NULL AFTER task_id;

ALTER TABLE project_task_exchange
    ADD INDEX idx_exchange_template_item (template_item_id);

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_template_item
    FOREIGN KEY (template_item_id) REFERENCES exchange_template_items(id) ON DELETE SET NULL;

INSERT INTO exchange_template_sets (code, name, scope_section, description, sort_order)
VALUES
    ('asu_base', 'АСУ: типовой обмен заданиями', 'АСУ', 'Входящие и исходящие задания группы АСУ по положению об обмене заданиями.', 10),
    ('cable_trays', 'Кабельные лотки: трассы кабелей', 'Лотки', 'Все кабельные разделы выдают трассы кабелей; по ним формируется схема и планы лотков.', 20)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    scope_section = VALUES(scope_section),
    description = VALUES(description),
    sort_order = VALUES(sort_order),
    is_active = 1;

INSERT INTO exchange_template_items (template_set_id, item_code, direction, from_section, to_section, assignment, default_status, comments, sort_order)
SELECT s.id, v.item_code, v.direction, v.from_section, v.to_section, v.assignment, v.default_status, v.comments, v.sort_order
FROM exchange_template_sets s
JOIN (
    SELECT 'asu_base' AS set_code, 'in_ovik' AS item_code, 'incoming' AS direction, 'ОВиК' AS from_section, 'АСУ' AS to_section, 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, описание работы агрегатов при переходе погодных режимов, технические подборы оборудования, ХОВС, перечень сигналов для диспетчеризации' AS assignment, 'pending' AS default_status, 'Матрица АСУ: входящее задание от ОВиК.' AS comments, 10 AS sort_order
    UNION ALL SELECT 'asu_base', 'in_vk', 'incoming', 'ВК', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ВК.', 20
    UNION ALL SELECT 'asu_base', 'in_eom', 'incoming', 'ЭОМ', 'АСУ', 'Однолинейные схемы электроснабжения, планы расположения оборудования, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ЭОМ.', 30
    UNION ALL SELECT 'asu_base', 'in_tm', 'incoming', 'ТМ', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ТМ.', 40
    UNION ALL SELECT 'asu_base', 'in_tx', 'incoming', 'ТХ', 'АСУ', 'Принципиальные схемы, планы расположения оборудования, алгоритмы работы нестандартных систем, технические подборы оборудования, перечень сигналов для диспетчеризации', 'pending', 'Матрица АСУ: входящее задание от ТХ.', 50
    UNION ALL SELECT 'asu_base', 'in_ar', 'incoming', 'АР (АИ)', 'АСУ', 'Планы расположения оконечных устройств и групп управления освещения, технические подборы оборудования, сценарии управления', 'pending', 'Матрица АСУ: входящее задание от АР (АИ).', 60
    UNION ALL SELECT 'asu_base', 'out_eom', 'outgoing', 'АСУ', 'ЭОМ', 'Перечень оборудования автоматизации для обеспечения электроснабжением: категория электроснабжения, количество фаз, мощность; планы расположения оборудования', 'pending', 'Матрица АСУ: исходящее задание в ЭОМ.', 70
    UNION ALL SELECT 'asu_base', 'out_ss', 'outgoing', 'АСУ', 'СС', 'Перечень оборудования автоматизации для подключения к ЛВС здания: количество портов, тип разъема', 'pending', 'Матрица АСУ: исходящее задание в СС.', 80
    UNION ALL SELECT 'asu_base', 'out_sppz', 'outgoing', 'АСУ', 'СППЗ', 'Перечень оборудования автоматизации для обеспечения приема сигнала «Пожар» с указанием характеристик входного контакта: тип НЗ, рабочие напряжение и ток', 'pending', 'Матрица АСУ: исходящее задание в СППЗ.', 90
    UNION ALL SELECT 'cable_trays', 'route_eom', 'incoming', 'ЭОМ', 'Лотки', 'Трассы силовых и питающих кабелей, требования к раздельной прокладке, габариты пучков и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от ЭОМ.', 10
    UNION ALL SELECT 'cable_trays', 'route_ss', 'incoming', 'СС', 'Лотки', 'Трассы слаботочных кабелей, требования к ЛВС/связи, габариты пучков и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от СС.', 20
    UNION ALL SELECT 'cable_trays', 'route_asu', 'incoming', 'АСУ', 'Лотки', 'Трассы кабелей автоматизации и диспетчеризации, требования к раздельной прокладке и точки подключения для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от АСУ.', 30
    UNION ALL SELECT 'cable_trays', 'route_sppz', 'incoming', 'СППЗ', 'Лотки', 'Трассы кабелей пожарной автоматики/сигнализации, требования к огнестойкости и раздельной прокладке для разработки кабельных лотков', 'pending', 'Матрица лотков: входящие трассы кабелей от СППЗ.', 40
    UNION ALL SELECT 'cable_trays', 'out_common', 'outgoing', 'Лотки', 'ЭОМ/СС/АСУ/СППЗ', 'Сводные планы и сечения кабельных лотков для проверки трасс, заполнения, резервов и конфликтов прокладки', 'pending', 'Матрица лотков: исходящее сводное задание смежникам на проверку.', 50
) v ON v.set_code = s.code
ON DUPLICATE KEY UPDATE
    direction = VALUES(direction),
    from_section = VALUES(from_section),
    to_section = VALUES(to_section),
    assignment = VALUES(assignment),
    default_status = VALUES(default_status),
    comments = VALUES(comments),
    sort_order = VALUES(sort_order);
