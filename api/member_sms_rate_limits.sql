-- Run once on the same MySQL database used by member_accounts.
-- Tracks SMS send quotas across sessions to reduce abuse and SMS charges.
CREATE TABLE IF NOT EXISTS member_sms_send_limits (
    limit_key CHAR(64) NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_member_sms_limits_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Optional cleanup: DELETE FROM member_sms_send_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
