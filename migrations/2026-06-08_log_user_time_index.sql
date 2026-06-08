-- Index for admin/abuse.php (offline scraping/farming detection, FR-DET-*).
-- The detection scan orders the log by (userid, timestamp) over a recent window;
-- this composite index makes that scan cheap as the log grows.
--
-- MySQL has no `CREATE INDEX IF NOT EXISTS`, so guard via information_schema to
-- keep the migration idempotent (safe to re-run).

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'log'
      AND index_name   = 'idx_log_user_time'
);

SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE log ADD INDEX idx_log_user_time (userid, timestamp)',
    'SELECT ''idx_log_user_time already present'' AS note');

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
