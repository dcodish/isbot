-- Feature: Cohorts / Groups  (see docs/features/cohorts.md)
-- Phase 0 — additive, idempotent, NON-destructive. Safe to re-run.
--
-- Do-no-harm guarantees:
--   * Only adds a table + a nullable column; touches no existing column/row data
--     except to backfill the new cohort_id.
--   * Existing users are assigned to a default cohort whose current_week is
--     copied from the CURRENT global settings.current_week, so the per-user read
--     path (Phase 1) computes an identical lecture filter for them — a no-op.
--   * No application code reads cohort_id yet at this phase.
--
-- Apply with utf8mb4 so the Hebrew name isn't mangled:
--   mysql -u isbot -p... isquestions_gamified --default-character-set=utf8mb4 < 2026-06-05_cohorts.sql

-- 1) cohorts table -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS cohorts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(64) NOT NULL,
    current_week        TINYINT NOT NULL DEFAULT 12,   -- 1..12, same semantics as settings.current_week
    color               VARCHAR(16) NULL,              -- optional leaderboard indicator
    active              TINYINT(1) NOT NULL DEFAULT 1, -- inactive cohorts hidden from the picker
    semester_start_date DATE NULL,                     -- reserved for future week auto-advance
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cohorts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) users.cohort_id (guarded — MySQL has no ADD COLUMN IF NOT EXISTS) --------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'cohort_id');
SET @ddl := IF(@col = 0,
               'ALTER TABLE users ADD COLUMN cohort_id INT NULL',
               'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) FK users.cohort_id -> cohorts.id (guarded) ------------------------------
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND constraint_name = 'fk_users_cohort');
SET @ddl := IF(@fk = 0,
               'ALTER TABLE users ADD CONSTRAINT fk_users_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL',
               'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) seed the default cohort from the current global week (only if missing) --
INSERT INTO cohorts (name, current_week, active)
SELECT 'סמסטר א 2026',
       COALESCE((SELECT CAST(setting_value AS UNSIGNED)
                   FROM settings WHERE setting_key = 'current_week' LIMIT 1), 12),
       1
WHERE NOT EXISTS (SELECT 1 FROM cohorts WHERE name = 'סמסטר א 2026');

-- 5) backfill: assign every existing user with no cohort to the default ------
UPDATE users
   SET cohort_id = (SELECT id FROM cohorts WHERE name = 'סמסטר א 2026' LIMIT 1)
 WHERE cohort_id IS NULL;

-- 6) VERIFY (run manually after applying) ------------------------------------
--   SELECT COUNT(*) AS unassigned FROM users WHERE cohort_id IS NULL;   -- expect 0
--   SELECT id, name, current_week, active FROM cohorts;                 -- default week == old global week
