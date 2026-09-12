-- CPL monthly batch/snapshot/maker-checker architecture (items 4, 10-15).
-- One row per prepared monthly submission attempt (cpl_batches), each
-- holding one immutable snapshot per reportable loan as at that month end
-- (cpl_monthly_snapshots) -- never recalculated from today's live loan
-- balance once taken, so an already-submitted month can always be
-- reproduced exactly as it was reported.

CREATE TABLE cpl_batches (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_type ENUM('Monthly', 'Daily') NOT NULL DEFAULT 'Monthly',
    month_end DATE NOT NULL,
    status ENUM('Draft', 'Ready for Review', 'Pending Approval', 'Approved', 'Rejected', 'Submitted') NOT NULL DEFAULT 'Draft',
    supplier_reference_number VARCHAR(10) NULL,
    total_records INT NOT NULL DEFAULT 0,
    blocking_error_count INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    generated_by INT NULL,
    generated_at DATETIME NULL,
    approval_request_id BIGINT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NULL,
    rejection_reason TEXT NULL,
    -- Populated only once Approved -- the actual CPLv1.1 fixed-width text,
    -- frozen at approval time so a later data change can never silently
    -- alter what was reported for a month already approved.
    file_content LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_batch_type_month (batch_type, month_end),
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (rejected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cpl_monthly_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    month_end DATE NOT NULL,
    -- The full CplExporter::buildFields() output for this loan, frozen at
    -- snapshot time -- the record is rebuilt from this JSON via
    -- CplRecordBuilder, never re-derived from live loan/borrower data once
    -- taken (see item 10: "do not recalculate an old submitted month from
    -- today's loan balance").
    field_data JSON NOT NULL,
    validation_status ENUM('Pending', 'Valid', 'Warning', 'Blocking Error') NOT NULL DEFAULT 'Pending',
    validation_messages JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_batch_loan (batch_id, loan_id),
    FOREIGN KEY (batch_id) REFERENCES cpl_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    INDEX idx_cpl_snap_status (batch_id, validation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Submission History skeleton (item 19) -- one row per actual file handed
-- to the bureaus for a batch (a resubmission after correction is a second
-- row against the same batch, never an overwrite of the first).
CREATE TABLE cpl_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    sequence_number INT NOT NULL DEFAULT 1,
    file_name VARCHAR(150) NOT NULL,
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL,
    outcome ENUM('Awaiting Load Report', 'Accepted', 'Rejected', 'Partially Rejected') NOT NULL DEFAULT 'Awaiting Load Report',
    load_report_received_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES cpl_batches(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maker-checker for the actual submission decision (item 16) -- reuses the
-- same generic ApprovalService/approval_policies engine as Public Defaults
-- listing/removal approval; maker != checker is enforced there
-- unconditionally, server-side.
INSERT INTO approval_policies (policy_key, policy_name, description, module, resource_type, action_type, approver_permission, required_steps, is_active) VALUES
('cpl_batch_approval', 'CPL Monthly Batch Approval', 'A prepared CPL monthly batch must be approved by someone other than the person who prepared it before the extract file can be generated and downloaded.', 'CPL', 'cpl_batch', 'approve', 'reports.cpl_approve', 1, 1);

INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'reports.cpl_approve', 'Approve CPL Batch Submission', 'Reports'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'reports.cpl_approve');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name = 'Super Admin' AND p.permission_key = 'reports.cpl_approve'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
