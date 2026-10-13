-- Employment Contract Templates: a single reusable, real .docx template
-- (storage/employment_contract_templates/) with ${PLACEHOLDER} tokens,
-- filled in automatically per employee using App\Services\
-- EmploymentContractService / PhpWord's TemplateProcessor -- same proven
-- mechanism App\Services\DocumentGenerationService already uses for
-- Loan Letters, just a dedicated, simpler table/model since employee
-- contracts have nothing to do with loans/borrowers.
--
-- Section 4 (Duties) is the one clause that genuinely differs by role, not
-- a fill-in-the-blank -- resolved automatically from the employee's own
-- hrm_designations.standard_duties, so generating a contract only ever
-- needs the term dates and contract date as real manual input.

ALTER TABLE hrm_designations
    ADD COLUMN standard_duties TEXT NULL AFTER department_id;

CREATE TABLE employment_contract_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per actually-generated contract -- an audit/history trail per
-- employee (same "what was actually sent" principle as
-- creditinfo_public_default_batch_items storing rendered_line verbatim):
-- position/salary/duties are snapshotted at generation time via
-- position_at_time/salary_at_time/duties_at_time, so a later change to
-- the employee's designation or salary never rewrites history.
CREATE TABLE employee_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    template_id INT NOT NULL,
    contract_date DATE NOT NULL,
    term_start_date DATE NOT NULL,
    term_end_date DATE NOT NULL,
    position_at_time VARCHAR(150) NOT NULL,
    salary_at_time DECIMAL(12,2) NOT NULL,
    duties_at_time TEXT NULL,
    file_path VARCHAR(255) NOT NULL,
    generated_by INT NULL,
    generated_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id),
    FOREIGN KEY (template_id) REFERENCES employment_contract_templates(id),
    FOREIGN KEY (generated_by) REFERENCES users(id),
    INDEX idx_ec_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
