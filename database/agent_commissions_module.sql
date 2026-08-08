-- =========================================================
-- MARKETING AGENT REFERRAL COMMISSIONS
--
-- Commission is 33.33% of a loan's INTEREST (not principal, not
-- total repayable) per the client's explicit rule, and is not a
-- single lump-sum trigger: it accrues proportionally as each
-- installment's interest is actually collected (agent_commissions
-- .earned_amount grows with every payment via AgentCommissionService),
-- and any not-yet-earned portion is permanently forfeited if the
-- loan is written off. See agent_commission_entries for the full
-- audit trail (one row per Earned/Payout/Forfeiture event), mirroring
-- how loan_application_status_history/payment_allocations already
-- pair a running-totals header table with a append-only ledger
-- elsewhere in this schema.
--
-- Deliberately NOT wired into hrm_payroll_entries/PayrollService --
-- per explicit client decision, payout is a standalone ledger the
-- owner marks paid manually, not part of a payroll run.
--
-- Run AFTER database/hrm_module.sql has been imported (FKs to
-- hrm_employees) and AFTER database/schema.sql (FKs to loans,
-- loan_applications, borrowers, payments, companies).
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Attribution columns
-- ---------------------------------------------------------

ALTER TABLE hrm_employees
    ADD COLUMN is_commission_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN referral_code VARCHAR(20) NULL UNIQUE AFTER is_commission_agent;

-- Set at intake (referral link resolution or agent portal submission) --
-- see ApplicationIntakeController and AgentSelfServiceController.
ALTER TABLE loan_applications
    ADD COLUMN agent_id INT NULL AFTER intake_source_id,
    ADD CONSTRAINT fk_loan_applications_agent FOREIGN KEY (agent_id) REFERENCES hrm_employees(id);

-- Copied from loan_applications.agent_id when a loan is created from
-- that application (LoanController::store()) -- only the loan tied to
-- the introducing application auto-carries the agent; a later top-up/
-- repeat loan for the same borrower does not auto-attribute.
ALTER TABLE loans
    ADD COLUMN agent_id INT NULL AFTER created_by,
    ADD CONSTRAINT fk_loans_agent FOREIGN KEY (agent_id) REFERENCES hrm_employees(id);

-- Single global commission rate, same "one settings row on the
-- company table" convention already used for payroll's working_days.
ALTER TABLE companies
    ADD COLUMN commission_rate_percent DECIMAL(6,4) NOT NULL DEFAULT 33.33;

-- ---------------------------------------------------------
-- Commission ledger
-- ---------------------------------------------------------

DROP TABLE IF EXISTS agent_commission_entries;
DROP TABLE IF EXISTS agent_commissions;

CREATE TABLE agent_commissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    agent_employee_id INT NOT NULL,
    borrower_id BIGINT NOT NULL,
    total_interest_amount DECIMAL(18,2) NOT NULL,
    commission_rate DECIMAL(6,4) NOT NULL,
    total_commission_amount DECIMAL(18,2) NOT NULL,
    earned_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    forfeited_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('Accruing','Fully Earned','Forfeited') NOT NULL DEFAULT 'Accruing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_loan_commission (loan_id),
    INDEX idx_agent_commissions_agent (agent_employee_id),
    INDEX idx_agent_commissions_status (status),
    CONSTRAINT fk_agent_commissions_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
    CONSTRAINT fk_agent_commissions_agent FOREIGN KEY (agent_employee_id) REFERENCES hrm_employees(id),
    CONSTRAINT fk_agent_commissions_borrower FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agent_commission_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    agent_commission_id BIGINT NOT NULL,
    payment_id BIGINT NULL,
    bank_account_id BIGINT NULL,
    journal_id BIGINT NULL,
    entry_type ENUM('Earned','Payout','Forfeiture') NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_commission_entries_commission (agent_commission_id),
    INDEX idx_commission_entries_payment (payment_id),
    CONSTRAINT fk_commission_entries_commission FOREIGN KEY (agent_commission_id) REFERENCES agent_commissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_entries_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_commission_entries_bank_account FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
    CONSTRAINT fk_commission_entries_journal FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    CONSTRAINT fk_commission_entries_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Commission payout accounting link
--
-- markPaid() now posts a real journal entry (Dr this expense account /
-- Cr whichever bank account staff pick) instead of just logging a
-- payout note -- see CommissionController::markPaid(). For an install
-- that already ran the CREATE TABLE above (bank_account_id/journal_id
-- didn't exist yet), apply this separately instead of re-running the
-- whole file (which would DROP and recreate agent_commissions/
-- agent_commission_entries and lose existing data):
--
--   ALTER TABLE agent_commission_entries
--       ADD COLUMN bank_account_id BIGINT NULL AFTER payment_id,
--       ADD COLUMN journal_id BIGINT NULL AFTER bank_account_id,
--       ADD CONSTRAINT fk_commission_entries_bank_account FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
--       ADD CONSTRAINT fk_commission_entries_journal FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id);
--
--   INSERT INTO accounting_accounts (account_code, account_name, account_type, afs_line_code, normal_balance, is_control_account, is_cash_bank_account, is_active)
--   VALUES ('5310', 'Agent Commission Expense', 'Expense', 'pl_opex_general', 'Debit', 0, 0, 1);
-- ---------------------------------------------------------

INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type, afs_line_code, normal_balance, is_control_account, is_cash_bank_account, is_active)
VALUES ('5310', 'Agent Commission Expense', 'Expense', 'pl_opex_general', 'Debit', 0, 0, 1);

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------

INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('commissions.manage', 'Manage Agent Commissions (view, approve payouts)', 'Commissions'),
('referrals.submit', 'Submit Marketing Agent Referrals', 'Commissions');

-- referrals.submit is intentionally not granted here -- the Marketing
-- Agent role doesn't exist yet; it's created via the Roles UI (same as
-- the HR/Developer roles earlier this session) and granted this
-- permission from the Permissions UI at that point.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name IN ('Super Admin', 'Admin') AND p.permission_key = 'commissions.manage';

SET FOREIGN_KEY_CHECKS = 1;
