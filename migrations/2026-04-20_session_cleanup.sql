-- Session-bounded question visibility: when a user returns after a gap, the
-- question messages from their prior session are removed from the chat.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_interaction_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS session_question_messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT       NOT NULL,
    message_id  BIGINT       NOT NULL,
    sent_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cleaned     TINYINT(1)   NOT NULL DEFAULT 0,
    INDEX idx_user_cleaned (user_id, cleaned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (setting_key, setting_value)
VALUES ('session_gap_minutes', '30')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
