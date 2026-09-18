CREATE TABLE IF NOT EXISTS revit_activation_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_revit_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_revit_activation_user_expiry (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revit_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(190) NOT NULL DEFAULT '',
    plugin_version VARCHAR(40) NOT NULL DEFAULT '',
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_revit_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_revit_token_user_active (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_model_series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    discipline VARCHAR(80) NOT NULL DEFAULT '',
    next_version_number INT UNSIGNED NOT NULL DEFAULT 1,
    current_version_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_model_series_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_model_series_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_model_series_project_name (project_id, name),
    INDEX idx_model_series_current (current_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_model_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_series_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    file_relative_path VARCHAR(1000) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    comment TEXT NULL,
    revit_version VARCHAR(40) NOT NULL DEFAULT '',
    document_title VARCHAR(255) NOT NULL DEFAULT '',
    document_unique_id VARCHAR(255) NOT NULL DEFAULT '',
    view_name VARCHAR(255) NOT NULL DEFAULT '',
    view_unique_id VARCHAR(255) NOT NULL DEFAULT '',
    ifc_profile VARCHAR(255) NOT NULL DEFAULT '',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_model_version_series FOREIGN KEY (model_series_id) REFERENCES project_model_series(id) ON DELETE CASCADE,
    CONSTRAINT fk_model_version_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_model_version_number (model_series_id, version_number),
    INDEX idx_model_version_created (model_series_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_model_series
    ADD CONSTRAINT fk_model_series_current_version FOREIGN KEY (current_version_id) REFERENCES project_model_versions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS revit_upload_sessions (
    id CHAR(36) PRIMARY KEY,
    model_series_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    expected_size BIGINT UNSIGNED NOT NULL,
    expected_sha256 CHAR(64) NOT NULL,
    metadata_json LONGTEXT NOT NULL,
    chunk_size INT UNSIGNED NOT NULL,
    chunk_count INT UNSIGNED NOT NULL,
    received_chunks_json LONGTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'uploading',
    completed_version_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_revit_upload_series FOREIGN KEY (model_series_id) REFERENCES project_model_series(id) ON DELETE CASCADE,
    CONSTRAINT fk_revit_upload_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_revit_upload_version FOREIGN KEY (completed_version_id) REFERENCES project_model_versions(id) ON DELETE SET NULL,
    UNIQUE KEY uq_revit_upload_idempotency (user_id, idempotency_key),
    INDEX idx_revit_upload_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
