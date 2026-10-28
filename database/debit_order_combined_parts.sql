-- ONE Collexia debit per month for an original loan AND its released top-ups.
--
-- A combined instalment is still ONE row of debit_order_split_leg_installments (one Collexia instalment, one amount on the borrower's bank
-- statement). Its schedule_id stays the PRIMARY loan_schedules row (the original loan's, or the only row owing on that date). This table lists
-- the schedule rows -- on the original loan and on each top-up -- that the combined amount settles, so a successful collection can be split
-- back across them and each part posted on its own loan (Payment::recordCollectionParts).
--
-- Only combined instalments have rows here. Every existing mandate has none and posts exactly as before.
--
--   installment_id   debit_order_split_leg_installments.id
--   loan_id          the loan the schedule row belongs to (original loan or a top-up)
--   schedule_id      the loan_schedules row
--   planned_amount   what that row owed when the mandate was registered (informational; a collection is re-split by what is owed at the time)
CREATE TABLE IF NOT EXISTS debit_order_split_leg_installment_parts (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installment_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    schedule_id BIGINT NOT NULL,
    planned_amount DECIMAL(18,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_installment_part_schedule (installment_id, schedule_id),
    KEY idx_installment_part_loan (loan_id),
    KEY idx_installment_part_schedule (schedule_id),
    CONSTRAINT fk_installment_part_installment FOREIGN KEY (installment_id) REFERENCES debit_order_split_leg_installments (id) ON DELETE CASCADE,
    CONSTRAINT fk_installment_part_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    CONSTRAINT fk_installment_part_schedule FOREIGN KEY (schedule_id) REFERENCES loan_schedules (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
