-- The log table is an append-only audit trail. Its foreign key on userid →
-- users.id was blocking writeLog() any time it ran before the matching user
-- row existed (most notably writeLog(6) at the top of /start, which fired
-- before the new-user INSERT into users). PHP threw a fatal mysqli exception,
-- the request died, and the new user was never registered.
--
-- Audit logs should never reject writes for referential reasons. Drop the FK.
-- Keep the index so JOINs to users stay fast.

ALTER TABLE log DROP FOREIGN KEY fk_user_log;
-- (idx_user_log / fk_user_log key on userid is auto-kept; verify with SHOW INDEX FROM log)
