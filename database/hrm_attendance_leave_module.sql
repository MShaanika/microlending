-- =========================================================
-- HRM MODULE -- PHASE 2: Attendance & Leave Management
-- Run AFTER database/hrm_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_attendances;
DROP TABLE IF EXISTS hrm_leave_applications;
DROP TABLE IF EXISTS hrm_leave_types;
DROP TABLE IF EXISTS hrm_holidays;

-- ---------------------------------------------------------
-- Overtime pay needs an hourly rate on the employee; not
-- part of Phase 1's fields.
-- ---------------------------------------------------------
ALTER TABLE hrm_employees ADD COLUMN rate_per_hour DECIMAL(10,2) NULL AFTER days_per_week;

-- ---------------------------------------------------------
-- Daily clock in/out per employee. total_hour/overtime_hours/
-- overtime_amount/status are computed at save time (see
-- HrmAttendance::calculate()), not recomputed on read.
-- ---------------------------------------------------------
CREATE TABLE hrm_attendances (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NULL,
    attendance_date DATE NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    break_hour DECIMAL(8,2) NOT NULL DEFAULT 0,
    total_hour DECIMAL(8,2) NOT NULL DEFAULT 0,
    overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    overtime_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Present','Half Day','Absent') NOT NULL DEFAULT 'Present',
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_date (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES hrm_shifts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Leave types (Annual, Sick, ...) with a per-year day
-- allocation used to compute the Leave Balance report.
-- ---------------------------------------------------------
CREATE TABLE hrm_leave_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    max_days_per_year INT NOT NULL DEFAULT 0,
    is_paid TINYINT(1) NOT NULL DEFAULT 1,
    color VARCHAR(7) NOT NULL DEFAULT '#FF6B6B',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Leave applications, Pending -> Approved/Rejected. Only
-- Approved applications count against the Leave Balance
-- report's used_days.
-- ---------------------------------------------------------
CREATE TABLE hrm_leave_applications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    approver_comment VARCHAR(500) NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES hrm_leave_types(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Company holiday calendar. holiday_type kept as a plain
-- ENUM here rather than a separate lookup table -- the
-- reference module's holiday_types table only ever holds a
-- single free-text label with no other attributes.
-- ---------------------------------------------------------
CREATE TABLE hrm_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    holiday_type ENUM('Public','Company','Optional') NOT NULL DEFAULT 'Public',
    description VARCHAR(255) NULL,
    is_paid TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Seed a sensible default set of leave types so the Leave
-- Balance report isn't empty on first use.
-- ---------------------------------------------------------
INSERT INTO hrm_leave_types (name, description, max_days_per_year, is_paid, color) VALUES
('Annual Leave', 'Standard yearly paid leave entitlement', 24, 1, '#4CAF50'),
('Sick Leave', 'Medical/illness leave', 30, 1, '#FF6B6B'),
('Unpaid Leave', 'Leave without pay', 0, 0, '#9E9E9E'),
('Compassionate Leave', 'Bereavement/family emergency leave', 5, 1, '#607D8B');

SET FOREIGN_KEY_CHECKS = 1;
