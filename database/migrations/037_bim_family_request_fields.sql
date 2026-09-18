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
