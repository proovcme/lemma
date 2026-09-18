ALTER TABLE tasks ADD INDEX idx_tasks_author_status (author_id, status);
ALTER TABLE project_issues ADD INDEX idx_issues_assignee_status_project_date (assignee_id, status, project_id, date_raised);
ALTER TABLE task_logs ADD INDEX idx_task_logs_task_field_newval_id (task_id, field, new_val(191), id);
