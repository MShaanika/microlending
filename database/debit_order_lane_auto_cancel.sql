-- Installments that were already paid by hand but are sent to Collexia anyway, then cancelled automatically.
--
-- A client may want the mandate to carry the WHOLE term even though some installments (typically ones
-- paid early) were settled manually before any debit order existed. Those installments ride in the
-- mandate so its numbering follows the loan, and once Collexia has confirmed the mandate they must be
-- cancelled so the borrower is not debited twice (CollexiaPaidInstallmentCanceller).
--
--   auto_cancel   1 = this installment was paid by hand and is to be cancelled at Collexia after placement
--   cancelled_at  when that cancellation succeeded (NULL while still waiting -- the finalize cron retries)
--   cancel_note   what happened, in words, for the screen and the audit trail
ALTER TABLE debit_order_split_leg_installments
    ADD COLUMN auto_cancel TINYINT(1) NOT NULL DEFAULT 0 AFTER applied_at,
    ADD COLUMN cancelled_at DATETIME NULL AFTER auto_cancel,
    ADD COLUMN cancel_note VARCHAR(255) NULL AFTER cancelled_at;
