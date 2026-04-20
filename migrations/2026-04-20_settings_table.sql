-- Phase C: student-facing lecture filter
-- Creates the settings table and seeds current_week = 3 (L1-L3 exposed)

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (setting_key, setting_value)
VALUES ('current_week', '3')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
