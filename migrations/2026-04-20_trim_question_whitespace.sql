-- One-time cleanup: strip trailing whitespace (CR/LF/tabs/spaces) from question text and
-- all four option fields. Legacy imports via fgets() left \r\n residue on every row.

UPDATE questions SET
    question_text = REGEXP_REPLACE(question_text, '[[:space:]]+$', ''),
    option1       = REGEXP_REPLACE(option1,       '[[:space:]]+$', ''),
    option2       = REGEXP_REPLACE(option2,       '[[:space:]]+$', ''),
    option3       = REGEXP_REPLACE(option3,       '[[:space:]]+$', ''),
    option4       = REGEXP_REPLACE(option4,       '[[:space:]]+$', '');
