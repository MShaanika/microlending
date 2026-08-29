-- Enterprise Control Architecture -- Phase 2: Governance (Delegation &
-- Segregation of Duties).
--
-- Deliberately separate from Auth::startImpersonation() (the existing
-- "Login As" feature, a full session-identity swap for support use) --
-- a delegation never changes who you're logged in as. The delegate acts
-- as themselves; DelegationService checks delegation_scopes at the
-- moment they use a delegated permission, and every resulting audit
-- entry records "acting under delegated authority from <delegator>"
-- (Part 14), not a plain "Approved by <delegate>".
--
-- No org-hierarchy (reports_to) column exists in hrm_employees (see the
-- Phase 0 audit) and none is added here -- delegations are explicit
-- admin-configured delegator/delegate pairs, not inferred from a
-- manager relationship.
CREATE TABLE delegations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delegator_user_id INT NOT NULL,
    delegate_user_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason TEXT NULL,
    -- Display/audit status, kept current by bin/expire_delegations.php.
    -- NOT the source of truth for whether a delegation currently grants
    -- authority -- DelegationService checks starts_at/ends_at/status
    -- directly and in real time, so a delegation is correct even in the
    -- minutes before the daily sweep next runs (Part 14: "Automatically
    -- activate... Automatically expire").
    status ENUM('Scheduled','Active','Expired','Revoked') DEFAULT 'Scheduled',
    revoked_by INT NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delegator_user_id) REFERENCES users(id),
    FOREIGN KEY (delegate_user_id) REFERENCES users(id),
    FOREIGN KEY (revoked_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_deleg_delegate_status (delegate_user_id, status),
    INDEX idx_deleg_dates (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Never a copy of the delegator's whole role (Part 13) -- one row per
-- specific permission being handed over, each optionally narrowed by
-- module/amount/branch. A delegation with zero scope rows grants
-- nothing; DelegationService checks this table, never the delegator's
-- role wholesale.
CREATE TABLE delegation_scopes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delegation_id INT NOT NULL,
    permission_key VARCHAR(100) NOT NULL,
    module VARCHAR(60) NULL,
    amount_limit DECIMAL(18,2) NULL,
    branch_id INT NULL,
    FOREIGN KEY (delegation_id) REFERENCES delegations(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    INDEX idx_delegscope_deleg_perm (delegation_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Segregation of Duties -- configurable conflicting-permission pairs
-- (Part 79). Detection only, no pairs seeded (Part 92: don't invent
-- policy) -- an admin defines what actually conflicts for this
-- organization. Checked by SegregationOfDutyService, surfaced as a
-- warning when creating a delegation (a delegation is exactly the
-- moment a new conflict could be introduced).
CREATE TABLE segregation_of_duty_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(150) NOT NULL,
    description TEXT,
    permission_key_a VARCHAR(100) NOT NULL,
    permission_key_b VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('delegation_module', 'enterprise-control-phase-2');
