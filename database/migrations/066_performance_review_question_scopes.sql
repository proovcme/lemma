ALTER TABLE performance_review_questions
    ADD COLUMN IF NOT EXISTS answer_scope VARCHAR(20) NOT NULL DEFAULT 'both' AFTER question_type;

UPDATE performance_review_questions
SET answer_scope = 'both'
WHERE answer_scope IS NULL OR answer_scope = '';
