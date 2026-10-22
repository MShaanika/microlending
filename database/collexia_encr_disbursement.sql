-- Collexia EnCr (Enhanced Credit) loan payout -- tracking columns on the disbursement.
--
-- EnCr is an optional, switchable way of paying a loan out: when it is switched
-- ON (Collections > Debit Order API Settings > Manage) and chosen on the Release
-- form, the disbursement is sent to Collexia as an EnCr transfer instead of being
-- paid by hand. With it switched OFF nothing here is used and the existing
-- disbursement process is exactly as before.
--
--   encr_reference_no  the remoteReferenceNo sent to Collexia (14 chars). This is
--                      also what the Cash Book and General Journal show as the
--                      reference of the disbursement, and it is what Collexia
--                      de-duplicates on, so a retry of the same payout re-uses it.
--   encr_state         where the payout stands on OUR side:
--                      Not Sent -> Submitted -> Completed | Failed
--   encr_status_code   Collexia's own status (1 Loaded, 2 Approved, 3 Completed,
--                      4 Rejected, 5 Cancelled, 6 Submitted, 7 Declined), as last
--                      returned by the EnCr info call.
ALTER TABLE loan_disbursements
    ADD COLUMN encr_reference_no VARCHAR(14) NULL AFTER reference_no,
    ADD COLUMN encr_state VARCHAR(20) NULL AFTER encr_reference_no,
    ADD COLUMN encr_status_code TINYINT NULL AFTER encr_state,
    ADD COLUMN encr_response_code VARCHAR(10) NULL AFTER encr_status_code,
    ADD COLUMN encr_response_description VARCHAR(250) NULL AFTER encr_response_code,
    ADD COLUMN encr_amount_paid_out DECIMAL(18,2) NULL AFTER encr_response_description,
    ADD COLUMN encr_submitted_at DATETIME NULL AFTER encr_amount_paid_out,
    ADD COLUMN encr_checked_at DATETIME NULL AFTER encr_submitted_at,
    ADD COLUMN encr_last_message VARCHAR(500) NULL AFTER encr_checked_at,
    ADD UNIQUE KEY uq_loan_disbursements_encr_reference (encr_reference_no);
