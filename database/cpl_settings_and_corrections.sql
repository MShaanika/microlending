-- CPLv1.1 configuration table -- see App\Models\CplSetting and
-- App\Services\CplExporter. Persists the Supplier Reference Number, trading
-- name, recipient, version, submission environment, agreed billing date /
-- deadline rule, and the configurable one-month/multi-month account-type
-- code mapping, so staff no longer re-type the Supplier Reference Number
-- into a GET parameter on every export.
CREATE TABLE cpl_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every value starts blank/off/documented-default -- nothing here is a
-- guess at a real Supplier Reference Number, which must come from
-- Creditinfo. account_type_one_month/account_type_multi_month default to
-- the spec's own M/P codes (CPLv1-1.pdf p.10) but stay editable in case the
-- bureau specifies something else for this book; account_type_mapping_confirmed
-- starts 'no' so the export screen keeps warning until that mapping is
-- actually confirmed in writing.
INSERT INTO cpl_settings (setting_key, setting_value) VALUES
('supplier_reference_number', ''),
('trading_name', 'Solid Desert Cash Loan'),
('recipient', 'ALL'),
('cpl_version', '06'),
('submission_environment', 'uat'),
('agreed_billing_date', ''),
('monthly_submission_deadline_rule', '5 working days after the agreed billing date'),
('account_type_one_month', 'M'),
('account_type_multi_month', 'P'),
('account_type_mapping_confirmed', 'no');

-- CPL field 46 (Home Telephone) had no source column anywhere -- borrowers
-- only ever captured a mobile number. Genuinely new, not a duplicate of any
-- existing column.
ALTER TABLE borrowers
    ADD COLUMN home_telephone VARCHAR(20) NULL AFTER phone;

-- Fixes a live mismatch: the 'occupation' intake field has always been
-- mapped to extra_data.occupation, but ApplicationController::approve()
-- (and therefore borrower_employment.job_title, and therefore CPL field 52)
-- reads extra_data.job_title -- meaning occupation captured on the online
-- application form was silently never reaching the borrower record. Retarget
-- the existing mapping rather than duplicate it.
UPDATE intake_field_mappings
SET target_field = 'extra:job_title'
WHERE incoming_field_name = 'occupation'
  AND target_field = 'extra:occupation'
  AND intake_source_id = (SELECT id FROM intake_sources WHERE source_code = 'solid-desert');
