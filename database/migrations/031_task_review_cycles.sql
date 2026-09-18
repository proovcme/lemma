ALTER TABLE tasks
    MODIFY status ENUM(
        'new',
        'in_progress',
        'review',
        'correction',
        'done',
        'blocked',
        'overdue',
        'pending_close'
    ) NOT NULL DEFAULT 'new';

ALTER TABLE tasks
    MODIFY task_type ENUM('work','assignment','issuance','labor_estimate','review') NOT NULL DEFAULT 'work';

ALTER TABLE task_approvals
    MODIFY stage ENUM('review_lead','review_gip','review_task','issued','close_author','close_gip') NOT NULL;
