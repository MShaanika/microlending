-- =========================================================
-- HRM MODULE -- PHASE 5: Communications
-- Announcements and Events, each targeted at one or more
-- departments via a pivot table (matching the reference
-- module -- there is no company-wide broadcast option and no
-- per-employee attendee/RSVP list, "reach" is always
-- department-level). Deliberate scope cut vs. the reference
-- module: no calendar view is built here (would need a JS
-- calendar library not otherwise used in this app) -- Events
-- are browsable as a plain filterable list instead, which
-- carries the same date/time/location information.
-- Run AFTER database/hrm_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_event_departments;
DROP TABLE IF EXISTS hrm_events;
DROP TABLE IF EXISTS hrm_event_types;
DROP TABLE IF EXISTS hrm_announcement_departments;
DROP TABLE IF EXISTS hrm_announcements;
DROP TABLE IF EXISTS hrm_announcement_categories;

-- ---------------------------------------------------------
-- Announcements
-- ---------------------------------------------------------
CREATE TABLE hrm_announcement_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    announcement_category_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    priority ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
    status ENUM('Draft','Active','Inactive') NOT NULL DEFAULT 'Draft',
    description TEXT NULL,
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_category_id) REFERENCES hrm_announcement_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_announcement_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    department_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_announcement_department (announcement_id, department_id),
    FOREIGN KEY (announcement_id) REFERENCES hrm_announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Events
-- ---------------------------------------------------------
CREATE TABLE hrm_event_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    event_type_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    location VARCHAR(255) NOT NULL,
    color VARCHAR(20) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    description TEXT NULL,
    approved_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_type_id) REFERENCES hrm_event_types(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_event_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    department_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_department (event_id, department_id),
    FOREIGN KEY (event_id) REFERENCES hrm_events(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
