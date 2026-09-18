CREATE TABLE IF NOT EXISTS positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL UNIQUE,
    grade VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE positions
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE users
    ADD COLUMN position_id BIGINT UNSIGNED NULL AFTER department;

ALTER TABLE users
    ADD COLUMN manager_id BIGINT UNSIGNED NULL AFTER position_id;

ALTER TABLE users
    MODIFY COLUMN position_id BIGINT UNSIGNED NULL;

ALTER TABLE users
    MODIFY COLUMN manager_id BIGINT UNSIGNED NULL;

ALTER TABLE users
    ADD INDEX idx_users_position (position_id);

ALTER TABLE users
    ADD INDEX idx_users_manager (manager_id);

ALTER TABLE users
    ADD CONSTRAINT fk_users_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL;

ALTER TABLE users
    ADD CONSTRAINT fk_users_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO positions (title, grade, sort_order)
VALUES
('Студент / стажёр', 'N-ст', 10),
('Техник', 'N-12', 20),
('Инженер-проектировщик', 'N-11', 30),
('Инженер-проектировщик 2 кат.', 'N-10', 40),
('Инженер нормоконтроля', 'N-10', 50),
('Инженер-проектировщик 1 кат.', 'N-9', 60),
('Помощник ГИПа', 'N-8', 70),
('ТИМ координатор', 'N-9', 80),
('Ведущий инженер-проектировщик', 'N-8', 90),
('Главный специалист по нормоконтролю', 'N-10', 100),
('Менеджер ТИМ', 'N-7', 110),
('Руководитель группы', 'N-7', 120),
('Главный специалист', 'N-7', 130),
('Начальник отдела', 'N-6', 140),
('Руководитель ТИМ', 'N-6', 150),
('Главный инженер проекта', 'N-5', 160),
('Главный архитектор проекта', 'N-5', 170),
('Зам. директора департамента / нач. бюро ГИП', 'N-4', 180),
('Директор департамента', 'N-3', 190)
ON DUPLICATE KEY UPDATE
    grade = VALUES(grade),
    sort_order = VALUES(sort_order);
