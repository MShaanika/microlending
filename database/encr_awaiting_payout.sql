-- Collexia EnCr payout: the loan WAITS until Collexia confirms the money left.
-- Idempotent: safe to run more than once. RUN THIS BEFORE deploying the code.
--
-- Until this has run, the "Pay out through Collexia EnCr" option is not offered on the Release
-- form at all (CollexiaEncrDisbursementService::schemaReady() checks for it), so a loan is always
-- released the usual, manual way.
--
-- What changes:
--   loans.loan_status gains 'Awaiting Payout'. A loan released with an EnCr payout sits here until
--     Collexia reports the payout Completed. Nothing is posted to the books while it waits, and every
--     report / arrears / penalty / debit order / CPL query already lists the statuses it counts
--     ('Active','Current','Released', ...), so a waiting loan is left out of all of them. Only on
--     Completed is the loan released for real (journal + cash book, status Active).
--   loan_disbursements.encr_maybe_received = 1 when the last attempt to send the payout got no clear
--     answer (network failure or a Collexia server error): the money MIGHT still leave, so the loan
--     can then not be paid another way or cancelled until a retry or a status check gives a clear answer.
--
-- PREREQUISITE MIGRATIONS (must already have been run):
--   loan_status_dimensions.sql        the current loans.loan_status list (+ payment_status, credit_status)
--   collexia_encr_disbursement.sql    the loan_disbursements.encr_* columns
-- The check below fails LOUDLY ("Unknown column ...") when one of them has not been run, before anything is changed.

-- ---- prerequisite check: every statement must succeed, none returns a row ----
SELECT loan_status, payment_status, aging_bucket, collection_status, credit_status FROM loans LIMIT 0;
SELECT encr_reference_no, encr_state, encr_status_code, encr_response_code, encr_response_description, encr_amount_paid_out,
       encr_submitted_at, encr_checked_at, encr_last_message FROM loan_disbursements LIMIT 0;

-- 1. The waiting status, placed right after 'Approved'. Every existing value is kept (a value in use that is
--    missing here would make this statement fail under strict mode rather than silently change a loan).
ALTER TABLE loans
    MODIFY loan_status ENUM('Draft','Pending Approval','Approved','Awaiting Payout','Released','Active','Current','Completed','Denied','Written Off','Cancelled','Recovered - Closed') DEFAULT 'Draft';

-- 2. "The last send may have reached Collexia" flag (added only if missing; works on MySQL and MariaDB).
SET @encr_col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loan_disbursements' AND COLUMN_NAME = 'encr_maybe_received');
SET @encr_sql := IF(@encr_col_exists = 0,
    'ALTER TABLE loan_disbursements ADD COLUMN encr_maybe_received TINYINT(1) NOT NULL DEFAULT 0 AFTER encr_state',
    'SELECT 1');
PREPARE encr_stmt FROM @encr_sql;
EXECUTE encr_stmt;
DEALLOCATE PREPARE encr_stmt;

-- ---- after-check: both must succeed ----
SELECT encr_maybe_received FROM loan_disbursements LIMIT 0;
SELECT COUNT(*) AS awaiting_payout_loans FROM loans WHERE loan_status = 'Awaiting Payout';
