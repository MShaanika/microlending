-- Creditinfo Namibia Public Defaults module -- business workflow, database
-- structure, permissions, and integration placeholders for Public Default
-- Listing/Removal (Individual), per the signed Creditinfo Statement of Work
-- (CBS Report Plus + Public Defaults, N$15.60/transaction excl. VAT).
--
-- Architecturally separate from the existing CBS credit-check module
-- (creditinfo_module.sql) -- no CBS table is touched here. Shared
-- infrastructure (users, permissions, roles, the approval engine) is
-- referenced, never duplicated.
--
-- Creditinfo Public Defaults API mapping pending vendor documentation.
-- Every column below is an internal DesertLedger field, not a Creditinfo
-- API field -- none of this schema should be treated as, or mapped
-- directly onto, the eventual vendor request/response shape.

-- Borrower-level Creditinfo bureau dispute flag. Nothing in DesertLedger
-- tracked this before Public Defaults needed it: the Subscriber Agreement
-- requires the subscriber not to penalise a consumer while a dispute is
-- under investigation, so a listing must be hard-blocked while one is
-- open. Deliberately its own small table (not a borrowers.* column) so a
-- borrower can have a history of multiple disputes over time.
CREATE TABLE creditinfo_disputes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    reference VARCHAR(100) NULL,
    status ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
    opened_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    notes TEXT NULL,
    recorded_by INT NOT NULL,
    resolved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    INDEX idx_creditinfo_disputes_borrower (borrower_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key-value settings, identical shape to creditinfo_settings (CBS) and
-- collexia_settings -- covers eligibility config (item 5), integration
-- settings (item 14), and tariff config (item 15) in one place since all
-- three are simple admin-editable values. A separate table from
-- creditinfo_settings on purpose -- Public Defaults must never be
-- silently switched on by a CBS settings change.
CREATE TABLE creditinfo_public_default_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per default record, covering its FULL lifecycle (listing then,
-- later, removal) -- not one row per workflow stage. The status enum
-- therefore merges item 2's listing states and item 3's removal states
-- into a single unambiguous vocabulary (removal states are prefixed
-- "Removal ..." so "Failed" can never be misread as which stage failed).
--
-- Creditinfo Public Defaults API mapping pending vendor documentation --
-- api_status/api_reference/api_last_error are DesertLedger's own tracking
-- fields, not vendor field names.
CREATE TABLE creditinfo_public_defaults (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    listing_reference VARCHAR(50) NOT NULL UNIQUE,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    loan_application_id BIGINT NULL,
    branch_id INT NULL,
    creditinfo_reference VARCHAR(100) NULL,
    default_type VARCHAR(50) NOT NULL DEFAULT 'Individual',
    outstanding_amount DECIMAL(18,2) NOT NULL,
    original_amount DECIMAL(18,2) NOT NULL,
    default_date DATE NOT NULL,
    days_in_arrears_at_listing INT NULL,
    listing_reason TEXT NOT NULL,
    status ENUM(
        'Draft','Pending Review','Approved','Rejected',
        'Awaiting API Submission','Submitted','Listed','Failed','Cancelled',
        'Removal Required','Removal Pending Review','Removal Approved','Removal Rejected',
        'Awaiting API Removal','Removal Submitted','Removed','Removal Failed'
    ) NOT NULL DEFAULT 'Draft',
    listing_requested_by INT NOT NULL,
    listing_requested_at DATETIME NOT NULL,
    listing_approved_by INT NULL,
    listing_approved_at DATETIME NULL,
    listed_at DATETIME NULL,
    removal_requested_by INT NULL,
    removal_requested_at DATETIME NULL,
    removal_approved_by INT NULL,
    removal_approved_at DATETIME NULL,
    removed_at DATETIME NULL,
    removal_reason TEXT NULL,
    removal_reason_category ENUM(
        'Loan fully settled','Written settlement agreement completed','Listing made in error',
        'Duplicate listing','Dispute resolved in borrower favour','Compliance instruction',
        'Creditinfo instruction','Other authorised reason'
    ) NULL,
    removal_trigger_source VARCHAR(50) NULL,
    api_status VARCHAR(50) NULL,
    api_reference VARCHAR(100) NULL,
    api_last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (loan_application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (listing_requested_by) REFERENCES users(id),
    FOREIGN KEY (listing_approved_by) REFERENCES users(id),
    FOREIGN KEY (removal_requested_by) REFERENCES users(id),
    FOREIGN KEY (removal_approved_by) REFERENCES users(id),
    INDEX idx_cpd_status (status),
    INDEX idx_cpd_borrower (borrower_id),
    INDEX idx_cpd_loan (loan_id),
    INDEX idx_cpd_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Borrower notice/evidence before listing (item 8). Its own table, not
-- columns on creditinfo_public_defaults, so more than one notice attempt
-- can be recorded against the same default. notice_document stores a
-- relative storage path only (same convention as expenses/letters
-- attachments) -- never the file content inline.
CREATE TABLE creditinfo_public_default_notices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    public_default_id BIGINT NOT NULL,
    notice_required TINYINT(1) NOT NULL DEFAULT 1,
    notice_date DATE NULL,
    notice_method ENUM('SMS','Email','Letter','WhatsApp','Hand Delivered','Other') NULL,
    notice_reference VARCHAR(150) NULL,
    notice_document VARCHAR(255) NULL,
    notice_sent_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (public_default_id) REFERENCES creditinfo_public_defaults(id) ON DELETE CASCADE,
    FOREIGN KEY (notice_sent_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Status-transition/action history for one default -- the record's own
-- timeline (item 9's "AUDIT TRAIL" section on the detail screen), distinct
-- from the global audit_logs table which also gets an entry per action
-- (see Audit::log() calls throughout the controller) for the
-- compliance-wide view. Mirrors the existing approval_actions pattern.
CREATE TABLE creditinfo_public_default_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    public_default_id BIGINT NOT NULL,
    action VARCHAR(50) NOT NULL,
    actor_user_id INT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NULL,
    reason TEXT NULL,
    api_reference VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (public_default_id) REFERENCES creditinfo_public_defaults(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    INDEX idx_cpd_actions_default (public_default_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Diagnostic placeholder log (item 4 + item 13). Every row here represents
-- a BLOCKED submission attempt -- PublicDefaultsGateway always throws
-- before any network call is made, so this table can never contain a real
-- Creditinfo API request/response. Never stores credentials, tokens, or
-- full National IDs, matching CreditinfoDiagnosticLog's (CBS) discipline.
CREATE TABLE creditinfo_public_default_api_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    public_default_id BIGINT NULL,
    triggered_by INT NULL,
    action_attempted ENUM('list_individual','remove_individual') NOT NULL,
    environment ENUM('uat','production') NOT NULL DEFAULT 'uat',
    blocked_reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (public_default_id) REFERENCES creditinfo_public_defaults(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions (item 7 + item 6's dispute control).
INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('creditinfo.public_defaults.view', 'View Public Defaults', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.create_listing', 'Create Public Default Listing Request', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.approve_listing', 'Approve Public Default Listing Request', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.create_removal', 'Create Public Default Removal Request', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.approve_removal', 'Approve Public Default Removal Request', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.submit', 'Submit Public Default to Creditinfo', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.view_audit', 'View Public Defaults Audit Trail', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.settings', 'Manage Public Defaults Settings', 'Creditinfo Public Defaults'),
('creditinfo.disputes.manage', 'Manage Creditinfo Disputes', 'Creditinfo Public Defaults');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.view'
  AND r.role_name IN ('Super Admin', 'Admin', 'Manager', 'Collector');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('creditinfo.public_defaults.create_listing', 'creditinfo.public_defaults.create_removal')
  AND r.role_name IN ('Manager', 'Collector');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('creditinfo.public_defaults.approve_listing', 'creditinfo.public_defaults.approve_removal')
  AND r.role_name IN ('Super Admin', 'Admin', 'Manager');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.submit'
  AND r.role_name IN ('Super Admin', 'Admin');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.view_audit'
  AND r.role_name IN ('Super Admin', 'Admin', 'Manager');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.settings'
  AND r.role_name IN ('Super Admin', 'Admin');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.disputes.manage'
  AND r.role_name IN ('Super Admin', 'Admin', 'Manager');

-- Maker-checker (item 7): reuses the existing generic Approval Engine
-- (approval_policies/approval_requests/approval_steps/approval_actions --
-- see database/approval_engine_module.sql) instead of a bespoke
-- creditinfo_public_default_approvals table. ApprovalService enforces
-- maker != checker unconditionally, server-side, with no configuration to
-- turn that off -- exactly item 7's "must not approve their own request"
-- and "enforce separation server-side, not only in the UI." Unlike the
-- write-off policy (which is an optional staged-rollout enhancement), the
-- Public Defaults controller treats a missing/inactive policy as a hard
-- configuration error and refuses to proceed rather than silently falling
-- back to single-permission approval -- dual control here is mandatory,
-- not optional.
INSERT INTO approval_policies (policy_key, policy_name, description, module, resource_type, action_type, approver_permission, required_steps, is_active) VALUES
('public_default_listing_approval', 'Public Default Listing Approval', 'A requested Creditinfo Public Default listing must be approved by someone other than the person who requested it before it can proceed to submission.', 'Creditinfo', 'public_default_listing', 'approve', 'creditinfo.public_defaults.approve_listing', 1, 1),
('public_default_removal_approval', 'Public Default Removal Approval', 'A requested Creditinfo Public Default removal must be approved by someone other than the person who requested it before it can proceed to submission.', 'Creditinfo', 'public_default_removal', 'approve', 'creditinfo.public_defaults.approve_removal', 1, 1);

-- Settings seed (item 14 + item 5 + item 15). Everything that is genuine
-- vendor/compliance uncertainty starts blank/off/"Documentation Required" --
-- never guessed. The two tariff figures and the VAT rate are NOT guesses:
-- N$15.60 per transaction (excl. VAT) for both Listing and Removal comes
-- directly from the signed Creditinfo Statement of Work quoted to build
-- this module, and 15% is Namibia's statutory VAT rate (public tax law,
-- not a vendor assumption) -- both are still stored as editable settings,
-- never hardcoded in code, so a tariff change is a settings update, not a
-- deploy. allow_listing_during_dispute is NOT a setting at all -- item 6
-- requires it as a hard, unconditional control, so it is enforced in code
-- with no toggle to weaken it until a formally configured compliance
-- override process exists.
INSERT INTO creditinfo_public_default_settings (setting_key, setting_value) VALUES
('public_defaults_enabled', 'off'),
('api_status', 'Documentation Required'),
('listing_endpoint', ''),
('removal_endpoint', ''),
('api_version', ''),
('vendor_documentation_received', 'no'),
('vendor_mapping_confirmed', 'no'),
('minimum_days_in_arrears', ''),
('minimum_outstanding_balance', ''),
('borrower_notice_required', 'yes'),
('notice_waiting_period_days', ''),
('listing_fee', '15.60'),
('removal_fee', '15.60'),
('vat_rate', '15'),
('tariff_effective_date', CURDATE());
