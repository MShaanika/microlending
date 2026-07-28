-- =========================================================
-- HRM MODULE -- PHASE 4: Performance & Discipline
-- Awards, Complaints, Warnings, Terminations, Promotions and
-- Transfers. Awards/Complaints/Warnings/Terminations are
-- standalone audit-trail records with no side effects on the
-- employee record. Promotions and Transfers cascade into
-- hrm_employees.branch_id/department_id/designation_id --
-- Promotion applies immediately on creation (matching the
-- reference module), Transfer only applies once approved.
-- Termination additionally sets hrm_employees.status =
-- 'Terminated' once approved (the existing Phase 1 status enum
-- already has this value; the reference module never wired it
-- up, this closes that gap).
-- Run AFTER database/hrm_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_transfers;
DROP TABLE IF EXISTS hrm_promotions;
DROP TABLE IF EXISTS hrm_terminations;
DROP TABLE IF EXISTS hrm_termination_types;
DROP TABLE IF EXISTS hrm_warnings;
DROP TABLE IF EXISTS hrm_warning_types;
DROP TABLE IF EXISTS hrm_complaints;
DROP TABLE IF EXISTS hrm_complaint_types;
DROP TABLE IF EXISTS hrm_awards;
DROP TABLE IF EXISTS hrm_award_types;

-- ---------------------------------------------------------
-- Awards
-- ---------------------------------------------------------
CREATE TABLE hrm_award_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_awards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    award_type_id INT NULL,
    award_date DATE NOT NULL,
    description TEXT NULL,
    certificate VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (award_type_id) REFERENCES hrm_award_types(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Complaints
-- ---------------------------------------------------------
CREATE TABLE hrm_complaint_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'Complainant',
    against_employee_id INT NULL,
    complaint_type_id INT NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NULL,
    complaint_date DATE NOT NULL,
    status ENUM('Pending','In Review','Assigned','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
    document VARCHAR(255) NULL,
    resolved_by INT NULL,
    resolution_date DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (against_employee_id) REFERENCES hrm_employees(id) ON DELETE SET NULL,
    FOREIGN KEY (complaint_type_id) REFERENCES hrm_complaint_types(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Warnings
-- ---------------------------------------------------------
CREATE TABLE hrm_warning_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_warnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    warning_by INT NULL,
    warning_type_id INT NULL,
    subject VARCHAR(200) NOT NULL,
    severity ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
    warning_date DATE NOT NULL,
    description TEXT NULL,
    document VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    employee_response TEXT NULL,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (warning_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (warning_type_id) REFERENCES hrm_warning_types(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Terminations
-- ---------------------------------------------------------
CREATE TABLE hrm_termination_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_terminations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    termination_type_id INT NULL,
    notice_date DATE NULL,
    termination_date DATE NULL,
    reason VARCHAR(255) NOT NULL,
    description TEXT NULL,
    document VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (termination_type_id) REFERENCES hrm_termination_types(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Promotions -- applies to the employee record immediately on
-- creation (previous_* snapshotted from the employee's current
-- values, current_* written to both this row and the employee
-- record). Approval only records a decision on the promotion
-- itself, matching the reference module's behaviour.
-- ---------------------------------------------------------
CREATE TABLE hrm_promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    previous_branch_id INT NULL,
    previous_department_id INT NULL,
    previous_designation_id INT NULL,
    current_branch_id INT NULL,
    current_department_id INT NULL,
    current_designation_id INT NULL,
    effective_date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    document VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (previous_department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (previous_designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
    FOREIGN KEY (current_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (current_department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (current_designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Transfers -- from_* snapshotted from the employee's current
-- values on creation, to_* only applied to the employee record
-- once the transfer is approved.
-- ---------------------------------------------------------
CREATE TABLE hrm_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    from_branch_id INT NULL,
    from_department_id INT NULL,
    from_designation_id INT NULL,
    to_branch_id INT NULL,
    to_department_id INT NULL,
    to_designation_id INT NULL,
    transfer_date DATE NULL,
    effective_date DATE NOT NULL,
    reason TEXT NULL,
    document VARCHAR(255) NULL,
    status ENUM('Pending','Approved','In Progress','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (from_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (from_department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (from_designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
    FOREIGN KEY (to_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (to_department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (to_designation_id) REFERENCES hrm_designations(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
