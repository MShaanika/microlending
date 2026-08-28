-- Splits the single loan_status column into independent dimensions, per the
-- "Status Flow and Accounting Integration Guide" spec: Lifecycle status
-- keeps answering "is the loan still open/closed", while payment_status,
-- aging_bucket, collection_status, and credit_status now each get their own
-- stored column instead of being inferred live on every read.
--
-- Recovery status is deliberately NOT duplicated here -- bad_debts.status
-- (Provisioned/Written Off/Under Recovery/Recovered) already tracks it as a
-- real, event-driven column; ArrearsService/Loan model map it to the spec's
-- vocabulary at read time.
--
-- Also widens bad_debts.aging_bucket (was locked to the OLD 6-bucket scheme)
-- to the new 5-bucket scheme, since BadDebtProvisionController writes
-- ArrearsService's bucket label straight into it -- without this, the first
-- provisioning run after ArrearsService::agingBucket() changes would fail.
--
-- See app/Services/ArrearsService.php::refreshLoanStatus() for what writes
-- these columns, and app/Controllers/LoanStatusBackfillController.php for
-- the one-time historical backfill (must run before the real-time hooks are
-- enabled -- see that controller's docblock).

ALTER TABLE loans
    ADD COLUMN payment_status ENUM('Current','In Arrears') NOT NULL DEFAULT 'Current' AFTER loan_status,
    ADD COLUMN aging_bucket ENUM('Current','1-29','30-59','60-89','90+') NOT NULL DEFAULT 'Current' AFTER payment_status,
    ADD COLUMN collection_status ENUM('Normal Collection','Arrears Recovery','Recovery Arrangement') NOT NULL DEFAULT 'Normal Collection' AFTER aging_bucket,
    ADD COLUMN credit_status ENUM('Performing','Watchlist','Non-Performing','Impaired') NOT NULL DEFAULT 'Performing' AFTER collection_status,
    MODIFY loan_status ENUM('Draft','Pending Approval','Approved','Released','Active','Current','Completed','Denied','Written Off','Cancelled','Recovered - Closed') DEFAULT 'Draft';

-- Widen first (old + new labels both valid) so existing rows survive the
-- remap, then remap their data, then narrow to just the new scheme. Doing
-- the MODIFY straight to the new 3-value ENUM in one step would silently
-- blank out (or reject, under strict mode) any existing row still holding
-- an old-scheme label like '31-60' -- this table backs live bad debt
-- records, not a throwaway/empty one.
ALTER TABLE bad_debts
    MODIFY aging_bucket ENUM('31-60','61-90','91-180','180+','30-59','60-89','90+') DEFAULT '90+';

UPDATE bad_debts SET aging_bucket = CASE aging_bucket
    WHEN '31-60' THEN '30-59'
    WHEN '61-90' THEN '60-89'
    WHEN '91-180' THEN '90+'
    WHEN '180+' THEN '90+'
    ELSE aging_bucket
END
WHERE aging_bucket IN ('31-60','61-90','91-180','180+');

ALTER TABLE bad_debts
    MODIFY aging_bucket ENUM('30-59','60-89','90+') DEFAULT '90+';

-- arrears_tracking (schema.sql) has zero references anywhere in app/ --
-- confirmed dead. Renamed rather than dropped, in case any historical rows
-- exist despite nothing in the app ever having written to it -- nothing
-- reads this archived copy, but it's preserved rather than destroyed.
-- Replaced by arrears_status_transitions, the new transition audit log the
-- spec requires (event_type / accounting date / source_event_key),
-- matching loan_status_history's from/to shape.
RENAME TABLE arrears_tracking TO arrears_tracking_archived_pre_status_dimensions;

CREATE TABLE arrears_status_transitions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_date DATE NOT NULL,
    from_payment_status ENUM('Current','In Arrears') NULL,
    to_payment_status ENUM('Current','In Arrears') NOT NULL,
    from_aging_bucket ENUM('Current','1-29','30-59','60-89','90+') NULL,
    to_aging_bucket ENUM('Current','1-29','30-59','60-89','90+') NOT NULL,
    from_collection_status ENUM('Normal Collection','Arrears Recovery','Recovery Arrangement') NULL,
    to_collection_status ENUM('Normal Collection','Arrears Recovery','Recovery Arrangement') NOT NULL,
    days_in_arrears INT NOT NULL DEFAULT 0,
    source ENUM('Payment','Sweep','Backfill') NOT NULL,
    source_event_key VARCHAR(100) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
