-- Creditinfo Public Defaults: manual-submission architecture correction.
--
-- Creditinfo has confirmed in writing: "We don't have an API for Public
-- Defaults, we have a user interface option and SFTP too." This migration
-- removes the REST-shaped status vocabulary built on that earlier (incorrect)
-- assumption and replaces it with the vendor-confirmed manual-submission
-- model: Public Default Workflow -> Maker-Checker Approval -> Submission
-- Method (1. Creditinfo SFTP, 2. Creditinfo User Interface).
--
-- NOT YET APPLIED TO PRODUCTION. Prepared per explicit instruction, pending
-- the user's review of the corresponding code changes. Do not run until
-- told to deploy.
--
-- Order matters: existing rows are remapped to the new status vocabulary
-- BEFORE the ENUM is narrowed, so no row is ever left holding a value the
-- new ENUM no longer accepts.

-- 1. Remap existing rows to the new status vocabulary.
UPDATE creditinfo_public_defaults SET status = 'Awaiting Manual Submission' WHERE status IN ('Approved', 'Awaiting API Submission', 'Failed');
UPDATE creditinfo_public_defaults SET status = 'Submitted via Creditinfo UI' WHERE status = 'Submitted';
UPDATE creditinfo_public_defaults SET status = 'Awaiting Manual Removal Submission' WHERE status IN ('Removal Approved', 'Awaiting API Removal', 'Removal Failed');
UPDATE creditinfo_public_defaults SET status = 'Removal Submitted via Creditinfo UI' WHERE status = 'Removal Submitted';

-- 2. Narrow the status ENUM to the vendor-confirmed manual-submission
-- vocabulary (13 values). api_status/api_reference/api_last_error columns
-- are left in place (no longer written to by new code) rather than dropped,
-- since they may still hold historical diagnostic values worth keeping.
ALTER TABLE creditinfo_public_defaults MODIFY COLUMN status ENUM(
    'Draft', 'Pending Review', 'Rejected',
    'Awaiting Manual Submission', 'Submitted via Creditinfo UI', 'Listed', 'Cancelled',
    'Removal Required', 'Removal Pending Review', 'Removal Rejected',
    'Awaiting Manual Removal Submission', 'Removal Submitted via Creditinfo UI', 'Removed'
) NOT NULL DEFAULT 'Draft';

-- 3. Evidence trail for each manual (or, once built, SFTP) submission event.
-- Separate from creditinfo_public_default_actions (the general status-
-- transition timeline) because a submission event carries specific evidence
-- fields (reference, document, notes, who/when) that don't belong on every
-- timeline row.
CREATE TABLE creditinfo_public_default_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    public_default_id BIGINT NOT NULL,
    direction ENUM('listing', 'removal') NOT NULL,
    method ENUM('manual_ui', 'sftp') NOT NULL DEFAULT 'manual_ui',
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL,
    creditinfo_reference VARCHAR(100) NULL,
    evidence_document VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (public_default_id) REFERENCES creditinfo_public_defaults(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    INDEX idx_cpds_default (public_default_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. New settings: submission method (fixed, informational) + SFTP
-- placeholders. Every SFTP value starts blank -- no endpoint, file layout,
-- folder path, filename convention, or transmission schedule is invented
-- here. sftp_secret is NOT seeded here -- it is written only through
-- CreditinfoPublicDefaultSetting::setEncryptedSftpSecret() (AES-256-GCM via
-- App\Core\Encryption), never as plaintext SQL.
INSERT INTO creditinfo_public_default_settings (setting_key, setting_value) VALUES
('submission_method', 'manual_ui'),
('sftp_enabled', 'off'),
('sftp_specification_status', 'AWAITING CREDITINFO DOCUMENTATION'),
('sftp_host', ''),
('sftp_port', ''),
('sftp_username', ''),
('sftp_auth_method', ''),
('sftp_outbound_directory', ''),
('sftp_inbound_directory', ''),
('sftp_archive_directory', ''),
('sftp_file_format', ''),
('sftp_file_naming_convention', ''),
('sftp_submission_schedule', '');
