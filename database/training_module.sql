-- =========================================================
-- TRAINING MODULE
-- A standalone employee-training system: training types
-- (catalog), a trainer directory, scheduled trainings, a
-- roster of enrolled employees per training (with per-employee
-- completion tracking), assignable tasks within a training, and
-- 1-5 star feedback per task. Ported from the reference
-- workdo/Training package, which the reference app itself
-- documents as depending only on Hrm (branches/departments) --
-- confirmed to have zero data coupling with workdo/Recruitment,
-- so this is kept as its own top-level module, not nested under
-- Human Resources or Recruitment.
--
-- Deliberate deviation from the reference: the reference module
-- has no real "who's enrolled in this training" concept -- its
-- only mechanism is training_tasks (1 task = 1 assignee), so
-- enrolling 5 employees means creating 5 separate task rows with
-- no roster/attendance rollup anywhere. Here, training_enrollments
-- is a first-class roster table (training_id, employee_id,
-- status, completed_at) so "who's in this training and are they
-- done" is a direct query -- training_tasks/training_feedback are
-- kept as-is for finer-grained per-employee task/rating tracking
-- within an enrollment, same shape as the reference.
--
-- The reference module assigns tasks/feedback to its app's
-- generic `users` table (specifically staff-type accounts). Here,
-- both training_enrollments and training_tasks point directly at
-- hrm_employees (this app's real employee directory, which may or
-- may not have a login) -- a more faithful mapping than the
-- reference's indirect "assign to a login account" model, since
-- most employees being trained will not have system logins.
--
-- Run AFTER database/hrm_module.sql has been imported (for the
-- hrm_employees/hrm_departments FKs) and after core schema.sql
-- (for branches).
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS training_feedback;
DROP TABLE IF EXISTS training_tasks;
DROP TABLE IF EXISTS training_enrollments;
DROP TABLE IF EXISTS trainings;
DROP TABLE IF EXISTS trainers;
DROP TABLE IF EXISTS training_types;

CREATE TABLE training_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    branch_id INT NULL,
    department_id INT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Standalone instructor/vendor directory -- not linked to
-- hrm_employees or users, same as the reference (a trainer can
-- be internal staff or an external vendor, treated identically).
-- ---------------------------------------------------------
CREATE TABLE trainers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    experience VARCHAR(100) NULL,
    expertise TEXT NULL,
    qualification TEXT NULL,
    branch_id INT NULL,
    department_id INT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trainings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    training_type_id INT NULL,
    trainer_id INT NULL,
    branch_id INT NULL,
    department_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    location VARCHAR(200) NULL,
    max_participants INT NULL,
    cost DECIMAL(12,2) NULL,
    status ENUM('Scheduled','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (training_type_id) REFERENCES training_types(id) ON DELETE SET NULL,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Roster: who is enrolled in this training and are they done.
-- Not present in the reference module -- see header comment.
-- ---------------------------------------------------------
CREATE TABLE training_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_id INT NOT NULL,
    employee_id INT NOT NULL,
    status ENUM('Enrolled','Completed','Dropped') NOT NULL DEFAULT 'Enrolled',
    completed_at DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_training_employee (training_id, employee_id),
    FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE training_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    assigned_to INT NULL,
    status ENUM('Pending','Completed') NOT NULL DEFAULT 'Pending',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES hrm_employees(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Feedback is about the task's assignee (copied from
-- training_tasks.assigned_to at submission time), same as the
-- reference -- functions as a 1-5 rating widget, not a formal
-- pass/fail evaluation.
-- ---------------------------------------------------------
CREATE TABLE training_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_task_id INT NOT NULL,
    employee_id INT NOT NULL,
    rating TINYINT NOT NULL DEFAULT 1,
    comments TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (training_task_id) REFERENCES training_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('training.view', 'View Training Records', 'Training'),
('training.manage', 'Manage Training Records (Types, Trainers, Trainings, Enrollments)', 'Training');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'Training';

SET FOREIGN_KEY_CHECKS = 1;
