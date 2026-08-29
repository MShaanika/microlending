-- Minimal visibility fix for the "API timeout, outcome unknown" gap:
-- DebitOrderCollexiaController::placeSingleMandate()'s network-level
-- failure catch previously left collexia_api_status completely untouched,
-- making a genuinely-unknown outcome silently indistinguishable from
-- "never attempted." Adds an 'Uncertain' state so it's now visible and can
-- be manually reconciled -- full automated reconciliation/retry against
-- Collexia is separate, larger work, explicitly deferred.
ALTER TABLE debit_orders
    MODIFY COLUMN collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled','Uncertain') NOT NULL DEFAULT 'Not Placed';

ALTER TABLE debit_order_split_legs
    MODIFY COLUMN collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled','Uncertain') NOT NULL DEFAULT 'Not Placed';
