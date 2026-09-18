CREATE TABLE IF NOT EXISTS calculator_portfolio_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    amount_thousand DECIMAL(14,2) NOT NULL DEFAULT 0,
    area_m2 DECIMAL(14,2) NULL,
    start_date DATE NULL,
    finish_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'expected',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_calculator_portfolio_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_calculator_portfolio_status_finish (status, finish_date),
    INDEX idx_calculator_portfolio_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
