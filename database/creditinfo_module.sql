-- Creditinfo Namibia CBS REST API integration: credentials settings,
-- consent evidence, and a per-check audit/content split so substantive
-- report data can be purged later while the audit trail survives.
-- Modeled directly on collexia_v3_ui.sql's shape (same key-value
-- settings table pattern) -- additive only.

CREATE TABLE creditinfo_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per real consent capture -- who/when/how/which application.
-- A credit check can never run without an unconsumed row here; never a
-- hardcoded "consent": true.
CREATE TABLE credit_bureau_consents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    borrower_id BIGINT NULL,
    consent_given TINYINT(1) NOT NULL DEFAULT 1,
    consent_method VARCHAR(50) NOT NULL,
    consent_reference VARCHAR(150) NULL,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL,
    ip_address VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permanent, audit-minimal fields only -- never substantive report
-- content (see creditinfo_report_content below). Split into two tables
-- deliberately: RetentionService only knows how to delete a whole row,
-- so purging the substantive part while keeping this audit trail needs
-- the substantive part in a table of its own.
CREATE TABLE creditinfo_report_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    borrower_id BIGINT NULL,
    consent_id BIGINT NOT NULL,
    requested_by INT NOT NULL,
    national_id_used VARCHAR(50) NOT NULL,
    is_uat_test_id TINYINT(1) NOT NULL DEFAULT 0,
    environment ENUM('uat','production') NOT NULL DEFAULT 'uat',
    inquiry_reason_search VARCHAR(100) NULL,
    inquiry_reason_report VARCHAR(20) NULL,
    workflow_id VARCHAR(100) NULL,
    request_id VARCHAR(100) NULL,
    subject_token VARCHAR(255) NULL,
    creditinfo_id VARCHAR(100) NULL,
    search_outcome ENUM('Pending','SubjectFound','SubjectNotFound','Error','Timeout') NOT NULL DEFAULT 'Pending',
    report_status ENUM('Unknown','New','InProgress','Finished','Error','Rejected','Timeout') NOT NULL DEFAULT 'Unknown',
    decision_at DATETIME NULL,
    purged_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (consent_id) REFERENCES credit_bureau_consents(id),
    FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The substantive, purgeable half. report_content_encrypted/
-- pdf_content_encrypted are App\Core\Encryption::encrypt() output (the
-- same class CollexiaSetting already uses for its own secrets) -- AES-
-- 256-GCM at rest, on top of being deleted outright once the retention
-- policy below is confirmed and activated.
CREATE TABLE creditinfo_report_content (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_cache_id BIGINT NOT NULL UNIQUE,
    report_token VARCHAR(255) NULL,
    pdf_token VARCHAR(255) NULL,
    report_content_encrypted LONGTEXT NULL,
    pdf_content_encrypted LONGTEXT NULL,
    decision_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_cache_id) REFERENCES creditinfo_report_cache(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Summary-only columns on the application itself, mirroring the
-- existing screened_by/screened_at convention already on this table.
-- No report content lives here -- see creditinfo_report_content.
ALTER TABLE loan_applications
    ADD COLUMN credit_check_status ENUM('Not Checked','Pending','Completed','Error') NOT NULL DEFAULT 'Not Checked' AFTER extra_data,
    ADD COLUMN credit_check_outcome VARCHAR(50) NULL AFTER credit_check_status,
    ADD COLUMN credit_checked_by INT NULL AFTER credit_check_outcome,
    ADD COLUMN credit_checked_at DATETIME NULL AFTER credit_checked_by,
    ADD COLUMN credit_bureau_reference VARCHAR(100) NULL AFTER credit_checked_at,
    ADD CONSTRAINT fk_loan_applications_credit_checked_by FOREIGN KEY (credit_checked_by) REFERENCES users(id);

INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('applications.credit_check', 'Run Credit Bureau Check', 'Applications');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'applications.credit_check'
  AND r.role_name IN ('Super Admin', 'Admin', 'Manager', 'Loan Officer');

-- Retention: seeded now but deliberately inactive and flagged, per this
-- project's own established rule (retention_module.sql: "DO NOT invent
-- legal retention periods"). requires_legal_confirmation=1 surfaces a
-- "Needs confirmation" badge on the existing Retention admin screen
-- (app/Views/retention/index.php.content) immediately -- an admin sets
-- the real retention_days and activates it there once confirmed, no
-- further deploy needed. is_active=0 means RetentionService will not
-- purge anything against a guessed number.
INSERT INTO retention_policies (policy_key, policy_name, data_category, resource_table, date_column, comparison_mode, retention_days, legal_hold_supported, requires_legal_confirmation, is_active) VALUES
('creditinfo_report_content_purge', 'Credit Bureau Report Content Purge', 'Credit Bureau Data', 'creditinfo_report_content', 'decision_at', 'AGE_FROM_DATE_COLUMN', 0, 1, 1, 0);
