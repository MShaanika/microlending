-- Cyber Security Monitoring & Incident Response -- Phase 1 Foundation.
--
-- login_logs (schema.sql, ~line 278) is superseded by security_events, not
-- repurposed -- see the comment added next to its CREATE TABLE. It is left
-- in place, untouched, still unused.
--
-- security_events: narrow, purpose-built signal table for the rules engine
-- (rolling-window lookups by IP/login), distinct from audit_logs (by-user/
-- module compliance history). See app/Core/SecurityEvent.php.
CREATE TABLE security_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(60) NOT NULL,
    severity ENUM('Info','Low','Medium','High','Critical') DEFAULT 'Info',
    user_id INT NULL,
    attempted_login VARCHAR(150) NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    request_path VARCHAR(255) NULL,
    description TEXT,
    metadata JSON NULL,
    risk_score SMALLINT DEFAULT 0,
    rule_id INT NULL,
    incident_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_sec_ev_type_time (event_type, created_at),
    INDEX idx_sec_ev_ip_time (ip_address, created_at),
    INDEX idx_sec_ev_user_time (user_id, created_at),
    INDEX idx_sec_ev_login_time (attempted_login, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- security_rules: admin-editable thresholds/severity/response for a small,
-- code-defined set of rule TYPES (rule authoring UI is Phase 2+). Every
-- rule that fires creates/appends an incident; response_action is only the
-- optional hard-block on top of that.
CREATE TABLE security_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_key VARCHAR(100) NOT NULL UNIQUE,
    rule_name VARCHAR(150) NOT NULL,
    description TEXT,
    event_type VARCHAR(60) NOT NULL,
    scope ENUM('ip','account','ip_distinct_accounts') NOT NULL,
    threshold_count INT NOT NULL,
    window_minutes INT NOT NULL,
    severity ENUM('Low','Medium','High','Critical') NOT NULL,
    risk_score_delta SMALLINT NOT NULL DEFAULT 0,
    response_action ENUM('none','rate_limit_source','lock_account') NOT NULL DEFAULT 'none',
    response_duration_minutes INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_triggered_at DATETIME NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- security_incidents: correlation target. incident_key = rule_key . '|' .
-- scope_value, no time-bucketing -- one sustained attack stays one open
-- incident until a human resolves it.
CREATE TABLE security_incidents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    incident_key VARCHAR(150) NOT NULL,
    title VARCHAR(255) NOT NULL,
    status ENUM('Open','Investigating','Contained','Resolved','False Positive','Closed') DEFAULT 'Open',
    severity ENUM('Low','Medium','High','Critical') NOT NULL,
    source_ip VARCHAR(50) NULL,
    source_login VARCHAR(150) NULL,
    user_id INT NULL,
    rule_id INT NULL,
    event_count INT DEFAULT 1,
    first_event_at DATETIME NOT NULL,
    last_event_at DATETIME NOT NULL,
    assigned_to INT NULL,
    resolution_notes TEXT NULL,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rule_id) REFERENCES security_rules(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    INDEX idx_incident_key_status (incident_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Now that security_incidents exists, add the two FKs security_events was
-- missing (rule_id/incident_id) -- split out from the CREATE TABLE above
-- since security_rules/security_incidents didn't exist yet at that point.
ALTER TABLE security_events
    ADD FOREIGN KEY (rule_id) REFERENCES security_rules(id),
    ADD FOREIGN KEY (incident_id) REFERENCES security_incidents(id);

-- security_blocked_sources: unified IP-scope / account-scope temporary
-- block, checked at the top of Auth::attempt(). Super Admin/Admin/
-- bypass_ip_restriction are exempt, mirroring Auth::ipAllowed()'s existing
-- exemption -- an admin must never be lockable out of fixing a false
-- positive.
CREATE TABLE security_blocked_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    block_type ENUM('ip','account') NOT NULL,
    block_value VARCHAR(150) NOT NULL,
    reason VARCHAR(255),
    rule_id INT NULL,
    incident_id BIGINT NULL,
    blocked_by INT NULL,
    blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    status ENUM('Active','Expired','Lifted') DEFAULT 'Active',
    lifted_by INT NULL,
    lifted_at DATETIME NULL,
    lift_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_block (block_type, block_value, status),
    FOREIGN KEY (rule_id) REFERENCES security_rules(id),
    FOREIGN KEY (incident_id) REFERENCES security_incidents(id),
    FOREIGN KEY (blocked_by) REFERENCES users(id),
    FOREIGN KEY (lifted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeded rules -- thresholds/severity/response are admin-editable via the
-- Security Rules page; rule *types* (the code that evaluates each one)
-- stay code-defined in Phase 1.
INSERT INTO security_rules (rule_key, rule_name, description, event_type, scope, threshold_count, window_minutes, severity, risk_score_delta, response_action, response_duration_minutes) VALUES
('login_failed_burst_per_ip', 'Failed Login Burst (Source)', 'Many failed login attempts from one source in a short window -- likely password guessing or a brute-force script.', 'LOGIN_FAILED', 'ip', 10, 15, 'High', 20, 'rate_limit_source', 15),
('login_failed_burst_per_account', 'Failed Login Burst (Account)', 'Many failed login attempts against one account in a short window.', 'LOGIN_FAILED', 'account', 5, 15, 'Medium', 15, 'lock_account', 15),
('multi_account_single_source', 'Multiple Accounts From One Source', 'One source attempting several different usernames in a short window -- a signature of credential stuffing.', 'LOGIN_FAILED', 'ip_distinct_accounts', 5, 15, 'High', 30, 'rate_limit_source', 30),
('permission_denied_burst', 'Repeated Authorization Failures', 'One account repeatedly denied access to something it is not permitted to do -- may indicate probing for unauthorized functionality.', 'PERMISSION_DENIED', 'account', 5, 10, 'Medium', 10, 'none', NULL);

-- Permissions -- module_name 'Security' matches Audit::log()'s existing
-- use of that literal string for Login/Logout/Impersonate events.
INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('security.view', 'View Security Dashboard', 'Security'),
('security.incidents.manage', 'Manage Security Incidents', 'Security'),
('security.rules.manage', 'Manage Security Rules', 'Security'),
('security.blocks.manage', 'Manage Blocked Sources', 'Security');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'security.view' AND r.role_name IN ('Super Admin', 'Admin', 'Manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('security.incidents.manage', 'security.rules.manage', 'security.blocks.manage')
  AND r.role_name IN ('Super Admin', 'Admin');

-- Alert-recipient email for High/Critical security notifications, admin-
-- editable from the Security Rules page. Blank by default -- notifications
-- are still recorded in notification_queue either way, just not addressed
-- anywhere until this is set.
INSERT IGNORE INTO system_settings (setting_key, setting_value, module_name) VALUES
('security_alert_recipient_email', '', 'Security');
