CREATE TABLE IF NOT EXISTS counterparties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(255) NOT NULL,
    role VARCHAR(120) NULL,
    representative VARCHAR(160) NULL,
    contact VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_counterparties_identity (company, role, representative),
    INDEX idx_counterparties_company (company),
    INDEX idx_counterparties_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_task_exchange
    ADD COLUMN direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing' AFTER task_id;

ALTER TABLE project_task_exchange
    ADD COLUMN from_counterparty_id BIGINT UNSIGNED NULL AFTER from_user_id;

ALTER TABLE project_task_exchange
    ADD COLUMN from_external_name VARCHAR(255) NULL AFTER from_counterparty_id;

ALTER TABLE project_task_exchange
    ADD COLUMN to_counterparty_id BIGINT UNSIGNED NULL AFTER to_user_id;

ALTER TABLE project_task_exchange
    ADD COLUMN to_external_name VARCHAR(255) NULL AFTER to_counterparty_id;

ALTER TABLE project_task_exchange
    ADD INDEX idx_exchange_direction (direction);

ALTER TABLE project_task_exchange
    ADD INDEX idx_exchange_from_counterparty (from_counterparty_id);

ALTER TABLE project_task_exchange
    ADD INDEX idx_exchange_to_counterparty (to_counterparty_id);

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_from_counterparty
    FOREIGN KEY (from_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL;

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_to_counterparty
    FOREIGN KEY (to_counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL;
