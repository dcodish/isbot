-- Feature: Student-facing Exam Mode  (see docs/features/exam-mode.md)
-- Additive, idempotent, non-destructive. Safe to re-run.
--
-- Adds:
--   * exam_attempts            — one row per practice-exam attempt (graded result)
--   * exam_attempt_questions   — one row per question in an attempt (per-lecture history)
--   * users.active_exam_attempt_id — soft pointer to the in-progress attempt
--   * settings: exam_num_questions / exam_time_minutes / exam_pass_grade
--   * actions: 36 ExamStart / 37 ExamCompleted / 38 ExamStopped (log audit events)
--
-- Apply with utf8mb4 so any Hebrew is not mangled:
--   mysql -u isbot -p... isquestions_gamified --default-character-set=utf8mb4 < 2026-06-30_exam-mode.sql

-- 1) exam_attempts -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS exam_attempts (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    user_id            BIGINT NOT NULL,
    status             ENUM('in_progress','completed','expired') NOT NULL DEFAULT 'in_progress',
    num_questions      TINYINT NOT NULL DEFAULT 10,   -- questions actually served (may be < target on a thin pool)
    num_correct        TINYINT NOT NULL DEFAULT 0,    -- filled at finalize
    grade              TINYINT NULL,                  -- 0..100, filled at finalize
    time_limit_seconds INT NOT NULL DEFAULT 1200,     -- snapshot of the limit at start
    started_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at        TIMESTAMP NULL,
    KEY idx_exam_attempts_user_started (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) exam_attempt_questions --------------------------------------------------
-- max_lecture is SNAPSHOT at exam time so per-lecture history is stable even if
-- the question is later re-tagged. No FK to questions (admin may delete a
-- question; recordAnswer() already tolerates that race). FK to exam_attempts
-- with ON DELETE CASCADE so abandoning an exam (delete the attempt) auto-removes
-- its question rows.
CREATE TABLE IF NOT EXISTS exam_attempt_questions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id     INT NOT NULL,
    question_id    INT NOT NULL,
    position       TINYINT NOT NULL,               -- 1..num_questions order served
    max_lecture    INT NULL,                       -- snapshot; NULL = "always visible"
    correct_answer VARCHAR(1024) NULL,             -- correct option text, snapshot for feedback
    user_answer    TINYINT NULL,                   -- chosen displayed option number (NULL = unanswered)
    is_correct     TINYINT(1) NULL,                -- 1 / 0 / NULL (unanswered at expiry)
    served_at      TIMESTAMP NULL,
    answered_at    TIMESTAMP NULL,
    KEY idx_eaq_attempt (attempt_id),
    CONSTRAINT fk_eaq_attempt FOREIGN KEY (attempt_id)
        REFERENCES exam_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) users.active_exam_attempt_id (guarded — no ADD COLUMN IF NOT EXISTS) -----
-- Soft pointer (no FK): it is set on start and cleared on finalize/cancel; the
-- attempt rows are deleted on cancel, so a hard FK would only add ordering risk.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'active_exam_attempt_id');
SET @ddl := IF(@col = 0,
               'ALTER TABLE users ADD COLUMN active_exam_attempt_id INT NULL',
               'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) settings knobs (preserve existing values on re-run) ----------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('exam_num_questions',  '10'),
    ('exam_time_minutes',   '20'),
    ('exam_pass_grade',     '56'),
    -- Staged rollout: while in development, exam mode is restricted to the staff
    -- cohort. Flip exam_enabled_for_all to '1' to open it to everyone (no deploy).
    ('exam_enabled_for_all', '0'),
    ('exam_staff_cohort_id', '3')   -- the "צוות" cohort
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 5) log audit events (same pattern as 2026-04-20_log_actions_expansion.sql) --
INSERT IGNORE INTO actions (action_id, action) VALUES
    (36, 'ExamStart'),       -- exam attempt created      (additional_value = attempt_id)
    (37, 'ExamCompleted'),   -- finished or timer expired (additional_value = attempt_id)
    (38, 'ExamStopped');     -- user pressed "stop"       (additional_value = attempt_id)

-- 6) VERIFY (run manually after applying) ------------------------------------
--   SHOW TABLES LIKE 'exam_%';
--   SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'exam_%';
--   SELECT action_id, action FROM actions WHERE action_id IN (36,37,38);
--   SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE table_name='users' AND column_name='active_exam_attempt_id';  -- expect 1
