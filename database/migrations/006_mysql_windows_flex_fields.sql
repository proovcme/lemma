ALTER TABLE tasks
    MODIFY discipline VARCHAR(50) NULL;

ALTER TABLE dictionary_items
    MODIFY discipline VARCHAR(50) NULL;

ALTER TABLE custom_fields
    MODIFY type ENUM('text','select','date','number','user','bool','link','links') NOT NULL DEFAULT 'text';

ALTER TABLE custom_values
    MODIFY value TEXT NULL;
