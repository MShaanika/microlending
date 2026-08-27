-- Period-by-period interest income accrual -- mirrors the existing
-- `penalties` table exactly: one row per loan_schedules installment whose
-- interest has been recognized as income (either because its due date
-- arrived, or because it was recognized early against an advance payment),
-- serving as both the audit trail and the idempotency guard (a schedule row
-- can only ever be accrued once -- enforced at the DB level via the unique
-- key on schedule_id, not just application logic).
--
-- See app/Services/InterestAccrualService.php.

CREATE TABLE interest_accruals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    schedule_id BIGINT NOT NULL,
    accrual_no VARCHAR(50) NOT NULL UNIQUE,
    accrual_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('Accrued','Reversed') NOT NULL DEFAULT 'Accrued',
    accrued_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id),
    FOREIGN KEY (accrued_by) REFERENCES users(id),
    UNIQUE KEY uniq_schedule_accrual (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
