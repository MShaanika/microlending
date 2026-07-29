-- =========================================================
-- HRM MODULE -- PHASE 6: Staff Loans
-- Named StaffLoan/StaffLoanType (not HrmLoan) throughout the
-- codebase (models/controllers) per explicit decision -- this
-- system's core domain already has Loan/LoanApplication for
-- loans made TO CUSTOMERS, and a staff benefit loan must never
-- be confusable with that. Table names stay hrm_-prefixed for
-- schema-organization consistency with the rest of this module;
-- there's no collision risk either way since "loans" (bare) is
-- the customer-facing table.
--
-- Deliberately more rigorous than the reference workdo/Hrm
-- module, which has no interest, no installment schedule, and
-- no repayment ledger at all (a "loan" there is just a
-- recurring fixed/percentage payroll deduction active between
-- two dates, with no balance tracking or completion signal).
-- Per explicit decision: 0% interest, automatic payroll
-- deduction only (no manual repayment recording), principal
-- split into equal installments and tracked to a real
-- completed balance via hrm_staff_loan_repayments.
--
-- Run AFTER database/hrm_payroll_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_staff_loan_repayments;
DROP TABLE IF EXISTS hrm_staff_loans;
DROP TABLE IF EXISTS hrm_staff_loan_types;

CREATE TABLE hrm_staff_loan_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- installment_amount = principal_amount / number_of_installments
-- (0% interest, computed once at creation and stored). Each
-- eligible payroll run deducts MIN(installment_amount,
-- outstanding_balance) -- the final installment self-corrects
-- for any rounding remainder so the loan always reaches exactly
-- zero. status becomes 'Active' only on HR approval (financially
-- significant -- gate it, don't auto-deduct on creation) and
-- flips to 'Completed' automatically once outstanding_balance
-- hits zero.
-- ---------------------------------------------------------
CREATE TABLE hrm_staff_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    staff_loan_type_id INT NULL,
    title VARCHAR(200) NOT NULL,
    principal_amount DECIMAL(12,2) NOT NULL,
    number_of_installments INT NOT NULL,
    installment_amount DECIMAL(12,2) NOT NULL,
    outstanding_balance DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    reason TEXT NULL,
    status ENUM('Pending','Active','Completed','Cancelled','Rejected') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_loan_type_id) REFERENCES hrm_staff_loan_types(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- One row per loan per payroll run it was deducted in -- the
-- repayment ledger. UNIQUE(staff_loan_id, payroll_id) makes a
-- payroll re-run idempotent at the loan level too (mirrors
-- hrm_payroll_entries' own per-employee uniqueness).
-- ---------------------------------------------------------
CREATE TABLE hrm_staff_loan_repayments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    staff_loan_id INT NOT NULL,
    payroll_id INT NULL,
    payroll_entry_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL,
    repayment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_loan_payroll (staff_loan_id, payroll_id),
    FOREIGN KEY (staff_loan_id) REFERENCES hrm_staff_loans(id) ON DELETE CASCADE,
    FOREIGN KEY (payroll_id) REFERENCES hrm_payrolls(id) ON DELETE SET NULL,
    FOREIGN KEY (payroll_entry_id) REFERENCES hrm_payroll_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hrm_payroll_entries
    ADD COLUMN total_staff_loans DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_deductions,
    ADD COLUMN staff_loans_breakdown TEXT NULL AFTER deductions_breakdown;

SET FOREIGN_KEY_CHECKS = 1;
