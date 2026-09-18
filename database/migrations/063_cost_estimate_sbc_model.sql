CREATE TABLE IF NOT EXISTS sbc_indices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_key VARCHAR(20) NOT NULL,
    label VARCHAR(120) NOT NULL,
    index_value DECIMAL(12,4) NOT NULL DEFAULT 1,
    source_ref VARCHAR(255) NULL,
    source_date DATE NULL,
    comment TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sbc_indices_period (period_key),
    KEY idx_sbc_indices_active (is_active, period_key),
    CONSTRAINT fk_sbc_indices_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_labor_estimates
    ADD COLUMN department_code VARCHAR(32) NULL AFTER executor_id,
    ADD COLUMN model_object_type VARCHAR(80) NULL AFTER department_code,
    ADD COLUMN model_stage VARCHAR(80) NULL AFTER model_object_type,
    ADD COLUMN model_area_m2 DECIMAL(14,2) NULL AFTER model_stage,
    ADD COLUMN model_quantity DECIMAL(14,4) NOT NULL DEFAULT 1 AFTER model_area_m2,
    ADD COLUMN model_complexity_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER model_quantity,
    ADD COLUMN model_typicality_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER model_complexity_coeff,
    ADD COLUMN model_bim_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER model_typicality_coeff,
    ADD COLUMN model_urgency_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER model_bim_coeff,
    ADD COLUMN model_input_quality_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER model_urgency_coeff,
    ADD COLUMN model_suggested_hours DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER model_input_quality_coeff,
    ADD COLUMN model_basis TEXT NULL AFTER model_suggested_hours,
    ADD COLUMN sbc_item_id BIGINT UNSIGNED NULL AFTER model_basis,
    ADD COLUMN sbc_quantity DECIMAL(14,4) NOT NULL DEFAULT 1 AFTER sbc_item_id,
    ADD COLUMN sbc_stage_percent DECIMAL(8,2) NOT NULL DEFAULT 100 AFTER sbc_quantity,
    ADD COLUMN sbc_index_id BIGINT UNSIGNED NULL AFTER sbc_stage_percent,
    ADD COLUMN sbc_adjustment_coeff DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER sbc_index_id,
    ADD COLUMN sbc_cost_snapshot DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER sbc_adjustment_coeff,
    ADD COLUMN sbc_basis_snapshot TEXT NULL AFTER sbc_cost_snapshot,
    ADD COLUMN department_submitted_by BIGINT UNSIGNED NULL AFTER executor_submitted_at,
    ADD COLUMN department_submitted_at DATETIME NULL AFTER department_submitted_by,
    ADD COLUMN gip_adjusted_at DATETIME NULL AFTER gip_approved_at,
    ADD COLUMN director_cost_thousand DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER director_approved_at,
    ADD COLUMN director_money_snapshot TEXT NULL AFTER director_cost_thousand;

ALTER TABLE project_labor_estimates
    DROP FOREIGN KEY fk_project_labor_estimates_task;

ALTER TABLE project_labor_estimates
    MODIFY task_id BIGINT UNSIGNED NULL,
    MODIFY status ENUM('draft','department_submitted','gip_adjusted','returned_to_department','director_approved','assigned','submitted','returned_to_responsible','gip_approved','returned_to_gip') NOT NULL DEFAULT 'draft';

CREATE INDEX idx_project_labor_department ON project_labor_estimates(department_code);
CREATE INDEX idx_project_labor_sbc_item ON project_labor_estimates(sbc_item_id);
CREATE INDEX idx_project_labor_sbc_index ON project_labor_estimates(sbc_index_id);

ALTER TABLE project_labor_estimates
    ADD CONSTRAINT fk_project_labor_estimates_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_project_labor_sbc_item FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_project_labor_sbc_index FOREIGN KEY (sbc_index_id) REFERENCES sbc_indices(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_project_labor_department_by FOREIGN KEY (department_submitted_by) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO sbc_items (
    reference_hash, collection_code, collection_name, edition, table_code, item_code, work_name, unit,
    base_price, price_level, default_labor_hours, formula, note, source_ref, justification_template
) VALUES
('5edf7c4dc74f0e913955daed43941ff20eceef99fa535eb55e56206f59bb2ef2', 'СБЦП 81-02-03-2001', 'Объекты жилищно-гражданского строительства', 'Приказ Минрегиона РФ от 28.05.2010 N 260', '1', '1', 'Жилые дома, проектные работы по натуральному показателю', '1000 м2 общей площади', 120.00, '01.01.2001', 160, 'C=a+b*x; a=35; b=85', 'Стартовая позиция справочника для предпроектной оценки; уточняется сметчиком по актуальному пункту ФРСН.', 'ФГИС ЦС / ФРСН, СБЦП 81-02-03-2001, снимок 2026-06-16', 'Базовая цена определяется по натуральному показателю объекта с ручным индексом ПИР.'),
('be72c3705e7c8413a5ab612be3ccdc103616ea536834d17ee21bc6a0a3d3db67', 'СБЦП 81-02-03-2001', 'Объекты жилищно-гражданского строительства', 'Приказ Минрегиона РФ от 28.05.2010 N 260', '25', '1', 'Административные здания', '1000 м3 строительного объема', 98.00, '01.01.2001', 140, 'C=a+b*x; a=28; b=70', 'Для зданий административного назначения; стадийность и корректировки задаются в строке оценки.', 'ФГИС ЦС / ФРСН, СБЦП 81-02-03-2001, снимок 2026-06-16', 'Стоимость проектных работ берется по таблице СБЦ и приводится индексом ПИР.'),
('870086b9a2f8ce47d415c1769d2747de20d1ede34affc71f6644828594002e8d', 'СБЦП 81-02-03-2001', 'Объекты жилищно-гражданского строительства', 'Приказ Минрегиона РФ от 28.05.2010 N 260', '27', '1', 'Общеобразовательные школы', 'учащихся', 75.00, '01.01.2001', 180, 'C=a+b*x; a=42; b=0.55', 'Натуральный показатель задается количеством мест/учащихся.', 'ФГИС ЦС / ФРСН, СБЦП 81-02-03-2001, снимок 2026-06-16', 'Расчет выполняется по базовой цене и натуральному показателю.'),
('fa1ddd7dc5dd769a4f01c628e1e795c74baea368e158083f101e1ede78102825', 'СБЦП 81-02-03-2001', 'Объекты жилищно-гражданского строительства', 'Приказ Минрегиона РФ от 28.05.2010 N 260', '29', '1', 'Дошкольные образовательные учреждения', 'мест', 68.00, '01.01.2001', 150, 'C=a+b*x; a=36; b=0.62', 'Используется как встроенная позиция для первичной оценки социальных объектов.', 'ФГИС ЦС / ФРСН, СБЦП 81-02-03-2001, снимок 2026-06-16', 'Итоговая стоимость фиксируется snapshot при утверждении директором.'),
('8ca704d2cae718537fcf6229209c929fb956851266e97d128171457d74b02db4', 'СБЦП 81-02-03-2001', 'Объекты жилищно-гражданского строительства', 'Приказ Минрегиона РФ от 28.05.2010 N 260', '31', '1', 'Лечебно-профилактические учреждения', 'коек', 135.00, '01.01.2001', 220, 'C=a+b*x; a=70; b=1.3', 'Повышенная сложность задается отдельным коэффициентом строки.', 'ФГИС ЦС / ФРСН, СБЦП 81-02-03-2001, снимок 2026-06-16', 'СБЦ используется как нормативная опора, часы утверждаются человеком.')
ON DUPLICATE KEY UPDATE
    collection_code = VALUES(collection_code),
    collection_name = VALUES(collection_name),
    edition = VALUES(edition),
    table_code = VALUES(table_code),
    item_code = VALUES(item_code),
    work_name = VALUES(work_name),
    unit = VALUES(unit),
    base_price = VALUES(base_price),
    price_level = VALUES(price_level),
    default_labor_hours = VALUES(default_labor_hours),
    formula = VALUES(formula),
    note = VALUES(note),
    source_ref = VALUES(source_ref),
    justification_template = VALUES(justification_template),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO sbc_indices (period_key, label, index_value, source_ref, source_date, comment, is_active)
VALUES ('2026-Q2', 'II квартал 2026', 1.0000, 'Ручной индекс ПИР: заполнить по письму Минстроя/утвержденному источнику', '2026-06-16', 'Стартовое значение для offline-поставки. Директор/админ меняет вручную перед расчетом.', 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    index_value = VALUES(index_value),
    source_ref = VALUES(source_ref),
    source_date = VALUES(source_date),
    comment = VALUES(comment),
    is_active = VALUES(is_active),
    updated_at = CURRENT_TIMESTAMP;
