-- Collexia's own "Statement Reference" (e.g. "FNCXSDLOANSLOAN0001A0001 261017")
-- is what actually distinguishes two split legs of the same loan on a bank
-- statement / the Cash Book -- merchant_system_contract_no alone doesn't,
-- since both legs' collections otherwise look identical once posted. Only
-- the REST Download Payments response (statementRef) and the "Successful
-- Transactions (simplified)" Excel report (StatementReference) actually
-- carry this value today; every other import path leaves it NULL rather
-- than guessing. See CollexiaPaymentReconciliationService::reconcile() and
-- DebitOrderCollectionController::store().
ALTER TABLE debit_order_collections
    ADD COLUMN statement_reference VARCHAR(100) NULL AFTER merchant_system_contract_no;
