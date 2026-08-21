-- Required before CollexiaPaymentReconciliationService is used.
-- Not run against production automatically -- run manually once the
-- Collexia V3 REST API has real credentials and this reconciliation path
-- is actually going to be exercised.
--
-- 1. merchant_system_contract_no was sized for the older "EnDo Batch v1.0"
--    Excel spec's contract number (<=10 chars). The V3 REST spec's
--    contractReference is defined as string(14) -- widen the column so a
--    V3 contract reference is never silently truncated.
-- 2. report_type's ENUM needs a value for API-sourced imports, distinct
--    from the three Excel report types it already covers.

ALTER TABLE debit_order_collections
    MODIFY merchant_system_contract_no VARCHAR(14);

ALTER TABLE debit_order_collection_imports
    MODIFY report_type ENUM('Successful','Unsuccessful','Scheduled','CollexiaAPI') NOT NULL DEFAULT 'Successful';
