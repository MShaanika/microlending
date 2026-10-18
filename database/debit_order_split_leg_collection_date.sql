-- The exact date this leg's one-time Collexia mandate should collect on.
-- Set by the "one mandate per scheduled installment" registration mode,
-- where each leg is a whole installment collected on its own amortization
-- date (or the earliest valid future date, for an installment already past
-- due). NULL for every older leg, which keeps falling back to its target
-- installment's due date and then the debit order's start_date -- see
-- DebitOrderCollexiaController::attemptSplitPlacement().
ALTER TABLE debit_order_split_legs
    ADD COLUMN collection_date DATE NULL AFTER schedule_id;
