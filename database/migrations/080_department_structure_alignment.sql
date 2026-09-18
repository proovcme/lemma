INSERT INTO departments (code, name, head_user_id)
SELECT 'ТИМ', 'Отдел технологий информационного моделирования', head_user_id
FROM departments WHERE code = 'BIM'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO departments (code, name, head_user_id)
SELECT 'КС-СКС', 'Отдел кабельных систем и структурированных сетей', head_user_id
FROM departments WHERE code = 'СС'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO departments (code, name) VALUES
('ДП', 'Дирекция департамента'),
('ГИП', 'Служба главных инженеров проектов'),
('ТИМ', 'Отдел технологий информационного моделирования'),
('НК', 'Отдел нормоконтроля'),
('АР', 'Отдел архитектурных решений'),
('АСУ', 'Отдел автоматизированных систем управления'),
('ОВ', 'Отдел отопления и вентиляции'),
('ВК', 'Отдел водоснабжения и водоотведения'),
('ЭОМ', 'Отдел силового электрооборудования и электроосвещения'),
('КС-СКС', 'Отдел кабельных систем и структурированных сетей'),
('СПЗ', 'Отдел специальных проектов защиты'),
('КСБ', 'Отдел комплексных систем безопасности');

UPDATE users SET department = 'ТИМ' WHERE department = 'BIM';
UPDATE users SET department = 'КС-СКС' WHERE department = 'СС';
UPDATE users SET department = 'ДП' WHERE department = 'ДПР';
UPDATE department_groups SET department_code = 'ТИМ' WHERE department_code = 'BIM';
UPDATE department_groups SET department_code = 'КС-СКС' WHERE department_code = 'СС';

INSERT INTO cfo_rates (dept_code, hourly_rate, label)
SELECT 'ТИМ', hourly_rate, label FROM cfo_rates WHERE dept_code = 'BIM'
ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), label = VALUES(label);
INSERT INTO cfo_rates (dept_code, hourly_rate, label)
SELECT 'КС-СКС', hourly_rate, label FROM cfo_rates WHERE dept_code = 'СС'
ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), label = VALUES(label);
INSERT INTO cfo_rates (dept_code, hourly_rate, label)
SELECT 'ДП', hourly_rate, label FROM cfo_rates WHERE dept_code = 'ДПР'
ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), label = VALUES(label);

DELETE FROM cfo_rates WHERE dept_code IN ('BIM', 'СС', 'ДПР');
DELETE FROM departments WHERE code IN ('BIM', 'СС');
