ALTER TABLE project_task_exchange
    ADD COLUMN task_id BIGINT UNSIGNED NULL AFTER project_id;

ALTER TABLE project_task_exchange
    ADD COLUMN from_user_id BIGINT UNSIGNED NULL AFTER task_id,
    ADD COLUMN to_user_id BIGINT UNSIGNED NULL AFTER from_user_id;

ALTER TABLE project_task_exchange
    ADD UNIQUE KEY uq_exchange_project_task (project_id, task_id),
    ADD INDEX idx_exchange_task (task_id),
    ADD INDEX idx_exchange_from_user (from_user_id),
    ADD INDEX idx_exchange_to_user (to_user_id);

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_task
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL;

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_from_user
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE project_task_exchange
    ADD CONSTRAINT fk_exchange_to_user
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL;
