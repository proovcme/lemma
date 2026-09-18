ALTER TABLE time_entries
    MODIFY category ENUM(
        'task',
        'meeting',
        'admin',
        'learning',
        'vacation',
        'sick_leave',
        'business_trip',
        'day_off',
        'idle',
        'absence',
        'overtime',
        'other'
    ) NOT NULL DEFAULT 'task';
