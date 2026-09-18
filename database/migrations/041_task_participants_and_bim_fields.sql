CREATE TABLE IF NOT EXISTS task_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('assignee','coauthor','observer') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_participants_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_task_participant_role (task_id, user_id, role),
    INDEX idx_task_participants_task (task_id),
    INDEX idx_task_participants_user_role (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
SELECT 'bim_model', 'Модель', 'text', NULL, NULL, 0, 31
WHERE NOT EXISTS (
    SELECT 1 FROM custom_fields WHERE project_id IS NULL AND name = 'bim_model'
);

INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
SELECT 'bim_image', 'Изображение', 'link', NULL, NULL, 0, 32
WHERE NOT EXISTS (
    SELECT 1 FROM custom_fields WHERE project_id IS NULL AND name = 'bim_image'
);

INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
SELECT 'bim_response', 'Ответ BIM отдела', 'text', NULL, NULL, 0, 33
WHERE NOT EXISTS (
    SELECT 1 FROM custom_fields WHERE project_id IS NULL AND name = 'bim_response'
);

INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
SELECT 'bim_electrical_connectors', 'Электрические коннекторы', 'select', NULL, '["Не требуется","Требуется","Настроить","Проверить"]', 0, 34
WHERE NOT EXISTS (
    SELECT 1 FROM custom_fields WHERE project_id IS NULL AND name = 'bim_electrical_connectors'
);
