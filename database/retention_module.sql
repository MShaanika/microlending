-- Enterprise Control Architecture -- Phase 4: Data Governance
-- (Retention & Archiving).
--
-- No retention periods are invented here (Part 92: "DO NOT invent
-- legal retention periods"). Two policies are seeded because they
-- generalize behavior that already existed or was already a confirmed
-- gap, not because a real number was guessed:
--   - form_drafts: already had a real, admin-configurable retention
--     value (system_settings.draft_retention_days, default 14) doing
--     exactly this job via bin/sweep_draft_expiry.php -- this policy
--     row documents that existing, already-approved behavior inside
--     the new framework rather than replacing it.
--   - idempotency_keys: the Phase 0 audit found this table already has
--     an expires_at column that NOTHING sweeps -- unbounded growth is a
--     real, pre-existing gap this phase closes, using the same TTL the
--     table already computes for itself (idempotency_ttl_hours), not a
--     new invented number.
-- Every other category (audit logs, security logs, financial records,
-- HR records, documents) needs a real retention period from the
-- business/compliance side before a policy is created for it --
-- flagged explicitly, not guessed.
CREATE TABLE retention_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(100) NOT NULL UNIQUE,
    policy_name VARCHAR(150) NOT NULL,
    data_category VARCHAR(100) NOT NULL,
    resource_table VARCHAR(100) NOT NULL,
    date_column VARCHAR(100) NOT NULL,
    -- AGE_FROM_DATE_COLUMN: eligible once date_column + retention_days
    -- has passed (the typical case -- e.g. created_at + 365 days).
    -- DATE_COLUMN_IS_EXPIRY: date_column already IS the row's computed
    -- expiry (form_drafts.expires_at, idempotency_keys.expires_at are
    -- both set per-row at creation from their own existing TTL
    -- settings) -- eligible once date_column < NOW(), retention_days
    -- unused/0 for these.
    comparison_mode ENUM('AGE_FROM_DATE_COLUMN','DATE_COLUMN_IS_EXPIRY') NOT NULL DEFAULT 'AGE_FROM_DATE_COLUMN',
    retention_days INT NOT NULL DEFAULT 0,
    legal_hold_supported TINYINT(1) DEFAULT 0,
    requires_legal_confirmation TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    policy_version INT NOT NULL DEFAULT 1,
    effective_from DATE NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A hold overrides a policy's delete-eligibility for one specific
-- record without editing the policy itself (Part 47). Only meaningful
-- for policies with legal_hold_supported = 1.
CREATE TABLE legal_holds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_table VARCHAR(100) NOT NULL,
    resource_id BIGINT NOT NULL,
    reason TEXT NOT NULL,
    placed_by INT NOT NULL,
    placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_by INT NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (placed_by) REFERENCES users(id),
    FOREIGN KEY (released_by) REFERENCES users(id),
    INDEX idx_legalhold_resource (resource_table, resource_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Execution report per Part 48 -- every retention run (dry-run or real)
-- leaves a record of what it found/did, independent of the
-- storage/logs/*.log file the cron's own stdout goes to.
CREATE TABLE retention_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    dry_run TINYINT(1) NOT NULL,
    eligible_count INT NOT NULL DEFAULT 0,
    held_count INT NOT NULL DEFAULT 0,
    deleted_count INT NOT NULL DEFAULT 0,
    ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ran_by INT NULL,
    FOREIGN KEY (policy_id) REFERENCES retention_policies(id),
    FOREIGN KEY (ran_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('retention_module', 'enterprise-control-phase-4');

INSERT INTO retention_policies (policy_key, policy_name, data_category, resource_table, date_column, comparison_mode, retention_days, legal_hold_supported, requires_legal_confirmation, is_active) VALUES
('form_drafts_expiry', 'Draft Auto-Save Expiry', 'Drafts', 'form_drafts', 'expires_at', 'DATE_COLUMN_IS_EXPIRY', 0, 0, 0, 1),
('idempotency_keys_expiry', 'Idempotency Key Expiry', 'Application Logs', 'idempotency_keys', 'expires_at', 'DATE_COLUMN_IS_EXPIRY', 0, 0, 0, 1);
