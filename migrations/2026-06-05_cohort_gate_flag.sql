-- Feature: Cohorts / Groups — Phase 4 onboarding-gate kill switch.
-- Seeds settings.cohort_gate_enabled = 0 (OFF). While 0, checkCohortRequired()
-- is a no-op and no user is ever shown the mandatory group picker. Flip to 1
-- to turn the gate ON; set back to 0 to disable instantly with no deploy.
-- Idempotent: re-running keeps whatever value is already there.

INSERT INTO settings (setting_key, setting_value)
VALUES ('cohort_gate_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
