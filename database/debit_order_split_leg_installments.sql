-- One split "lane" = one Collexia mandate that carries SEVERAL loan
-- installments (noOfInstallments = N), instead of one one-time mandate per
-- installment. A lane's debit_order_split_legs row has schedule_id NULL; this
-- table says which loan installment each of that mandate's installments
-- (Collexia's own 1..N sequence) is, and what date/amount it should collect.
--
--   leg_id          the lane (debit_order_split_legs.id)
--   installment_no  Collexia's installment sequence number within that mandate
--   schedule_id     the loan_schedules row this installment settles
--   planned_date    exact amortization date it should collect on
--   planned_amount  exact amount it should collect
--   collexia_date   the scheduleDate last read back from Collexia (sync)
--   applied_at      when planned date/amount were confirmed on Collexia
--                   (NULL = still needs the post-registration update step)
--
-- Payment reconciliation resolves a collected installment as
-- (contractReference -> leg, installmentNo) -> schedule_id from here.
CREATE TABLE IF NOT EXISTS debit_order_split_leg_installments (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    leg_id BIGINT NOT NULL,
    installment_no INT NOT NULL,
    schedule_id BIGINT NOT NULL,
    planned_date DATE NOT NULL,
    planned_amount DECIMAL(18,2) NOT NULL,
    collexia_date DATE NULL,
    applied_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leg_installment (leg_id, installment_no),
    KEY idx_leg_inst_schedule (schedule_id),
    CONSTRAINT fk_leg_inst_leg FOREIGN KEY (leg_id) REFERENCES debit_order_split_legs (id) ON DELETE CASCADE,
    CONSTRAINT fk_leg_inst_schedule FOREIGN KEY (schedule_id) REFERENCES loan_schedules (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
