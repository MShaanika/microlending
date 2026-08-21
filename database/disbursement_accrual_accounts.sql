-- Full-accrual-at-disbursement fix: interest, NAMFISA levy, and duty stamp
-- are now booked as receivables at disbursement (against a matching
-- deferred/payable credit), instead of interest being recognized only on
-- collection and levy/stamp being lumped into Loans Receivable.
--
-- 1050 (Provision for Doubtful Debts) and 2010 (Accounts Payable) already
-- exist for other purposes, so the new NAMFISA Levy Receivable and
-- Deferred Interest Income accounts use the next free codes (1051, 2011)
-- instead. 1030 Interest Receivable already existed but was unused --
-- tagging it here alongside the two new accounts.

INSERT INTO accounting_accounts (account_code, account_name, account_type, afs_line_code, normal_balance, is_control_account, is_cash_bank_account, is_active) VALUES
('1051', 'NAMFISA Levy Receivable', 'Asset', 'bs_receivables_prepayments', 'Debit', 1, 0, 1),
('1060', 'Stamp Duty Receivable', 'Asset', 'bs_receivables_prepayments', 'Debit', 1, 0, 1),
('2011', 'Deferred Interest Income', 'Liability', 'bs_accounts_payable', 'Credit', 0, 0, 1);

UPDATE accounting_accounts SET afs_line_code = 'bs_receivables_prepayments' WHERE account_code = '1030' AND afs_line_code IS NULL;
