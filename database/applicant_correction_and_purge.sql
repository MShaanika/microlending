-- Applicant correction texts + 30-day clean-up of unapproved applications.
-- SAFE BY DEFAULT: the clean-up switch is OFF (application_purge_enabled = 0); with it off the cron only
-- reports what it WOULD do and sends no warnings. Run this BEFORE deploying the code. Idempotent.

-- 1. Staff action "Request correction" (texts the applicant what to fix).
INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'applications.request_correction', 'Request Application Correction (SMS)', 'Applications'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'applications.request_correction');

-- Everyone who can screen applications can request a correction.
INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, np.id
FROM role_permissions rp
JOIN permissions sp ON sp.id = rp.permission_id AND sp.permission_key = 'applications.screen'
JOIN permissions np ON np.permission_key = 'applications.request_correction'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.permission_id = np.id);

-- SMS_CORRECTION_CONTACT: the number applicants are told to call (063 234 341); no text is sent while it is blank.
INSERT INTO notification_settings (setting_key, setting_value, channel) VALUES
    ('SMS_CORRECTION_ENABLED', '1', 'SMS'),
    ('SMS_CORRECTION_CONTACT', '063 234 341', 'SMS'),
    ('SMS_CORRECTION_MAX_PER_APPLICATION', '3', 'SMS'),
    ('SMS_CORRECTION_COOLDOWN_HOURS', '24', 'SMS')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Staff may correct an open application's personal details (edit screen). Same roles that can screen.
INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'applications.edit', 'Edit Application Details', 'Applications'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'applications.edit');

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, np.id
FROM role_permissions rp
JOIN permissions sp ON sp.id = rp.permission_id AND sp.permission_key = 'applications.screen'
JOIN permissions np ON np.permission_key = 'applications.edit'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.permission_id = np.id);

-- 2. Clean-up of unapproved applications.
--   purge_warned_at : when staff were told this application will be deleted (deletion waits at least warning_days after this)
--   purged_at       : set on a "tombstone" (personal data wiped, row kept because a Creditinfo consent/report record must never be deleted)
ALTER TABLE loan_applications
    ADD COLUMN IF NOT EXISTS purge_warned_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS purged_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS idx_applications_purge (status, purge_warned_at, purged_at);

INSERT INTO system_settings (setting_key, setting_value, module_name) VALUES
    ('application_purge_enabled', '0', 'Retention'),
    ('application_retention_days', '30', 'Retention'),
    ('application_purge_warning_days', '7', 'Retention'),
    ('application_purge_max_per_run', '50', 'Retention'),
    ('application_purge_quarantine_days', '14', 'Retention')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
