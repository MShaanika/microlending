-- Closes a real gap: collexia_api_status never moved once Collexia's own
-- mandate reached its authoritative "Completed" or "Cancelled" state via
-- Sync Status -- it just stayed at whatever the last PLACEMENT attempt set
-- it to (usually "Registered" forever), which is why a mandate could show
-- "Completed" (debit_orders.status, from Collexia's mandate.status) right
-- next to "Registered" (collexia_api_status, stale) with Cancel/Reschedule
-- still offered. See CollexiaMandateStatusSyncService, which now writes
-- both fields consistently.
--
-- 'Mixed' replaces the old split rollup's silent fallback to 'Registered'
-- whenever split legs genuinely disagree (e.g. one Registered, one
-- Cancelled) in a way that isn't simply "one is still Load
-- Pending/Failed" -- that fallback hid a leg that had actually stopped
-- collecting. See DebitOrderSplitLeg::computeRollupStatus().
ALTER TABLE debit_orders
    MODIFY collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled','Uncertain','Completed','Mixed') NOT NULL DEFAULT 'Not Placed';

ALTER TABLE debit_order_split_legs
    MODIFY collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled','Uncertain','Completed') NOT NULL DEFAULT 'Not Placed';
