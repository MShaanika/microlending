-- Snapshots clientNo (and, for a split leg, the per-leg userReference) as
-- actually sent to Collexia at mandate placement time -- same rationale as
-- collexia_user_reference.sql: the value on record for an already-placed
-- mandate must stay exactly what Collexia was told, not a live
-- recomputation from borrowers.loan_ref_no (which could be edited later)
-- or, for a split leg that went through the retry path, a value that
-- wouldn't match what was actually sent (a retry's userReference is
-- time-based, not deterministic from debit_order_id + split_no). Lets the
-- debit order / split transactions screens show the same Client No /
-- Merchant System Contract No Collexia's own Mandate Data Repository
-- shows, instead of staff having to cross-check the Collexia portal.
-- See App\Controllers\DebitOrderCollexiaController::placeSingleMandate()
-- and attemptSplitPlacement().
ALTER TABLE debit_orders
    ADD COLUMN collexia_client_no VARCHAR(15) NULL AFTER collexia_api_contract_reference;

ALTER TABLE debit_order_split_legs
    ADD COLUMN collexia_client_no VARCHAR(15) NULL AFTER collexia_api_contract_reference,
    ADD COLUMN collexia_user_reference VARCHAR(10) NULL AFTER collexia_client_no;
