CREATE TABLE IF NOT EXISTS sbc_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_hash CHAR(64) NOT NULL,
    collection_code VARCHAR(80) NOT NULL DEFAULT '',
    collection_name VARCHAR(255) NOT NULL,
    edition VARCHAR(80) NULL,
    table_code VARCHAR(120) NULL,
    item_code VARCHAR(120) NOT NULL DEFAULT '',
    work_name TEXT NOT NULL,
    unit VARCHAR(120) NULL,
    base_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    price_level VARCHAR(80) NULL,
    default_labor_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    formula TEXT NULL,
    note TEXT NULL,
    source_ref VARCHAR(255) NULL,
    justification_template TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sbc_reference_hash (reference_hash),
    INDEX idx_sbc_collection (collection_code, edition),
    INDEX idx_sbc_table_item (table_code, item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_cost_plan
    ADD COLUMN sbc_item_id BIGINT UNSIGNED NULL AFTER section_code;

ALTER TABLE project_cost_plan
    ADD CONSTRAINT fk_cost_plan_sbc_item FOREIGN KEY (sbc_item_id) REFERENCES sbc_items(id) ON DELETE SET NULL;

ALTER TABLE project_cost_plan
    ADD INDEX idx_cost_plan_sbc_item (sbc_item_id);
