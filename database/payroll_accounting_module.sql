-- Payroll -> Accounting integration.
--
-- Two accounts payroll needs that don't exist yet: a liability to hold
-- statutory/other deductions withheld from staff (owed to a third
-- party, e.g. PAYE/pension -- hrm_deduction_types isn't typed by
-- category, so this is one combined payable rather than split per
-- deduction type), and the staff-loan-receivable asset that 0%-interest
-- staff loan repayments deducted in payroll reduce (StaffLoanRepayment
-- itself has never posted to the GL until now). Codes 1080/2060
-- confirmed unused across every other accounting_accounts seed file.
INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type, afs_line_code, normal_balance, is_control_account, is_cash_bank_account, is_active) VALUES
('1080', 'Staff Loan Receivable', 'Asset', 'bs_receivables_prepayments', 'Debit', 1, 0, 1),
('2060', 'Payroll Deductions Payable', 'Liability', 'bs_accounts_payable', 'Credit', 1, 0, 1);

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('payroll_accounting_module', 'production-readiness-followup');
