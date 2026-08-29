-- Enterprise Control Architecture -- Phase 2: Governance (Approval Engine).
--
-- A generic maker-checker engine rather than separate approval logic
-- per module (Part 8). approval_policies.is_active is the staged-
-- rollout "off switch" (Part 41) -- disabling a policy does not block
-- the underlying workflow, it just removes the extra dual-control gate;
-- the calling module falls back to whatever single-permission check it
-- already had. This substitutes for a full Feature Flag system (not
-- built until Phase 5) without inventing one early.
--
-- No approval amount thresholds are seeded -- Part 92 forbids guessing
-- financial policy. The one policy this phase actually wires up (loan
-- write-offs) applies to every write-off regardless of amount; tiered
-- thresholds are a config change away once real limits are confirmed.

CREATE TABLE approval_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(100) NOT NULL UNIQUE,
    policy_name VARCHAR(150) NOT NULL,
    description TEXT,
    module VARCHAR(60) NOT NULL,
    resource_type VARCHAR(60) NOT NULL,
    action_type VARCHAR(60) NOT NULL,
    amount_min DECIMAL(18,2) NULL,
    amount_max DECIMAL(18,2) NULL,
    approver_permission VARCHAR(100) NOT NULL,
    required_steps INT NOT NULL DEFAULT 1,
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

CREATE TABLE approval_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    approval_uuid VARCHAR(36) NOT NULL UNIQUE,
    correlation_id VARCHAR(40) NULL,
    policy_id INT NOT NULL,
    module VARCHAR(60) NOT NULL,
    resource_type VARCHAR(60) NOT NULL,
    resource_id BIGINT NOT NULL,
    action_type VARCHAR(60) NOT NULL,
    maker_user_id INT NOT NULL,
    current_step INT NOT NULL DEFAULT 1,
    required_steps INT NOT NULL DEFAULT 1,
    status ENUM('PENDING','APPROVED','REJECTED','RETURNED','CANCELLED','EXPIRED') DEFAULT 'PENDING',
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(18,2) NULL,
    reason TEXT NULL,
    metadata JSON NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (policy_id) REFERENCES approval_policies(id),
    FOREIGN KEY (maker_user_id) REFERENCES users(id),
    INDEX idx_appreq_status (status),
    INDEX idx_appreq_resource (module, resource_type, resource_id),
    INDEX idx_appreq_maker (maker_user_id, status),
    INDEX idx_appreq_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE approval_steps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    approval_request_id BIGINT NOT NULL,
    step_number INT NOT NULL,
    approver_permission VARCHAR(100) NOT NULL,
    status ENUM('PENDING','APPROVED','REJECTED','RETURNED','SKIPPED') DEFAULT 'PENDING',
    acted_by INT NULL,
    acted_via_delegation_id INT NULL,
    acted_at TIMESTAMP NULL,
    comments TEXT NULL,
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (acted_by) REFERENCES users(id),
    INDEX idx_appstep_request (approval_request_id, step_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per state transition -- the request's own audit trail,
-- distinct from the global audit_logs (which also gets an entry per
-- action, for the compliance-wide view). This one is what the request
-- detail page's timeline reads.
CREATE TABLE approval_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    approval_request_id BIGINT NOT NULL,
    approval_step_id BIGINT NULL,
    action VARCHAR(30) NOT NULL,
    actor_user_id INT NULL,
    acted_via_delegation_id INT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (approval_step_id) REFERENCES approval_steps(id) ON DELETE SET NULL,
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    INDEX idx_appact_request (approval_request_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('approval_engine_module', 'enterprise-control-phase-2');

-- First real policy: loan write-off approval. Single step, applies to
-- every amount (no tier -- see header comment). approver_permission
-- reuses the module's existing accounting.writeoffs key rather than
-- minting a new one, since the existing permission already means
-- "can approve a write-off" -- what's new here is the maker != checker
-- enforcement, not a new permission surface.
INSERT INTO approval_policies (policy_key, policy_name, description, module, resource_type, action_type, approver_permission, required_steps, is_active) VALUES
('loan_write_off_approval', 'Loan Write-Off Approval', 'A requested loan write-off must be approved by someone other than the person who requested it before it can be posted.', 'Accounting', 'loan_write_off', 'approve', 'accounting.writeoffs', 1, 1);
