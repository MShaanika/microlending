-- =========================================================
-- HRM MODULE -- PHASE 3: Payroll
-- Allowances, deductions, and payroll runs that turn each
-- employee's basic salary plus that period's attendance/leave
-- record into a gross/net pay figure.
--
-- Scoped down from the reference workdo/Hrm module: "manual
-- overtime" (a separate ad-hoc override entity) and staff-loan
-- deductions are left for a later phase rather than pulled in
-- as hard dependencies here -- attendance-based overtime
-- (already computed in Phase 2) is what feeds gross pay.
--
-- Run AFTER database/hrm_attendance_leave_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_payroll_entries;
DROP TABLE IF EXISTS hrm_payrolls;
DROP TABLE IF EXISTS hrm_allowances;
DROP TABLE IF EXISTS hrm_allowance_types;
DROP TABLE IF EXISTS hrm_deductions;
DROP TABLE IF EXISTS hrm_deduction_types;

-- ---------------------------------------------------------
-- Which weekdays count as working days when a payroll run
-- prorates basic salary into a per-day rate. Stored as CSV of
-- PHP date('w') indices (0=Sun..6=Sat); default Mon-Fri.
-- ---------------------------------------------------------
ALTER TABLE companies ADD COLUMN working_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5';

CREATE TABLE hrm_allowance_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Recurring per-employee allowances, applied to every payroll
-- run until removed. amount is either a flat NAD figure or a
-- percentage of that run's basic_salary, per `type`.
-- ---------------------------------------------------------
CREATE TABLE hrm_allowances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    allowance_type_id INT NOT NULL,
    type ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_allowance (employee_id, allowance_type_id),
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (allowance_type_id) REFERENCES hrm_allowance_types(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_deduction_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    deduction_type_id INT NOT NULL,
    type ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_deduction (employee_id, deduction_type_id),
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (deduction_type_id) REFERENCES hrm_deduction_types(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- One payroll run per pay period. Totals are rolled up from
-- its entries once run.
-- ---------------------------------------------------------
CREATE TABLE hrm_payrolls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    payroll_frequency ENUM('Weekly','Biweekly','Monthly') NOT NULL DEFAULT 'Monthly',
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    pay_date DATE NULL,
    notes VARCHAR(500) NULL,
    total_gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    employee_count INT NOT NULL DEFAULT 0,
    status ENUM('Draft','Processing','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
    bank_account_id BIGINT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- One row per employee per payroll run -- the payslip. Snapshot
-- values so a later change to an employee's salary/allowances
-- doesn't retroactively alter an already-run payroll.
-- ---------------------------------------------------------
CREATE TABLE hrm_payroll_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT NOT NULL,
    employee_id INT NOT NULL,

    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    per_day_salary DECIMAL(12,2) NOT NULL DEFAULT 0,

    working_days INT NOT NULL DEFAULT 0,
    present_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    half_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    half_day_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    absent_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    absent_day_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_leave_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    unpaid_leave_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    unpaid_leave_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,

    overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    overtime_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    overtime_amount DECIMAL(12,2) NOT NULL DEFAULT 0,

    status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid',
    allowances_breakdown TEXT NULL,
    deductions_breakdown TEXT NULL,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_payroll_employee (payroll_id, employee_id),
    FOREIGN KEY (payroll_id) REFERENCES hrm_payrolls(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
