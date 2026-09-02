-- Fields required by the CPLv1.1 (Credit Providers Layout) monthly credit
-- bureau extract -- see app/Services/CplExporter.php and CPLv1-1.pdf.
-- physical_address/postal_address are left in place (unused by the new
-- exporter) so nothing else reading them breaks; the structured lines
-- below are what the CPL export and the borrower form now use.

ALTER TABLE borrowers
    ADD COLUMN title VARCHAR(5) NULL AFTER last_name,
    ADD COLUMN ownership_type ENUM('00','01','02') NOT NULL DEFAULT '00' AFTER nationality,
    ADD COLUMN residential_ownership ENUM('O','T') NULL AFTER ownership_type,
    ADD COLUMN residential_line1 VARCHAR(25) NULL AFTER physical_address,
    ADD COLUMN residential_line2 VARCHAR(25) NULL AFTER residential_line1,
    ADD COLUMN residential_line3 VARCHAR(25) NULL AFTER residential_line2,
    ADD COLUMN residential_line4 VARCHAR(25) NULL AFTER residential_line3,
    ADD COLUMN residential_postal_code VARCHAR(6) NULL AFTER residential_line4,
    ADD COLUMN postal_line1 VARCHAR(25) NULL AFTER postal_address,
    ADD COLUMN postal_line2 VARCHAR(25) NULL AFTER postal_line1,
    ADD COLUMN postal_line3 VARCHAR(25) NULL AFTER postal_line2,
    ADD COLUMN postal_line4 VARCHAR(25) NULL AFTER postal_line3,
    ADD COLUMN postal_postal_code VARCHAR(6) NULL AFTER postal_line4;

-- Lives alongside gross_salary/net_salary, the other income figures.
ALTER TABLE borrower_employment
    ADD COLUMN income_frequency ENUM('M','W','F','Q','A') NULL AFTER net_salary;

-- CPL field 26, mandatory for account type P -- see the Loan Reason Code
-- table (CPLv1-1.pdf p.203). Defaults to Other so existing loan-creation
-- flows are never blocked; selectable on the loan create form.
ALTER TABLE loans
    ADD COLUMN loan_reason_code ENUM('C','D','E','G','I','H','S','F','R','O','J') NOT NULL DEFAULT 'O' AFTER purpose;

-- Tracks the last CPL status code actually submitted per loan, so a
-- status is sent once in the month it occurs and omitted from every
-- subsequent monthly run unless it changes -- see
-- CplExporter::statusCode() and the spec's "Status Code Process Rules".
CREATE TABLE cpl_status_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    status_code CHAR(1) NOT NULL,
    status_date DATE NOT NULL,
    submitted_month_end DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    UNIQUE KEY uniq_loan_last_status (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permission for the new /reports/cpl-export screen -- see
-- App\Controllers\CplExportController. Super Admin is granted every
-- permission automatically, mirroring schema.sql's own
-- "CROSS JOIN permissions WHERE role_name = 'Super Admin'" seed.
INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'reports.cpl_export', 'Export Credit Bureau (CPL) Data', 'Reports'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'reports.cpl_export');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name = 'Super Admin' AND p.permission_key = 'reports.cpl_export'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
