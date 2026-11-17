-- "Loan questions": while the loans of the online applications are approved and managed in the old loan system, staff are asked, application by application,
-- whether it was approved, for how much, whether it was paid out and, once paid out, whether each instalment was paid. The answers feed the Creditinfo files.

CREATE TABLE IF NOT EXISTS loan_followups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    application_no VARCHAR(40) NOT NULL,
    status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
    closed_reason VARCHAR(60) NULL,
    approved ENUM('Yes', 'No') NULL,
    plans_to_approve ENUM('Yes', 'No', 'Not sure') NULL,
    approved_amount DECIMAL(12,2) NULL,
    disbursed ENUM('Yes', 'No') NULL,
    disbursed_on DATE NULL,
    term_months INT NULL,
    pay_day TINYINT NULL,
    ask_count INT NOT NULL DEFAULT 0,
    next_ask_at DATETIME NULL,
    last_answered_by INT NULL,
    last_answered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_followup_application (application_id),
    INDEX idx_followup_open (status, next_ask_at),
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (last_answered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per instalment of a paid-out loan; "paid_amount" is what has been paid towards it so far in total, "paid_on" the date of the latest payment.
CREATE TABLE IF NOT EXISTS loan_followup_instalments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    followup_id BIGINT NOT NULL,
    instalment_no INT NOT NULL,
    due_date DATE NOT NULL,
    expected_amount DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NULL,
    paid_on DATE NULL,
    answered_by INT NULL,
    answered_at DATETIME NULL,
    next_ask_at DATETIME NULL,
    UNIQUE KEY uniq_followup_instalment (followup_id, instalment_no),
    FOREIGN KEY (followup_id) REFERENCES loan_followups(id) ON DELETE CASCADE,
    FOREIGN KEY (answered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each payment reported towards an instalment, with its own date (a month-end file only counts the payments made by that month end).
CREATE TABLE IF NOT EXISTS loan_followup_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instalment_id BIGINT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_on DATE NOT NULL,
    answered_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_followup_payment_instalment (instalment_id),
    FOREIGN KEY (instalment_id) REFERENCES loan_followup_instalments(id) ON DELETE CASCADE,
    FOREIGN KEY (answered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every answer ever given, who gave it and when (nothing is overwritten without a trace).
CREATE TABLE IF NOT EXISTS loan_followup_answers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    followup_id BIGINT NOT NULL,
    question VARCHAR(40) NOT NULL,
    answer VARCHAR(255) NOT NULL,
    answered_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_followup_answers (followup_id),
    FOREIGN KEY (followup_id) REFERENCES loan_followups(id) ON DELETE CASCADE,
    FOREIGN KEY (answered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'loans.followups', 'Answer Loan Questions (approved, paid out, paid)', 'Loans'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'loans.followups');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name IN ('Super Admin', 'Admin') AND p.permission_key = 'loans.followups'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
