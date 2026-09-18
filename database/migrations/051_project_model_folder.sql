-- Папка с моделями проекта (сетевая шара). Атлас берёт модели из неё (рекурсивный
-- скан .frag/.ifc/.ifczip, .frag в приоритете), а не только из отдельных файлов.
-- Ручная привязка отдельных моделей (project_model_links) сохраняется.
ALTER TABLE projects
    ADD COLUMN model_folder_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER file_folder_url;
