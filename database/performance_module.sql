-- =========================================================
-- PERFORMANCE MODULE
-- A standalone employee-performance system: KPI/OKR-style
-- Employee Goals, plus indicator-based Employee Reviews (1-5
-- star ratings per performance indicator, grouped by category,
-- averaged into an overall score). Ported from the reference
-- workdo/Performance package, which is functionally independent
-- of workdo/Hrm's own "Performance & Discipline" feature set
-- (Awards/Complaints/Warnings/Terminations/Promotions/Transfers,
-- already ported) -- zero overlap, deliberately kept as its own
-- top-level module rather than folded into Human Resources.
--
-- The reference module points every person-reference at its
-- app's generic `users` table. Here, the employee being
-- goal-tracked or reviewed is a real hrm_employees row (this
-- app's actual employee directory, which may or may not have a
-- login) -- reviewer_id stays on `users` since conducting a
-- review requires being logged in.
--
-- Business logic replicated faithfully from the reference,
-- including what it does NOT do: goal progress/status are
-- manually edited, never auto-computed from dates or indicators;
-- review scoring is a flat average of whatever indicators the
-- reviewer chose to rate (rating=0 excluded from both sides of
-- the average), rounded to 1 decimal; indicator target_value/
-- measurement_unit are metadata only, never used in scoring.
--
-- Run AFTER database/hrm_module.sql has been imported (for the
-- hrm_employees FK).
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS performance_employee_reviews;
DROP TABLE IF EXISTS performance_review_cycles;
DROP TABLE IF EXISTS performance_employee_goals;
DROP TABLE IF EXISTS performance_goal_types;
DROP TABLE IF EXISTS performance_indicators;
DROP TABLE IF EXISTS performance_indicator_categories;

CREATE TABLE performance_indicator_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- target_value/measurement_unit are free-text metadata shown
-- alongside the indicator (e.g. "90%", "<24 hours") -- never
-- referenced by the review scoring logic.
-- ---------------------------------------------------------
CREATE TABLE performance_indicators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    measurement_unit VARCHAR(100) NULL,
    target_value VARCHAR(50) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES performance_indicator_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE performance_goal_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- target is free-text (e.g. "100", "500000", "4/5") -- progress
-- percentage is only meaningful when target happens to be a
-- plain number; both progress and status are set by hand, never
-- auto-derived from dates or a linked indicator.
-- ---------------------------------------------------------
CREATE TABLE performance_employee_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    goal_type_id INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    target VARCHAR(50) NOT NULL,
    progress DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Not Started','In Progress','Completed','Overdue') NOT NULL DEFAULT 'Not Started',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_type_id) REFERENCES performance_goal_types(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- A label/dropdown reviews attach to -- frequency is metadata
-- only, no actual date range or auto-generation of reviews.
-- ---------------------------------------------------------
CREATE TABLE performance_review_cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    frequency ENUM('Monthly','Quarterly','Semi-Annual','Annual') NOT NULL DEFAULT 'Annual',
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- rating is a JSON map {indicator_id: 1-5} stored as text,
-- filled in via the one-shot "conduct" action which always
-- forces status to Completed and stamps completion_date. No
-- partial/draft save of an in-progress rating fill-out, no
-- second-level approval -- replicated faithfully from the
-- reference module's actual (minimal) workflow.
-- ---------------------------------------------------------
CREATE TABLE performance_employee_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    reviewer_id INT NULL,
    review_cycle_id INT NULL,
    review_date DATE NOT NULL,
    completion_date DATE NULL,
    rating TEXT NULL,
    pros TEXT NULL,
    cons TEXT NULL,
    status ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (review_cycle_id) REFERENCES performance_review_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('performance.view', 'View Performance Records', 'Performance'),
('performance.manage', 'Manage Performance Records (Goals, Reviews, Indicators)', 'Performance');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'Performance';

SET FOREIGN_KEY_CHECKS = 1;
