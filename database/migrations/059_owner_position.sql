INSERT INTO positions (title, grade, sort_order)
VALUES ('Собственник', '0', 220)
ON DUPLICATE KEY UPDATE
    grade = VALUES(grade),
    sort_order = VALUES(sort_order);
