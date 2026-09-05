-- Snapshots the DesertLedger loan reference (borrowers.loan_ref_no, e.g.
-- "SDLOAN0003") actually sent to Collexia as `userReference` at mandate
-- placement time -- stored separately from borrowers.loan_ref_no itself so
-- that if a borrower's reference is ever edited afterward, the value on
-- record for an already-placed mandate stays exactly what Collexia was
-- told, matching contractReference's existing immutable-once-placed
-- treatment. See App\Controllers\DebitOrderCollexiaController::placeSingleMandate().
ALTER TABLE debit_orders
    ADD COLUMN collexia_user_reference VARCHAR(10) NULL AFTER collexia_api_contract_reference;
