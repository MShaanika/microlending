-- Enterprise Control Architecture -- Phase 3: Operational Control (SLA
-- & Escalation).
--
-- No durations are seeded here (Part 16/92: the master prompt's own
-- worked examples -- "2 hours", "1 business day" -- are explicitly
-- illustrative, not real business policy to hardcode). sla_policies
-- starts empty; an administrator creates real policies with real
-- durations through the SLA Policies admin page. The engine itself is
-- fully built and wired -- see App\Services\SlaService and its
-- integration into ApprovalService::request()/approve()/reject().
CREATE TABLE sla_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(100) NOT NULL UNIQUE,
    policy_name VARCHAR(150) NOT NULL,
    description TEXT,
    module VARCHAR(60) NOT NULL,
    resource_type VARCHAR(60) NOT NULL,
    duration_minutes INT NOT NULL,
    business_hours_aware TINYINT(1) DEFAULT 0,
    -- % of duration elapsed before an instance shows amber ("at risk")
    -- instead of green -- a technical display threshold, not a business
    -- policy; still admin-editable per policy.
    at_risk_threshold_percent INT NOT NULL DEFAULT 75,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sla_instances (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    correlation_id VARCHAR(40) NULL,
    resource_type VARCHAR(60) NOT NULL,
    resource_id BIGINT NOT NULL,
    owner_user_id INT NULL,
    status ENUM('ON_TRACK','AT_RISK','BREACHED','PAUSED','COMPLETED','CANCELLED') DEFAULT 'ON_TRACK',
    started_at DATETIME NOT NULL,
    due_at DATETIME NOT NULL,
    paused_at DATETIME NULL,
    paused_minutes_total INT NOT NULL DEFAULT 0,
    escalation_level INT NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (policy_id) REFERENCES sla_policies(id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id),
    INDEX idx_sla_inst_status (status),
    INDEX idx_sla_inst_resource (resource_type, resource_id),
    INDEX idx_sla_inst_due (due_at),
    INDEX idx_sla_inst_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Timeline for one instance -- what My Work / an instance detail view
-- reads. ESCALATED rows double as the "has this threshold already
-- fired" dedup check (Part 21: no repeat-notification storms) --
-- App\Services\EscalationService queries this table for an existing
-- ESCALATED row at a given threshold_percent before firing again.
CREATE TABLE sla_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sla_instance_id BIGINT NOT NULL,
    event_type ENUM('STARTED','PAUSED','RESUMED','AT_RISK','BREACHED','ESCALATED','COMPLETED','CANCELLED') NOT NULL,
    threshold_percent INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sla_instance_id) REFERENCES sla_instances(id) ON DELETE CASCADE,
    INDEX idx_sla_events_instance (sla_instance_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Escalation RULES per policy (Part 20) -- e.g. "80% consumed -> remind
-- owner". No rows seeded, same reasoning as sla_policies: the exact
-- percentages and actions are a policy decision, not a default this
-- app should invent.
CREATE TABLE sla_escalations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    threshold_percent INT NOT NULL,
    action ENUM('REMIND_OWNER','NOTIFY_SUPERVISOR','ESCALATE_MANAGER','CREATE_EXCEPTION') NOT NULL,
    notify_permission VARCHAR(100) NULL,
    exception_severity ENUM('Low','Medium','High','Critical') NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (policy_id) REFERENCES sla_policies(id) ON DELETE CASCADE,
    INDEX idx_sla_esc_policy (policy_id, threshold_percent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('sla_module', 'enterprise-control-phase-3');

-- One SLA-related setting, matching the DB-backed-settings convention
-- used throughout this app (SystemSetting) rather than a config file.
INSERT IGNORE INTO system_settings (setting_key, setting_value, module_name) VALUES
('business_hours_start', '08:00', 'Operations'),
('business_hours_end', '17:00', 'Operations');
