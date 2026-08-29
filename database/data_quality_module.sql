-- Enterprise Control Architecture -- Phase 4: Data Governance (Data
-- Quality Management).
--
-- Rule TYPES are code-defined (App\Services\DataQualityService), same
-- pattern as SecurityRuleEngine and the SLA engine -- each concrete
-- check is a real, reviewed SQL query against this app's actual
-- schema, not a generic rule interpreter. What's admin-configurable
-- per row is severity/active/auto-exception/ownership, matching the
-- Security Rules page's edit-only precedent. Four rules are seeded,
-- each one a real gap or invariant the Phase 0 audit actually found or
-- confirmed -- not a guessed-at example (Part 92).
CREATE TABLE data_quality_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_key VARCHAR(100) NOT NULL UNIQUE,
    rule_name VARCHAR(150) NOT NULL,
    description TEXT,
    dimension ENUM('Completeness','Validity','Consistency','Uniqueness','Integrity','Timeliness','Accuracy') NOT NULL,
    module VARCHAR(60) NOT NULL,
    severity ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
    remediation_guidance TEXT,
    owner_user_id INT NULL,
    auto_create_exception TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    last_run_at DATETIME NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per detected instance -- re-checked on every scan (see
-- DataQualityService::scan()) so an issue whose underlying condition is
-- genuinely corrected resolves itself; nothing here ever writes back to
-- the record being checked (Part 33, Part 99: "Do NOT automatically
-- change balance... correction follows the existing authorized
-- financial workflow").
CREATE TABLE data_quality_issues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    correlation_id VARCHAR(40) NULL,
    resource_type VARCHAR(60) NOT NULL,
    resource_id BIGINT NOT NULL,
    description TEXT NOT NULL,
    status ENUM('OPEN','REVIEWING','CONFIRMED','FALSE_POSITIVE','RESOLVED') DEFAULT 'OPEN',
    exception_id BIGINT NULL,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    resolution_notes TEXT NULL,
    FOREIGN KEY (rule_id) REFERENCES data_quality_rules(id),
    FOREIGN KEY (exception_id) REFERENCES exceptions(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    UNIQUE KEY uq_dq_open_issue (rule_id, resource_type, resource_id),
    INDEX idx_dq_issue_status (status),
    INDEX idx_dq_issue_rule (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('data_quality_module', 'enterprise-control-phase-4');

INSERT INTO data_quality_rules (rule_key, rule_name, description, dimension, module, severity, remediation_guidance, auto_create_exception, is_active) VALUES
('unbalanced_journal', 'Unbalanced Journal Entry', 'A posted journal whose debit and credit lines do not sum to the same total. AccountingJournal::post() validates this at the application layer, but nothing enforces it at the database level -- a direct write or a future bug could still produce one.', 'Integrity', 'Accounting', 'Critical', 'Review the journal in General Ledger; correct via a reversing/adjusting entry through the normal accounting workflow. Never edit posted journal lines directly.', 1, 1),
('completed_loan_with_balance', 'Completed Loan With Outstanding Balance', 'A loan marked Completed still has one or more unpaid installment rows on its schedule -- these should be mutually exclusive states.', 'Consistency', 'Loans', 'High', 'Investigate the loan''s schedule and payment history; correct the loan status or the schedule through the normal loan workflow, not by editing the database directly.', 1, 1),
('borrower_missing_national_id', 'Borrower Missing National ID', 'An approved borrower has no ID/passport number on file.', 'Completeness', 'Borrowers', 'Medium', 'Request and capture the borrower''s ID document via Borrower > Documents.', 0, 1),
('negative_loan_principal', 'Loan With Zero or Negative Principal', 'A loan''s principal amount is zero or negative -- not a valid loan amount.', 'Validity', 'Loans', 'High', 'Investigate how this loan was created; correct via the loan edit workflow if the loan is still in Draft/Pending Approval.', 0, 1),
('duplicate_borrower_phone', 'Duplicate Borrower Phone Number', 'Two or more active borrowers share the exact same phone number -- a deterministic (exact-match, not fuzzy) signal of a possible duplicate client record. Part 32: potential duplicates are surfaced for review here, never auto-merged.', 'Uniqueness', 'Borrowers', 'Medium', 'Review both borrower records under Borrowers; if genuinely the same person, follow the borrower merge/consolidation process rather than editing records directly.', 0, 1);
