-- =========================================================
-- RECRUITMENT MODULE
-- A standalone hiring pipeline: job postings -> candidates ->
-- interviews (rounds, feedback, assessments) -> offers (with an
-- approval step) -> convert-to-employee -> onboarding checklists.
-- Includes a public, unauthenticated careers portal (job listing,
-- apply form, application tracking by code, offer response).
-- Ported from the reference workdo/Recruitment package -- confirmed
-- to have zero data coupling with workdo/Training, so kept as its
-- own top-level module, not nested under Human Resources or
-- Training.
--
-- Deliberate scope trims vs. the reference (disclosed, not silent):
--   * No external-application-URL toggle -- every posting is
--     applied to through this app's own public form.
--   * No per-posting configurable "which fields to ask/show" JSON
--     (applicant/visibility) -- the public apply form always
--     collects the same standard candidate fields.
--   * Offer letter templates are single-language (no per-lang
--     array), stored as reusable named templates rather than one
--     template per language code.
--   * Candidate onboarding is a single overall-status record
--     against a reusable checklist template, matching the
--     reference's actual (minimal) behaviour: checklist_items are
--     a template list, not per-item completion tracking.
--
-- Mapping onto this app's existing data model (deliberate deviation
-- from the reference, which points everything at a generic `users`
-- table): "Convert Offer to Employee" inserts directly into the
-- existing hrm_employees table (reusing its own employee_no
-- generation and the same optional "Linked System User" dropdown
-- pattern already used on the Employee create form) rather than
-- always creating a brand-new login account. offers.department_id
-- and candidate_onboardings.buddy_employee_id point at
-- hrm_departments / hrm_employees for the same reason.
--
-- Run AFTER database/hrm_module.sql has been imported (for the
-- hrm_departments/hrm_employees FKs).
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS recruitment_candidate_onboardings;
DROP TABLE IF EXISTS recruitment_checklist_items;
DROP TABLE IF EXISTS recruitment_onboarding_checklists;
DROP TABLE IF EXISTS recruitment_offers;
DROP TABLE IF EXISTS recruitment_offer_letter_templates;
DROP TABLE IF EXISTS recruitment_candidate_assessments;
DROP TABLE IF EXISTS recruitment_interview_feedbacks;
DROP TABLE IF EXISTS recruitment_interviews;
DROP TABLE IF EXISTS recruitment_interview_rounds;
DROP TABLE IF EXISTS recruitment_candidates;
DROP TABLE IF EXISTS recruitment_job_postings;
DROP TABLE IF EXISTS recruitment_custom_questions;
DROP TABLE IF EXISTS recruitment_job_locations;
DROP TABLE IF EXISTS recruitment_interview_types;
DROP TABLE IF EXISTS recruitment_candidate_sources;
DROP TABLE IF EXISTS recruitment_job_types;
DROP TABLE IF EXISTS recruitment_settings;

-- ---------------------------------------------------------
-- Lookup tables
-- ---------------------------------------------------------
CREATE TABLE recruitment_job_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_candidate_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_interview_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_job_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    remote_work TINYINT(1) NOT NULL DEFAULT 0,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_custom_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255) NOT NULL,
    type ENUM('text','textarea','select','radio','checkbox','date','number') NOT NULL DEFAULT 'text',
    options TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- key/value store for public careers-page copy (About, Application
-- Tips, etc.) -- overall branding (logo/colors) reuses the existing
-- CompanySetting feature rather than duplicating it here.
CREATE TABLE recruitment_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Core pipeline
-- ---------------------------------------------------------
CREATE TABLE recruitment_job_postings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    posting_code VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    job_type_id INT NULL,
    location_id INT NULL,
    position INT NOT NULL DEFAULT 1,
    priority ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
    min_experience DECIMAL(4,1) NULL,
    max_experience DECIMAL(4,1) NULL,
    min_salary DECIMAL(14,2) NULL,
    max_salary DECIMAL(14,2) NULL,
    description TEXT NULL,
    requirements TEXT NULL,
    skills TEXT NULL,
    benefits TEXT NULL,
    terms_condition TEXT NULL,
    application_deadline DATE NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    publish_date DATE NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Draft','Active','Closed') NOT NULL DEFAULT 'Draft',
    custom_questions TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_type_id) REFERENCES recruitment_job_types(id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES recruitment_job_locations(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- status: New, Screening, Interview, Offer, Hired, Rejected.
-- tracking_id is what the public tracking page is keyed on --
-- unauthenticated, so it must not be guessable/sequential in
-- practice (generate_reference() includes random hex).
-- ---------------------------------------------------------
CREATE TABLE recruitment_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(50) NOT NULL UNIQUE,
    job_id INT NOT NULL,
    source_id INT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    gender ENUM('Male','Female','Other') NULL,
    dob DATE NULL,
    country VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    current_company VARCHAR(150) NULL,
    current_position VARCHAR(150) NULL,
    experience_years DECIMAL(4,1) NULL,
    current_salary DECIMAL(14,2) NULL,
    expected_salary DECIMAL(14,2) NULL,
    notice_period VARCHAR(100) NULL,
    skills TEXT NULL,
    education TEXT NULL,
    portfolio_url VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    profile_path VARCHAR(255) NULL,
    resume_path VARCHAR(255) NULL,
    cover_letter_path VARCHAR(255) NULL,
    status ENUM('New','Screening','Interview','Offer','Hired','Rejected') NOT NULL DEFAULT 'New',
    application_date DATE NOT NULL,
    custom_answers TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES recruitment_job_postings(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES recruitment_candidate_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_interview_rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    sequence_number INT NOT NULL DEFAULT 1,
    description TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES recruitment_job_postings(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- interviewer_ids: JSON array of users.id (interviewing requires a
-- login, matching the app-wide convention already used for
-- reviewer_id in the Performance module).
CREATE TABLE recruitment_interviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    job_id INT NOT NULL,
    round_id INT NULL,
    interview_type_id INT NULL,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NULL,
    duration INT NULL,
    location VARCHAR(200) NULL,
    meeting_link VARCHAR(255) NULL,
    interviewer_ids TEXT NULL,
    status ENUM('Scheduled','Completed','Cancelled','No-show') NOT NULL DEFAULT 'Scheduled',
    feedback_submitted TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES recruitment_job_postings(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES recruitment_interview_rounds(id) ON DELETE SET NULL,
    FOREIGN KEY (interview_type_id) REFERENCES recruitment_interview_types(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_interview_feedbacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    interview_id INT NOT NULL,
    technical_rating TINYINT NULL,
    communication_rating TINYINT NULL,
    cultural_fit_rating TINYINT NULL,
    overall_rating TINYINT NULL,
    strengths TEXT NULL,
    weaknesses TEXT NULL,
    comments TEXT NULL,
    recommendation ENUM('Strong Hire','Hire','Maybe','Reject','Strong Reject') NOT NULL DEFAULT 'Maybe',
    interviewer_ids TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (interview_id) REFERENCES recruitment_interviews(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_candidate_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    assessment_name VARCHAR(150) NOT NULL,
    score DECIMAL(6,2) NULL,
    max_score DECIMAL(6,2) NULL,
    pass_fail_status ENUM('Pass','Fail','Pending') NOT NULL DEFAULT 'Pending',
    comments TEXT NULL,
    assessment_date DATE NOT NULL,
    conducted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (conducted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_offer_letter_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    content LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- status: Draft, Sent, Accepted, Negotiating, Declined, Expired --
-- the offer's own lifecycle. approval_status is a separate,
-- smaller internal sign-off gate (Pending/Approved/Rejected),
-- matching the reference's two-track design.
-- ---------------------------------------------------------
CREATE TABLE recruitment_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    job_id INT NOT NULL,
    department_id INT NULL,
    offer_date DATE NOT NULL,
    position VARCHAR(150) NOT NULL,
    salary DECIMAL(14,2) NOT NULL,
    bonus DECIMAL(14,2) NULL,
    equity VARCHAR(100) NULL,
    benefits TEXT NULL,
    start_date DATE NOT NULL,
    expiration_date DATE NULL,
    offer_letter_path VARCHAR(255) NULL,
    status ENUM('Draft','Sent','Accepted','Negotiating','Declined','Expired') NOT NULL DEFAULT 'Draft',
    response_date DATE NULL,
    decline_reason TEXT NULL,
    approval_status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    approved_by INT NULL,
    converted_to_employee TINYINT(1) NOT NULL DEFAULT 0,
    employee_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES recruitment_job_postings(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES hrm_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_onboarding_checklists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recruitment_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checklist_id INT NOT NULL,
    task_name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NULL,
    assigned_to_role VARCHAR(100) NULL,
    due_day INT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (checklist_id) REFERENCES recruitment_onboarding_checklists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- buddy_employee_id points at hrm_employees (a real employee
-- mentoring the new hire) rather than the reference's generic user.
CREATE TABLE recruitment_candidate_onboardings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    checklist_id INT NULL,
    start_date DATE NOT NULL,
    buddy_employee_id INT NULL,
    status ENUM('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidate_id) REFERENCES recruitment_candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (checklist_id) REFERENCES recruitment_onboarding_checklists(id) ON DELETE SET NULL,
    FOREIGN KEY (buddy_employee_id) REFERENCES hrm_employees(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Permissions -- 2-key-per-module convention, matching the rest
-- of the app (no extra granular gates like the reference's
-- approve-offers/convert-offers-to-employees; both actions are
-- gated by recruitment.manage).
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('recruitment.view', 'View Recruitment Records', 'Recruitment'),
('recruitment.manage', 'Manage Recruitment Records (Postings, Candidates, Offers, Onboarding)', 'Recruitment');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'Recruitment';

SET FOREIGN_KEY_CHECKS = 1;
