INSERT INTO positions (title, grade, sort_order)
VALUES
('Директор Н2', 'Н2', 200),
('Директор Н1', 'Н1', 210)
ON DUPLICATE KEY UPDATE
    grade = VALUES(grade),
    sort_order = VALUES(sort_order);
