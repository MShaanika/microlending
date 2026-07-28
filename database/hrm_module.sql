-- =========================================================
-- HRM MODULE -- PHASE 1: Core HR foundation
-- Org structure (Departments, Designations, Shifts) and the
-- Employee register. Reuses the existing branches/users tables
-- rather than duplicating them. Later phases (Attendance, Leave,
-- Payroll, Performance & Discipline, Communications, Staff Loans)
-- are separate module files layered on top of this one.
-- Run AFTER database/schema.sql (or minimal_setup.sql) has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_employees;
DROP TABLE IF EXISTS hrm_designations;
DROP TABLE IF EXISTS hrm_departments;
DROP TABLE IF EXISTS hrm_shifts;

-- ---------------------------------------------------------
-- Departments (per branch)
-- ---------------------------------------------------------
CREATE TABLE hrm_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(150) NOT NULL,
    branch_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Designations (job titles), scoped to a department
-- ---------------------------------------------------------
CREATE TABLE hrm_designations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    designation_name VARCHAR(150) NOT NULL,
    branch_id INT NULL,
    department_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Work shifts (used by Employees now, and by Attendance in
-- a later phase)
-- ---------------------------------------------------------
CREATE TABLE hrm_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_name VARCHAR(100) NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    break_start_time TIME NULL,
    break_end_time TIME NULL,
    is_night_shift TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Employee register. user_id optionally links to an existing
-- staff login (users) for portal/system access -- an employee
-- record does not require one (e.g. field/contract staff not
-- given system accounts).
-- ---------------------------------------------------------
CREATE TABLE hrm_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_no VARCHAR(30) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    date_of_birth DATE NULL,
    gender ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
    date_of_joining DATE NULL,
    employment_type ENUM('Full-Time','Part-Time','Contract','Intern') NOT NULL DEFAULT 'Full-Time',
    status ENUM('Active','On Leave','Suspended','Terminated') NOT NULL DEFAULT 'Active',

    address_line_1 VARCHAR(200) NULL,
    address_line_2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    country VARCHAR(100) NULL DEFAULT 'Namibia',
    postal_code VARCHAR(20) NULL,

    emergency_contact_name VARCHAR(150) NULL,
    emergency_contact_relationship VARCHAR(100) NULL,
    emergency_contact_number VARCHAR(50) NULL,

    bank_name VARCHAR(150) NULL,
    account_holder_name VARCHAR(150) NULL,
    account_number VARCHAR(50) NULL,
    branch_code VARCHAR(50) NULL,
    tax_payer_id VARCHAR(50) NULL,

    basic_salary DECIMAL(18,2) NULL,
    hours_per_day DECIMAL(5,2) NULL DEFAULT 8,
    days_per_week DECIMAL(3,1) NULL DEFAULT 5,

    user_id INT NULL,
    branch_id INT NULL,
    department_id INT NULL,
    designation_id INT NULL,
    shift_id INT NULL,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
    FOREIGN KEY (shift_id) REFERENCES hrm_shifts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('hrm.view', 'View HR Records', 'HRM'),
('hrm.manage', 'Manage HR Records (Departments, Designations, Shifts, Employees)', 'HRM');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'HRM';

SET FOREIGN_KEY_CHECKS = 1;
