-- Creditinfo Public Defaults: submission FILE generation.
--
-- Creditinfo confirmed (2026-09-30) the full code tables and filename
-- convention for the pipe-delimited 36-column submission file (legend:
-- storage/documentation/Creditinfo_Public_Defaults_Legend_2026-09-30.txt,
-- sample: ..._Sample_2026-09-30.txt) -- see
-- App\Services\CreditinfoPublicDefaultFileService for the field-by-field
-- mapping. This migration adds only what's needed to RENDER that file from
-- existing creditinfo_public_defaults rows and to run a maker-checker
-- review on the generated file before it is downloaded for a real upload.
--
-- Still does NOT add an SFTP client or make any live call -- Creditinfo's
-- own SFTP endpoint is only ever touched by a human, outside this app,
-- after downloading the file this migration's tables support generating.
--
-- Applied to production 2026-10-07, after this exact file/supplier-reference
-- bug was reported by Creditinfo and fixed.

-- 1. DEFAULT STATUS CODE (legend column 14) has no existing DesertLedger
-- equivalent -- Creditinfo's 8 categories (Absconded/Collection/
-- Delinquent/HandedOver/NotContactable/Paid/SlowPayer/WriteOff) don't map
-- onto listing_reason (freeform) or removal_reason_category (a different,
-- DesertLedger-internal vocabulary). Captured explicitly by staff at
-- listing time and re-confirmed at removal time (the true final status,
-- e.g. "Paid", is often only known once removal is requested) -- see
-- CreditinfoPublicDefaultController::listingStore()/removalStore().
ALTER TABLE creditinfo_public_defaults
    ADD COLUMN default_status_category ENUM(
        'Absconded','Collection','Delinquent','HandedOver','NotContactable','Paid','SlowPayer','WriteOff'
    ) NULL AFTER listing_reason;

-- 2. One row per generated submission file. A file is a BATCH of many
-- public defaults at once (Creditinfo's format has no concept of "one
-- file per borrower"), so this is deliberately a new table rather than a
-- column on creditinfo_public_defaults. environment mirrors the CBS
-- module's UAT/Production split -- a 'live' batch can only be generated
-- once creditinfo_public_default_settings.submission_environment is
-- explicitly switched to 'live' (see CreditinfoPublicDefaultBatchService);
-- the column still exists here so a 'test' batch can never be confused
-- with a 'live' one after the fact.
CREATE TABLE creditinfo_public_default_batches (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_reference VARCHAR(50) NOT NULL UNIQUE,
    direction ENUM('listing','removal') NOT NULL,
    environment ENUM('test','live') NOT NULL DEFAULT 'test',
    filename VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    record_count INT NOT NULL DEFAULT 0,
    status ENUM('Pending Review','Approved','Rejected','Downloaded','Marked Submitted') NOT NULL DEFAULT 'Pending Review',
    generated_by INT NOT NULL,
    generated_at DATETIME NOT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_comments TEXT NULL,
    downloaded_by INT NULL,
    downloaded_at DATETIME NULL,
    marked_submitted_by INT NULL,
    marked_submitted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (downloaded_by) REFERENCES users(id),
    FOREIGN KEY (marked_submitted_by) REFERENCES users(id),
    INDEX idx_cpdb_status (direction, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Snapshot of every rendered line in a batch, one row per included
-- public default. rendered_line is stored verbatim (not regenerated on
-- demand) so the file that was actually reviewed/approved/downloaded stays
-- provable later even if the underlying borrower/loan record subsequently
-- changes -- an audit requirement for data submitted to a credit bureau.
CREATE TABLE creditinfo_public_default_batch_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    public_default_id BIGINT NOT NULL,
    line_number INT NOT NULL,
    rendered_line TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES creditinfo_public_default_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (public_default_id) REFERENCES creditinfo_public_defaults(id),
    UNIQUE KEY uniq_cpdbi_batch_default (batch_id, public_default_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Settings: the real Creditinfo-assigned Supplier Reference Number
-- (an earlier version of this migration seeded the literal placeholder
-- "SRN" here, which is the field's own abbreviation in Creditinfo's
-- legend, not a value, and is exactly the wrong filename Creditinfo
-- flagged on our first test submission; see
-- CreditinfoPublicDefaultSetting::supplierReferenceNumber()).
-- "NA02628" was Creditinfo's first answer (2026-10-07), then corrected
-- the next day to "NA02629" -- confirmed 2026-10-08 as the one real
-- Supplier Reference Number used across the CBS API, Public Defaults and
-- CPL alike (Creditinfo warned NA02628 would route data to a different
-- provider). See the environment gate below too. 'test' is the only
-- value that can ever be reached without an admin explicitly visiting
-- Settings and switching it -- mirrors the CBS module's UAT Test Centre
-- pattern (never silently default to live).
INSERT INTO creditinfo_public_default_settings (setting_key, setting_value) VALUES
('supplier_reference_number', 'NA02629'),
('submission_environment', 'test');

-- 5. Permissions: "generate_batch" (the maker -- builds the file from
-- eligible records) is deliberately separate from "approve_batch" (the
-- checker), reusing the existing generic Approval Engine exactly like
-- listing/removal approval already does, so maker != checker is enforced
-- server-side with no configuration to turn it off (see
-- database/creditinfo_public_defaults_module.sql's equivalent comment on
-- public_default_listing_approval). Both scoped to Super Admin/Admin only,
-- same as the existing "submit" permission -- generating and downloading a
-- real credit-bureau submission file is not a Manager/Collector action.
INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('creditinfo.public_defaults.generate_batch', 'Generate Public Defaults Submission File', 'Creditinfo Public Defaults'),
('creditinfo.public_defaults.approve_batch', 'Approve Public Defaults Submission File', 'Creditinfo Public Defaults');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.generate_batch'
  AND r.role_name IN ('Super Admin', 'Admin');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'creditinfo.public_defaults.approve_batch'
  AND r.role_name IN ('Super Admin', 'Admin');

INSERT INTO approval_policies (policy_key, policy_name, description, module, resource_type, action_type, approver_permission, required_steps, is_active) VALUES
('public_default_batch_approval', 'Public Default Submission Batch Approval', 'A generated Public Defaults submission file must be reviewed and approved by someone other than the person who generated it before it can be downloaded for upload to Creditinfo.', 'Creditinfo', 'public_default_batch', 'approve', 'creditinfo.public_defaults.approve_batch', 1, 1);
