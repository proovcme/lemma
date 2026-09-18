ALTER TABLE positions
    ADD COLUMN IF NOT EXISTS competency_position_index INT NULL AFTER grade;

ALTER TABLE performance_review_cycles
    ADD COLUMN IF NOT EXISTS cycle_kind ENUM('annual','test') NOT NULL DEFAULT 'annual' AFTER title;

ALTER TABLE performance_review_cycles
    DROP INDEX IF EXISTS uq_pr_cycle_review_year,
    ADD INDEX IF NOT EXISTS idx_pr_cycles_year_kind (review_year, cycle_kind);

UPDATE positions p
JOIN (
    SELECT 0 AS matrix_index, 'Студент / стажёр' AS title UNION ALL
    SELECT 1, 'Техник' UNION ALL
    SELECT 2, 'Инженер-проектировщик' UNION ALL
    SELECT 3, 'Инженер-проектировщик 2 кат.' UNION ALL
    SELECT 4, 'Инженер нормоконтроля' UNION ALL
    SELECT 5, 'Инженер-проектировщик 1 кат.' UNION ALL
    SELECT 6, 'Помощник ГИПа' UNION ALL
    SELECT 7, 'ТИМ координатор' UNION ALL
    SELECT 8, 'Ведущий инженер-проектировщик' UNION ALL
    SELECT 9, 'Главный специалист по нормоконтролю' UNION ALL
    SELECT 10, 'Менеджер ТИМ' UNION ALL
    SELECT 11, 'Руководитель группы' UNION ALL
    SELECT 12, 'Главный специалист' UNION ALL
    SELECT 13, 'Начальник отдела' UNION ALL
    SELECT 14, 'Руководитель ТИМ' UNION ALL
    SELECT 15, 'Главный инженер проекта' UNION ALL
    SELECT 16, 'Главный архитектор проекта' UNION ALL
    SELECT 17, 'Зам. директора департамента / нач. бюро ГИП' UNION ALL
    SELECT 18, 'Директор департамента'
) matrix_position ON matrix_position.title = p.title
SET p.competency_position_index = matrix_position.matrix_index
WHERE p.competency_position_index IS NULL;
