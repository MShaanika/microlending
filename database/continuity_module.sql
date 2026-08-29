-- Enterprise Control Architecture -- Phase 7: Business Continuity & DR.
--
-- Closes the honesty gap Phase 5's HealthCheckService::checkBackup()
-- deliberately left open: "no in-app backup automation exists yet".
-- backup_runs is what BackupService writes to and what checkBackup()
-- now reads from -- append-only history, same convention as
-- health_check_results/security_events.
CREATE TABLE backup_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    backup_type VARCHAR(30) NOT NULL DEFAULT 'database',
    file_path VARCHAR(500) NULL,
    file_size_bytes BIGINT NULL,
    status ENUM('RUNNING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'RUNNING',
    triggered_by ENUM('scheduled', 'manual') NOT NULL DEFAULT 'scheduled',
    triggered_by_user INT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    duration_seconds INT NULL,
    error_message TEXT NULL,
    retention_expires_at DATETIME NULL,
    FOREIGN KEY (triggered_by_user) REFERENCES users(id),
    INDEX idx_backup_status_time (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recovery Time/Point Objectives are business decisions this app must
-- never guess (same principle as sla_policies' zero-seeded durations)
-- -- zero plans seeded. rto_minutes/rpo_minutes/recovery_steps stay
-- NULL until an administrator actually defines them for a given
-- scope; the dashboard shows that honestly rather than implying a
-- plan exists.
CREATE TABLE continuity_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(150) NOT NULL,
    scope_description VARCHAR(255) NOT NULL,
    rto_minutes INT NULL,
    rpo_minutes INT NULL,
    recovery_steps TEXT NULL,
    key_contacts TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_reviewed_at DATETIME NULL,
    last_reviewed_by INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (last_reviewed_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('continuity_module', 'enterprise-control-phase-7');

-- How many days of local backup files/history to keep before
-- BackupService prunes them -- an operational disk-space setting, not
-- a business/compliance retention policy (those live in
-- retention_policies, Phase 4), so a reasonable default is safe here
-- the same way error_display_mode's default was: it's disclosed and
-- adjustable, never a silent policy decision.
INSERT IGNORE INTO system_settings (setting_key, setting_value, module_name) VALUES
('backup_retention_days', '30', 'Continuity');
