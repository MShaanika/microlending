-- "Split Debit Order" feature: an optional toggle on debit order
-- registration that, instead of one Collexia EnDO V3 mandate for the full
-- monthly installment, places TWO mandates of half the amount each (leg A
-- / leg B), tracked independently but always resolving back onto the SAME
-- loan_schedules row -- both legs collect = Paid, one collects = Partial,
-- neither collects = stays unpaid. Purely additive: debit_orders and every
-- existing single-mandate code path are unchanged when split_enabled=0
-- (the default), and every table here is only ever touched for a split
-- order.

ALTER TABLE debit_orders
    ADD COLUMN split_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER collexia_api_synced_at;

-- One row per leg (A/B) of a split mandate -- mirrors debit_orders' own
-- collexia_api_* columns, just scoped to a leg, so Place Mandate/Check
-- Final Fate/Sync Status/Cancel Mandate can track each leg's own contract
-- reference and status independently.
CREATE TABLE debit_order_split_legs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    debit_order_id BIGINT NOT NULL,
    leg CHAR(1) NOT NULL,
    leg_amount DECIMAL(18,2) NOT NULL,
    collexia_api_contract_reference VARCHAR(14) NULL UNIQUE,
    collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled') NOT NULL DEFAULT 'Not Placed',
    collexia_api_last_response TEXT NULL,
    collexia_api_synced_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_debit_order_leg (debit_order_id, leg),
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Snapshotted once when a split mandate is placed: which loan_schedules
-- row corresponds to Collexia's own 1..N installment sequence number for
-- THIS mandate. Needed because both legs' mandates report collections
-- against their own sequence number, not our loan_schedules.installment_no
-- -- this mapping is what lets a collection result be posted to the exact
-- intended row (Payment::recordAndAllocateToScheduleId()) instead of
-- relying on FIFO, which could let the two legs land on different rows
-- whenever the loan has arrears ahead of the current installment.
CREATE TABLE debit_order_installment_targets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    debit_order_id BIGINT NOT NULL,
    collexia_installment_no INT NOT NULL,
    schedule_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_debit_order_installment (debit_order_id, collexia_installment_no),
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 'leg' distinguishes leg A's and leg B's collection rows for the same
-- debit order + Collexia installment number, which otherwise look
-- identical to the existing (debit_order_id, installment_no) idempotency
-- key in DebitOrderCollection::alreadyPosted() -- NULL for every
-- non-split collection (unaffected, matches today's behaviour exactly).
-- merchant_system_contract_no is also widened here to VARCHAR(14), the
-- same prerequisite database/collexia_v3_reconciliation.sql already
-- called for (V3's contractReference can be up to 14 characters; the
-- column was still sized for the older v1.0 spec's 10-char contract no).
ALTER TABLE debit_order_collections
    MODIFY merchant_system_contract_no VARCHAR(14),
    ADD COLUMN leg CHAR(1) NULL AFTER installment_no;

ALTER TABLE debit_order_collection_imports
    MODIFY report_type ENUM('Successful','Unsuccessful','Scheduled','CollexiaAPI') NOT NULL DEFAULT 'Successful';
