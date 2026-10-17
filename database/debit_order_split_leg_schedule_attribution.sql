-- Fixes a real attribution bug waiting to happen for multi-month splits:
-- each split leg is its own one-time Collexia mandate (noOfInstallments=1),
-- so Collexia always reports "installment #1" for whichever leg collects --
-- that's "this mandate's first and only collection", not "the loan's first
-- month". debit_order_installment_targets' old lookup (keyed by debit_order
-- + that always-1 number, snapshotted once at first placement) collapses
-- every month's legs onto the SAME schedule row the moment a second
-- month's splits get placed under the same debit order.
--
-- schedule_id is set directly on each leg at creation time (see
-- DebitOrderController::store() for the first batch,
-- DebitOrderController::addNextInstallmentSplits() for later months), so
-- resolving a leg's own contractReference (CollexiaMandateLookupService)
-- gives its unambiguous target with no installmentNo involved at all.
-- debit_order_installment_targets is left in place (unused for new splits
-- going forward) as a fallback for any split leg placed before this
-- migration that never got its own schedule_id set.
ALTER TABLE debit_order_split_legs
    ADD COLUMN schedule_id BIGINT NULL AFTER total_splits,
    ADD FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id);
