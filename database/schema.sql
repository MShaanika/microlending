-- Standard Micro-Lending System Database Schema
-- Target DB: MySQL 8+
-- Engine: InnoDB
-- Charset: utf8mb4

SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS micro_lending_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE micro_lending_system;

-- =========================================================
-- PHASE 1: FOUNDATION / COMPANY / SECURITY
-- =========================================================

DROP TABLE IF EXISTS user_dashboard_widgets;
DROP TABLE IF EXISTS dashboard_widgets;
DROP TABLE IF EXISTS report_exports;
DROP TABLE IF EXISTS cash_collection_report_snapshots;
DROP TABLE IF EXISTS cash_disbursed_report_snapshots;
DROP TABLE IF EXISTS collection_performance_snapshots;
DROP TABLE IF EXISTS portfolio_aging_snapshots;
DROP TABLE IF EXISTS portfolio_snapshots;
DROP TABLE IF EXISTS report_runs;
DROP TABLE IF EXISTS report_definitions;
DROP TABLE IF EXISTS notification_settings;
DROP TABLE IF EXISTS notification_logs;
DROP TABLE IF EXISTS notification_queue;
DROP TABLE IF EXISTS notification_templates;
DROP TABLE IF EXISTS expense_approvals;
DROP TABLE IF EXISTS expense_attachments;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS document_template_versions;
DROP TABLE IF EXISTS document_delivery_logs;
DROP TABLE IF EXISTS document_attachments;
DROP TABLE IF EXISTS document_signatures;
DROP TABLE IF EXISTS generated_document_values;
DROP TABLE IF EXISTS generated_documents;
DROP TABLE IF EXISTS document_template_fields;
DROP TABLE IF EXISTS document_templates;
DROP TABLE IF EXISTS document_template_categories;
DROP TABLE IF EXISTS regulatory_exports;
DROP TABLE IF EXISTS salary_breakdown_bands;
DROP TABLE IF EXISTS loan_breakdown_size_bands;
DROP TABLE IF EXISTS duty_stamp_transactions;
DROP TABLE IF EXISTS namfisa_levy_transactions;
DROP TABLE IF EXISTS quarterly_report_snapshots;
DROP TABLE IF EXISTS regulatory_report_lines;
DROP TABLE IF EXISTS regulatory_reports;
DROP TABLE IF EXISTS regulatory_report_types;
DROP TABLE IF EXISTS duty_stamp_settings;
DROP TABLE IF EXISTS namfisa_levy_settings;
DROP TABLE IF EXISTS accounting_settings;
DROP TABLE IF EXISTS accounting_recurring_journal_lines;
DROP TABLE IF EXISTS accounting_recurring_journals;
DROP TABLE IF EXISTS accounting_tax_rates;
DROP TABLE IF EXISTS accounting_cash_book;
DROP TABLE IF EXISTS accounting_bank_reconciliation;
DROP TABLE IF EXISTS accounting_bank_statement;
DROP TABLE IF EXISTS accounting_event_rules;
DROP TABLE IF EXISTS accounting_journal_lines;
DROP TABLE IF EXISTS accounting_journal_entries;
DROP TABLE IF EXISTS accounting_bank_accounts;
DROP TABLE IF EXISTS accounting_periods;
DROP TABLE IF EXISTS accounting_fiscal_years;
DROP TABLE IF EXISTS accounting_accounts;
DROP TABLE IF EXISTS loan_recovery_allocations;
DROP TABLE IF EXISTS loan_recoveries;
DROP TABLE IF EXISTS loan_write_offs;
DROP TABLE IF EXISTS bad_debt_provisions;
DROP TABLE IF EXISTS bad_debts;
DROP TABLE IF EXISTS loan_reschedule_schedules;
DROP TABLE IF EXISTS loan_reschedules;
DROP TABLE IF EXISTS refund_claim_documents;
DROP TABLE IF EXISTS refund_claims;
DROP TABLE IF EXISTS case_escalations;
DROP TABLE IF EXISTS payment_promises;
DROP TABLE IF EXISTS collection_contacts;
DROP TABLE IF EXISTS overdue_notices;
DROP TABLE IF EXISTS arrears_tracking;
DROP TABLE IF EXISTS penalties;
DROP TABLE IF EXISTS debit_order_cancellations;
DROP TABLE IF EXISTS debit_order_run_lines;
DROP TABLE IF EXISTS debit_order_runs;
DROP TABLE IF EXISTS debit_orders;
DROP TABLE IF EXISTS collection_batch_lines;
DROP TABLE IF EXISTS collection_batches;
DROP TABLE IF EXISTS cash_collections;
DROP TABLE IF EXISTS payment_allocations;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS loan_guarantors;
DROP TABLE IF EXISTS loan_collaterals;
DROP TABLE IF EXISTS loan_disbursements;
DROP TABLE IF EXISTS loan_schedules;
DROP TABLE IF EXISTS loan_status_history;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS loan_plans;
DROP TABLE IF EXISTS loan_products;
DROP TABLE IF EXISTS online_application_otp;
DROP TABLE IF EXISTS application_upload_requirements;
DROP TABLE IF EXISTS loan_request_documents;
DROP TABLE IF EXISTS loan_requests;
DROP TABLE IF EXISTS rejected_applications;
DROP TABLE IF EXISTS loan_application_status_history;
DROP TABLE IF EXISTS loan_application_screening;
DROP TABLE IF EXISTS loan_application_documents;
DROP TABLE IF EXISTS loan_applications;
DROP TABLE IF EXISTS borrower_statements;
DROP TABLE IF EXISTS borrower_portal_sessions;
DROP TABLE IF EXISTS borrower_notifications;
DROP TABLE IF EXISTS borrower_portal_users;
DROP TABLE IF EXISTS borrower_documents;
DROP TABLE IF EXISTS borrower_bank_details;
DROP TABLE IF EXISTS borrower_employment;
DROP TABLE IF EXISTS borrower_contacts;
DROP TABLE IF EXISTS borrowers;
DROP TABLE IF EXISTS login_logs;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS companies;

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    brand_name VARCHAR(150),
    registration_no VARCHAR(100),
    namfisa_license_no VARCHAR(100),
    tax_no VARCHAR(100),
    email VARCHAR(150),
    phone VARCHAR(50),
    address TEXT,
    logo VARCHAR(255),
    primary_color VARCHAR(7) DEFAULT '#25a9e0',
    sidebar_color VARCHAR(7) DEFAULT NULL,
    footer_tagline VARCHAR(255) DEFAULT 'Your trusted Loan Manager',
    favicon VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_name VARCHAR(150) NOT NULL,
    branch_code VARCHAR(50) UNIQUE,
    phone VARCHAR(50),
    email VARCHAR(150),
    address TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(100) UNIQUE,
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    user_type ENUM('Super Admin','Admin','Manager','Loan Officer','Cashier','Accountant','Collector','Borrower') DEFAULT 'Admin',
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME NULL,
    password_reset_token VARCHAR(255) NULL,
    password_reset_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(150) NOT NULL UNIQUE,
    permission_name VARCHAR(150) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_permission (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_role (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(150) NOT NULL UNIQUE,
    setting_value TEXT,
    module_name VARCHAR(100),
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(150) NOT NULL,
    module_name VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(150),
    ip_address VARCHAR(50),
    user_agent TEXT,
    login_status ENUM('Success','Failed') DEFAULT 'Success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 2: BORROWERS + PORTAL
-- =========================================================

CREATE TABLE borrowers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    borrower_no VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    gender ENUM('Male','Female','Other') NULL,
    date_of_birth DATE NULL,
    id_number VARCHAR(50) UNIQUE,
    passport_no VARCHAR(50),
    marital_status VARCHAR(50),
    nationality VARCHAR(100) DEFAULT 'Namibian',
    phone VARCHAR(50),
    email VARCHAR(150),
    physical_address TEXT,
    postal_address TEXT,
    status ENUM('Pending','Approved','Rejected','Blacklisted','Inactive') DEFAULT 'Pending',
    created_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_contacts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    contact_type ENUM('Next of Kin','Employer','Guarantor','Emergency','Other') DEFAULT 'Next of Kin',
    full_name VARCHAR(150) NOT NULL,
    relationship VARCHAR(100),
    phone VARCHAR(50),
    email VARCHAR(150),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_employment (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    employer_name VARCHAR(150),
    employee_no VARCHAR(100),
    job_title VARCHAR(150),
    employment_type VARCHAR(100),
    employment_start_date DATE,
    gross_salary DECIMAL(18,2) DEFAULT 0,
    net_salary DECIMAL(18,2) DEFAULT 0,
    payment_day INT NULL,
    employer_phone VARCHAR(50),
    employer_email VARCHAR(150),
    employer_address TEXT,
    is_current TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_bank_details (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    bank_name VARCHAR(150),
    account_name VARCHAR(150),
    account_number VARCHAR(100),
    account_type VARCHAR(100),
    branch_name VARCHAR(150),
    branch_code VARCHAR(50),
    is_primary TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A point-in-time affordability worksheet snapshot (other income streams
-- beyond salary, monthly living expenses, existing contractual payments) --
-- the same breakdown the public application form collects from applicants.
-- Kept as its own table rather than more borrowers/borrower_employment
-- columns since it's a re-takeable snapshot (staff re-run this whenever
-- they reassess a borrower), not a fixed profile attribute.
CREATE TABLE borrower_affordability (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    commission DECIMAL(18,2) DEFAULT 0,
    pension DECIMAL(18,2) DEFAULT 0,
    business_income DECIMAL(18,2) DEFAULT 0,
    groceries DECIMAL(18,2) DEFAULT 0,
    school_fees DECIMAL(18,2) DEFAULT 0,
    transport DECIMAL(18,2) DEFAULT 0,
    home_loan DECIMAL(18,2) DEFAULT 0,
    home_rental DECIMAL(18,2) DEFAULT 0,
    credit_card DECIMAL(18,2) DEFAULT 0,
    personal_loans DECIMAL(18,2) DEFAULT 0,
    education_loan DECIMAL(18,2) DEFAULT 0,
    insurance DECIMAL(18,2) DEFAULT 0,
    car_payments DECIMAL(18,2) DEFAULT 0,
    cell_phone DECIMAL(18,2) DEFAULT 0,
    other_credit DECIMAL(18,2) DEFAULT 0,
    total_income DECIMAL(18,2) DEFAULT 0,
    total_expenses DECIMAL(18,2) DEFAULT 0,
    total_installments DECIMAL(18,2) DEFAULT 0,
    recorded_by INT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_name VARCHAR(150),
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE NULL,
    status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_portal_users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150),
    password VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME NULL,
    password_reset_token VARCHAR(255),
    password_reset_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    notification_type ENUM('SMS','Email','WhatsApp','Portal') DEFAULT 'Portal',
    subject VARCHAR(150),
    message TEXT NOT NULL,
    status ENUM('Pending','Sent','Failed','Read') DEFAULT 'Pending',
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_portal_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    portal_user_id BIGINT NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (portal_user_id) REFERENCES borrower_portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE borrower_statements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    statement_no VARCHAR(100) NOT NULL UNIQUE,
    period_from DATE,
    period_to DATE,
    file_path VARCHAR(255),
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 3: APPLICATIONS
-- =========================================================

-- One row per external client website/form that submits applications into
-- this system (e.g. a client's public "Apply Now" page). Onboarding a new
-- client with a differently-shaped form is a data change here + in
-- intake_field_mappings below, not a code change -- mirrors how
-- document_templates/document_template_fields let a new client's own letter
-- templates plug in without touching DocumentGenerationService.
CREATE TABLE intake_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_code VARCHAR(50) NOT NULL UNIQUE,
    source_name VARCHAR(150) NOT NULL,
    api_token VARCHAR(100) NOT NULL,
    allowed_origin VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps one intake source's raw form field names onto our canonical
-- loan_applications columns, or 'extra:<key>' to land in extra_data JSON
-- for fields with no dedicated column. ApplicationIntakeController reads
-- these rows to normalize $_POST -- it never hardcodes a client's field
-- names.
CREATE TABLE intake_field_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_source_id INT NOT NULL,
    incoming_field_name VARCHAR(100) NOT NULL,
    target_field VARCHAR(100) NOT NULL,
    is_required TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_source_field (intake_source_id, incoming_field_name),
    FOREIGN KEY (intake_source_id) REFERENCES intake_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lightweight anti-abuse throttle for the public intake endpoint (no
-- CAPTCHA infra exists yet) -- ApplicationIntakeController counts rows for
-- the same source+IP in the last hour before accepting a submission.
CREATE TABLE intake_submission_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    intake_source_id INT NOT NULL,
    ip_address VARCHAR(50) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (intake_source_id) REFERENCES intake_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_applications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    borrower_id BIGINT NULL,
    application_no VARCHAR(50) NOT NULL UNIQUE,
    application_source ENUM('Online','Back Office','Borrower Portal') DEFAULT 'Online',
    application_type ENUM('New Loan','Top-up','Consolidation','Repeat Loan') DEFAULT 'New Loan',
    requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    requested_term_months INT NOT NULL DEFAULT 1,
    requested_purpose TEXT,
    applicant_first_name VARCHAR(100),
    applicant_middle_name VARCHAR(100),
    applicant_last_name VARCHAR(100),
    applicant_id_number VARCHAR(50),
    applicant_phone VARCHAR(50),
    applicant_email VARCHAR(150),
    applicant_gender ENUM('Male','Female','Other') NULL,
    applicant_address TEXT,
    employer_name VARCHAR(150),
    employee_no VARCHAR(100),
    gross_salary DECIMAL(18,2) DEFAULT 0,
    net_salary DECIMAL(18,2) DEFAULT 0,
    payment_day INT NULL,
    bank_name VARCHAR(150),
    bank_account_name VARCHAR(150),
    bank_account_number VARCHAR(100),
    bank_branch_code VARCHAR(50),
    status ENUM('Submitted','Screening','Documents Required','Approved','Rejected','Converted to Loan','Cancelled') DEFAULT 'Submitted',
    rejection_reason TEXT,
    screened_by INT NULL,
    screened_at DATETIME NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    intake_source_id INT NULL,
    extra_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (screened_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (rejected_by) REFERENCES users(id),
    FOREIGN KEY (intake_source_id) REFERENCES intake_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_application_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    borrower_id BIGINT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_name VARCHAR(150),
    file_path VARCHAR(255) NOT NULL,
    status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',
    rejection_reason TEXT,
    uploaded_by INT NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_application_screening (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    affordability_score DECIMAL(10,2) DEFAULT 0,
    credit_score DECIMAL(10,2) DEFAULT 0,
    risk_level ENUM('Low','Medium','High','Rejected') DEFAULT 'Medium',
    gross_salary DECIMAL(18,2) DEFAULT 0,
    net_salary DECIMAL(18,2) DEFAULT 0,
    existing_deductions DECIMAL(18,2) DEFAULT 0,
    proposed_installment DECIMAL(18,2) DEFAULT 0,
    disposable_income DECIMAL(18,2) DEFAULT 0,
    debt_to_income_ratio DECIMAL(10,2) DEFAULT 0,
    screening_notes TEXT,
    recommendation ENUM('Approve','Reject','Request More Info') DEFAULT 'Request More Info',
    data_source ENUM('AI Bank Statement','Self-Reported') DEFAULT 'Self-Reported',
    screened_by INT NULL,
    screened_at DATETIME NULL,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (screened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_application_status_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    old_status VARCHAR(100),
    new_status VARCHAR(100) NOT NULL,
    notes TEXT,
    changed_by INT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_application_bank_analysis (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    source_document_ids JSON NULL,
    statement_format ENUM('Merged','Separate') NOT NULL,
    ingestion_method ENUM('AI Document Analysis','CSV Keyword Rules') NOT NULL DEFAULT 'AI Document Analysis',
    months_covered INT NULL,
    analysis_period_start DATE NULL,
    analysis_period_end DATE NULL,
    average_monthly_income DECIMAL(18,2) DEFAULT 0,
    average_monthly_expenses DECIMAL(18,2) DEFAULT 0,
    net_monthly_cash_flow DECIMAL(18,2) DEFAULT 0,
    average_closing_balance DECIMAL(18,2) DEFAULT 0,
    existing_commitments_total DECIMAL(18,2) DEFAULT 0,
    existing_commitments JSON NULL,
    expense_breakdown JSON NULL,
    transactions JSON NULL,
    risk_flags JSON NULL,
    nsf_count INT DEFAULT 0,
    ai_summary TEXT,
    model_used VARCHAR(100) NULL,
    status ENUM('Completed','Failed') DEFAULT 'Completed',
    error_message TEXT NULL,
    analyzed_by INT NULL,
    analyzed_at DATETIME NULL,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (analyzed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rejected_applications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    borrower_id BIGINT NULL,
    rejection_reason TEXT NOT NULL,
    rejection_category ENUM('Affordability','Incomplete Documents','Credit Risk','Blacklisted','Duplicate Application','Employer Verification Failed','Other') DEFAULT 'Other',
    rejected_by INT NULL,
    rejected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    request_type ENUM('New','Top Up') NOT NULL DEFAULT 'New',
    existing_loan_id BIGINT NULL,
    branch_id INT NULL,
    request_no VARCHAR(50) NOT NULL UNIQUE,
    requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    requested_term_months INT NOT NULL DEFAULT 1,
    purpose TEXT,
    status ENUM('Pending','Approved','Rejected','Converted to Application','Cancelled') DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id) ON DELETE CASCADE,
    FOREIGN KEY (existing_loan_id) REFERENCES loans(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_request_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_request_id BIGINT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_name VARCHAR(150) NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_request_id) REFERENCES loan_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE application_upload_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requirement_name VARCHAR(150) NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    is_required TINYINT(1) DEFAULT 1,
    applies_to ENUM('New Loan','Top-up','Consolidation','Repeat Loan','All') DEFAULT 'All',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE online_application_otp (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NULL,
    phone VARCHAR(50),
    email VARCHAR(150),
    otp_code VARCHAR(20) NOT NULL,
    purpose ENUM('Application Verification','Document Upload','Portal Registration') DEFAULT 'Application Verification',
    is_used TINYINT(1) DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 4: LOANS
-- =========================================================

CREATE TABLE loan_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(150) NOT NULL,
    description TEXT,
    min_amount DECIMAL(18,2) DEFAULT 0,
    max_amount DECIMAL(18,2) DEFAULT 0,
    min_term_months INT DEFAULT 1,
    max_term_months INT DEFAULT 1,
    interest_method ENUM('Flat','Reducing Balance','Fixed Fee') DEFAULT 'Flat',
    interest_rate DECIMAL(10,2) DEFAULT 0,
    penalty_rate DECIMAL(10,2) DEFAULT 0,
    service_fee DECIMAL(18,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    plan_name VARCHAR(150) NOT NULL,
    months INT NOT NULL,
    interest_rate DECIMAL(10,2) DEFAULT 0,
    penalty_rate DECIMAL(10,2) DEFAULT 0,
    admin_fee DECIMAL(18,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES loan_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loans (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    borrower_id BIGINT NOT NULL,
    application_id BIGINT NULL,
    topup_of_loan_id BIGINT NULL,
    product_id INT NOT NULL,
    plan_id INT NOT NULL,
    loan_no VARCHAR(50) NOT NULL UNIQUE,
    loan_type ENUM('New Loan','Top-up','Consolidation','Repeat Loan') DEFAULT 'New Loan',
    principal_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    interest_amount DECIMAL(18,2) DEFAULT 0,
    admin_fee DECIMAL(18,2) DEFAULT 0,
    total_payable DECIMAL(18,2) DEFAULT 0,
    installment_amount DECIMAL(18,2) DEFAULT 0,
    term_months INT NOT NULL DEFAULT 1,
    interest_rate DECIMAL(10,2) DEFAULT 0,
    penalty_rate DECIMAL(10,2) DEFAULT 0,
    purpose TEXT,
    payment_day INT NULL,
    loan_status ENUM('Draft','Pending Approval','Approved','Released','Active','Current','Completed','Denied','Written Off','Cancelled') DEFAULT 'Draft',
    approval_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    denied_by INT NULL,
    denied_at DATETIME NULL,
    denial_reason TEXT,
    released_by INT NULL,
    released_at DATETIME NULL,
    start_date DATE NULL,
    maturity_date DATE NULL,
    quarter_month DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (topup_of_loan_id) REFERENCES loans(id),
    FOREIGN KEY (product_id) REFERENCES loan_products(id),
    FOREIGN KEY (plan_id) REFERENCES loan_plans(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (denied_by) REFERENCES users(id),
    FOREIGN KEY (released_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_status_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    old_status VARCHAR(100),
    new_status VARCHAR(100) NOT NULL,
    notes TEXT,
    changed_by INT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_schedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    installment_no INT NOT NULL,
    due_date DATE NOT NULL,
    opening_balance DECIMAL(18,2) DEFAULT 0,
    principal_due DECIMAL(18,2) DEFAULT 0,
    interest_due DECIMAL(18,2) DEFAULT 0,
    fees_due DECIMAL(18,2) DEFAULT 0,
    namfisa_levy_due DECIMAL(18,2) NOT NULL DEFAULT 0,
    duty_stamp_due DECIMAL(18,2) NOT NULL DEFAULT 0,
    penalty_due DECIMAL(18,2) DEFAULT 0,
    total_due DECIMAL(18,2) DEFAULT 0,
    principal_paid DECIMAL(18,2) DEFAULT 0,
    interest_paid DECIMAL(18,2) DEFAULT 0,
    fees_paid DECIMAL(18,2) DEFAULT 0,
    namfisa_levy_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
    duty_stamp_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
    penalty_paid DECIMAL(18,2) DEFAULT 0,
    total_paid DECIMAL(18,2) DEFAULT 0,
    closing_balance DECIMAL(18,2) DEFAULT 0,
    status ENUM('Pending','Partial','Paid','In Arrears','Written Off') DEFAULT 'Pending',
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_loan_installment (loan_id, installment_no),
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_disbursements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    disbursement_no VARCHAR(50) NOT NULL UNIQUE,
    disbursement_date DATE NOT NULL,
    disbursement_method ENUM('Cash','Bank Transfer','Wallet','Other') DEFAULT 'Cash',
    bank_account_id BIGINT NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    reference_no VARCHAR(100),
    notes TEXT,
    status ENUM('Pending','Approved','Disbursed','Cancelled') DEFAULT 'Pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    disbursed_by INT NULL,
    disbursed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (disbursed_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_collaterals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    collateral_type VARCHAR(100),
    description TEXT,
    estimated_value DECIMAL(18,2) DEFAULT 0,
    document_path VARCHAR(255),
    status ENUM('Active','Released','Disposed') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_guarantors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    id_number VARCHAR(50),
    phone VARCHAR(50),
    email VARCHAR(150),
    relationship VARCHAR(100),
    address TEXT,
    guaranteed_amount DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- More phases continue below...

-- =========================================================
-- PHASE 5: PAYMENTS / COLLECTIONS / ARREARS / DEBIT ORDERS
-- =========================================================

CREATE TABLE payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    payment_no VARCHAR(50) NOT NULL UNIQUE,
    payment_date DATE NOT NULL,
    receipt_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    payment_method_id INT NULL,
    bank_account_id BIGINT NULL,
    payment_source ENUM('Cash','Debit Order','Bank Transfer','Wallet','Manual Adjustment','Other') DEFAULT 'Cash',
    amount_received DECIMAL(18,2) NOT NULL DEFAULT 0,
    principal_amount DECIMAL(18,2) DEFAULT 0,
    interest_amount DECIMAL(18,2) DEFAULT 0,
    fees_amount DECIMAL(18,2) DEFAULT 0,
    namfisa_levy_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    duty_stamp_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    penalty_amount DECIMAL(18,2) DEFAULT 0,
    overpayment_amount DECIMAL(18,2) DEFAULT 0,
    reference_no VARCHAR(100),
    payer_name VARCHAR(150),
    notes TEXT,
    status ENUM('Pending','Posted','Reversed','Cancelled') DEFAULT 'Posted',
    collected_by INT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    schedule_id BIGINT NULL,
    principal_allocated DECIMAL(18,2) DEFAULT 0,
    interest_allocated DECIMAL(18,2) DEFAULT 0,
    fees_allocated DECIMAL(18,2) DEFAULT 0,
    namfisa_levy_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    duty_stamp_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    penalty_allocated DECIMAL(18,2) DEFAULT 0,
    total_allocated DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cash_collections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    payment_id BIGINT NULL,
    collection_no VARCHAR(50) NOT NULL UNIQUE,
    collection_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    collected_by INT NULL,
    received_by INT NULL,
    reference_no VARCHAR(100),
    notes TEXT,
    status ENUM('Pending','Received','Posted','Cancelled') DEFAULT 'Received',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE collection_batches (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    batch_no VARCHAR(50) NOT NULL UNIQUE,
    batch_type ENUM('Cash','Debit Order','Bank Import','Manual') DEFAULT 'Cash',
    batch_date DATE NOT NULL,
    total_records INT DEFAULT 0,
    total_amount DECIMAL(18,2) DEFAULT 0,
    status ENUM('Draft','Submitted','Approved','Posted','Rejected','Cancelled') DEFAULT 'Draft',
    prepared_by INT NULL,
    approved_by INT NULL,
    posted_by INT NULL,
    approved_at DATETIME NULL,
    posted_at DATETIME NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (prepared_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE collection_batch_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    payment_id BIGINT NULL,
    expected_amount DECIMAL(18,2) DEFAULT 0,
    collected_amount DECIMAL(18,2) DEFAULT 0,
    reference_no VARCHAR(100),
    status ENUM('Pending','Collected','Failed','Posted') DEFAULT 'Pending',
    failure_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES collection_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    debit_order_no VARCHAR(50) NOT NULL UNIQUE,
    bank_name VARCHAR(150),
    account_name VARCHAR(150),
    account_number VARCHAR(100),
    branch_code VARCHAR(50),
    debit_day INT NOT NULL,
    debit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('Active','Suspended','Cancelled','Completed') DEFAULT 'Active',
    mandate_file VARCHAR(255),
    id_type TINYINT NULL,
    account_type TINYINT NOT NULL DEFAULT 1,
    bank_code VARCHAR(2) NULL,
    merchant_system_contract_no VARCHAR(10) NULL UNIQUE,
    no_of_days_tracking INT NOT NULL DEFAULT 3,
    collexia_status ENUM('Not Registered','Registered') NOT NULL DEFAULT 'Not Registered',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_order_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    run_no VARCHAR(50) NOT NULL UNIQUE,
    run_date DATE NOT NULL,
    debit_month VARCHAR(20),
    total_accounts INT DEFAULT 0,
    total_amount DECIMAL(18,2) DEFAULT 0,
    status ENUM('Draft','Generated','Submitted','Processed','Posted','Cancelled') DEFAULT 'Draft',
    generated_by INT NULL,
    posted_by INT NULL,
    generated_at DATETIME NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_order_run_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT NOT NULL,
    debit_order_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    debit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    bank_reference VARCHAR(100),
    response_code VARCHAR(50),
    response_message TEXT,
    status ENUM('Pending','Successful','Failed','Returned','Posted') DEFAULT 'Pending',
    payment_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES debit_order_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_order_collection_imports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255),
    report_type ENUM('Successful','Unsuccessful','Scheduled') NOT NULL DEFAULT 'Successful',
    total_rows INT DEFAULT 0,
    matched_rows INT DEFAULT 0,
    posted_payments INT DEFAULT 0,
    imported_by INT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_order_collections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT NOT NULL,
    debit_order_id BIGINT NULL,
    loan_id BIGINT NULL,
    merchant_system_contract_no VARCHAR(10),
    installment_no INT NULL,
    scheduled_date DATE NULL,
    installment_amount DECIMAL(18,2) DEFAULT 0,
    payment_date DATE NULL,
    payment_amount DECIMAL(18,2) NULL,
    installment_status VARCHAR(30),
    matched TINYINT(1) DEFAULT 0,
    payment_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_id) REFERENCES debit_order_collection_imports(id) ON DELETE CASCADE,
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE debit_order_cancellations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NULL,
    debit_order_id BIGINT NULL,
    cancellation_no VARCHAR(50) NOT NULL UNIQUE,
    cancellation_date DATE NOT NULL,
    amount_cancelled DECIMAL(18,2) DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('Pending','Approved','Cancelled','Rejected') DEFAULT 'Pending',
    requested_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    document_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE penalties (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    schedule_id BIGINT NULL,
    penalty_no VARCHAR(50) NOT NULL UNIQUE,
    penalty_date DATE NOT NULL,
    base_amount DECIMAL(18,2) DEFAULT 0,
    penalty_rate DECIMAL(10,2) DEFAULT 0,
    penalty_amount DECIMAL(18,2) DEFAULT 0,
    reason TEXT,
    status ENUM('Pending','Charged','Waived','Paid','Reversed') DEFAULT 'Charged',
    charged_by INT NULL,
    waived_by INT NULL,
    waived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id),
    FOREIGN KEY (charged_by) REFERENCES users(id),
    FOREIGN KEY (waived_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE arrears_tracking (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    schedule_id BIGINT NULL,
    as_at_date DATE NOT NULL,
    days_in_arrears INT DEFAULT 0,
    arrears_amount DECIMAL(18,2) DEFAULT 0,
    principal_arrears DECIMAL(18,2) DEFAULT 0,
    interest_arrears DECIMAL(18,2) DEFAULT 0,
    penalty_arrears DECIMAL(18,2) DEFAULT 0,
    aging_bucket ENUM('Current','1-30','31-60','61-90','91-180','180+') DEFAULT 'Current',
    status ENUM('Open','Resolved','Written Off') DEFAULT 'Open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (schedule_id) REFERENCES loan_schedules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE overdue_notices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    notice_no VARCHAR(50) NOT NULL UNIQUE,
    notice_type ENUM('SMS','Email','Letter','WhatsApp','Phone Call') DEFAULT 'SMS',
    notice_stage ENUM('Friendly Reminder','First Notice','Final Notice','Legal Notice') DEFAULT 'Friendly Reminder',
    arrears_amount DECIMAL(18,2) DEFAULT 0,
    days_in_arrears INT DEFAULT 0,
    message TEXT,
    status ENUM('Pending','Sent','Failed','Acknowledged') DEFAULT 'Pending',
    sent_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 6B: COLLECTIONS WORKLIST (contact log / promise to pay / escalation)
-- =========================================================

CREATE TABLE collection_contacts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    contact_method ENUM('Phone Call','SMS','Email','In-Person Visit','WhatsApp') NOT NULL,
    outcome ENUM('Promised to Pay','No Answer','Disputed Amount','Agreed to Pay','Refused to Pay') NOT NULL,
    notes TEXT NOT NULL,
    contacted_by INT NULL,
    contacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (contacted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_promises (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    promise_date DATE NOT NULL,
    expected_amount DECIMAL(18,2) DEFAULT 0,
    notes TEXT,
    status ENUM('Pending','Kept','Broken','Cancelled') DEFAULT 'Pending',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE case_escalations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    escalation_level ENUM('Supervisor','Manager','Legal','Collections Agency') NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Open','Resolved','Cancelled') DEFAULT 'Open',
    escalated_by INT NULL,
    escalated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    resolution_notes TEXT,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (escalated_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 7: ACCOUNTING CORE (created before Phase 6 journal refs)
-- =========================================================

CREATE TABLE accounting_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(50) NOT NULL UNIQUE,
    account_name VARCHAR(150) NOT NULL,
    account_type ENUM('Asset','Contra Asset','Liability','Equity','Income','Expense') NOT NULL,
    afs_line_code VARCHAR(60) NULL,
    normal_balance ENUM('Debit','Credit') NOT NULL,
    parent_account_id BIGINT NULL,
    is_control_account TINYINT(1) DEFAULT 0,
    is_cash_bank_account TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_fiscal_years (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    financial_year VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('Open','Closed') DEFAULT 'Open',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_periods (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year_id BIGINT NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed TINYINT(1) DEFAULT 0,
    closed_by INT NULL,
    closed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id) ON DELETE CASCADE,
    FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_bank_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    bank_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(100) NOT NULL,
    branch VARCHAR(150),
    branch_code VARCHAR(50),
    swift_code VARCHAR(50),
    account_id BIGINT NOT NULL,
    opening_balance DECIMAL(18,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE loan_disbursements
ADD CONSTRAINT fk_loan_disbursements_bank_account
FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id);

CREATE TABLE accounting_journal_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    journal_no VARCHAR(80) NOT NULL UNIQUE,
    journal_date DATE NOT NULL,
    fiscal_year_id BIGINT NULL,
    period_id BIGINT NULL,
    source_module VARCHAR(100),
    source_table VARCHAR(100),
    source_id BIGINT NULL,
    reference_no VARCHAR(150),
    description TEXT,
    journal_type ENUM('Manual','Adjustment','Recurring','Automatic','Reversal') DEFAULT 'Manual',
    status ENUM('Draft','Posted','Reversed','Cancelled') DEFAULT 'Posted',
    reversed_from BIGINT NULL,
    created_by INT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id),
    FOREIGN KEY (period_id) REFERENCES accounting_periods(id),
    FOREIGN KEY (reversed_from) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_journal_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    journal_id BIGINT NOT NULL,
    account_id BIGINT NOT NULL,
    description TEXT,
    debit DECIMAL(18,2) DEFAULT 0,
    credit DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_event_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL UNIQUE,
    debit_account_id BIGINT NOT NULL,
    credit_account_id BIGINT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (debit_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (credit_account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Templates for automated repetitive postings (rent, subscriptions, etc.).
-- A daily background job (see RecurringJournalService::processDue()) finds
-- templates whose next_run_date is due, posts a standard journal entry via
-- AccountingJournal::post(), then advances next_run_date -- or flips status
-- to Expired once the new next_run_date would fall after end_date.
CREATE TABLE recurring_journal_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_no VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL,
    debit_account_id BIGINT NOT NULL,
    credit_account_id BIGINT NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    frequency ENUM('Weekly','Monthly','Quarterly','Annually') NOT NULL DEFAULT 'Monthly',
    start_date DATE NOT NULL,
    next_run_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('Active','Inactive','Expired') NOT NULL DEFAULT 'Active',
    last_run_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (debit_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (credit_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_bank_statement (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id BIGINT NOT NULL,
    transaction_date DATE NOT NULL,
    reference_no VARCHAR(150),
    description VARCHAR(255),
    money_in DECIMAL(18,2) DEFAULT 0,
    money_out DECIMAL(18,2) DEFAULT 0,
    balance DECIMAL(18,2) DEFAULT 0,
    reconciled TINYINT(1) DEFAULT 0,
    imported_by INT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
    FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_bank_reconciliation (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_statement_id BIGINT NOT NULL,
    journal_line_id BIGINT NOT NULL,
    matched_amount DECIMAL(18,2) DEFAULT 0,
    reconciliation_status ENUM('Matched','Manual','Unmatched') DEFAULT 'Matched',
    reconciled_by INT NULL,
    reconciled_at DATETIME NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_statement_id) REFERENCES accounting_bank_statement(id) ON DELETE CASCADE,
    FOREIGN KEY (journal_line_id) REFERENCES accounting_journal_lines(id),
    FOREIGN KEY (reconciled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records each "Complete Reconciliation" click: once completed, every
-- posted journal line against that bank account's GL account dated on or
-- before statement_date is locked (cannot be reversed) unless the row is
-- later reopened, or the user holds accounting.reconciliation_override.
-- Only the latest non-reopened row per bank_account_id is the active cutoff.
CREATE TABLE bank_reconciliation_completions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id BIGINT NOT NULL,
    statement_date DATE NOT NULL,
    completed_by INT NULL,
    completed_at DATETIME NOT NULL,
    reopened_by INT NULL,
    reopened_at DATETIME NULL,
    FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
    FOREIGN KEY (completed_by) REFERENCES users(id),
    FOREIGN KEY (reopened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_cash_book (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id BIGINT NOT NULL,
    journal_id BIGINT NULL,
    transaction_date DATE NOT NULL,
    reference_no VARCHAR(150),
    description TEXT,
    cash_in DECIMAL(18,2) DEFAULT 0,
    cash_out DECIMAL(18,2) DEFAULT 0,
    running_balance DECIMAL(18,2) DEFAULT 0,
    source_module VARCHAR(100),
    source_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_tax_rates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tax_name VARCHAR(100) NOT NULL,
    tax_code VARCHAR(50) NOT NULL UNIQUE,
    rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_type ENUM('VAT','Withholding','Income Tax','Other') DEFAULT 'VAT',
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_recurring_journals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    journal_name VARCHAR(150) NOT NULL,
    frequency ENUM('Daily','Weekly','Monthly','Quarterly','Yearly') DEFAULT 'Monthly',
    next_run DATE NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_recurring_journal_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recurring_journal_id BIGINT NOT NULL,
    account_id BIGINT NOT NULL,
    description TEXT,
    debit DECIMAL(18,2) DEFAULT 0,
    credit DECIMAL(18,2) DEFAULT 0,
    FOREIGN KEY (recurring_journal_id) REFERENCES accounting_recurring_journals(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounting_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(150) NOT NULL UNIQUE,
    setting_value TEXT,
    module_name VARCHAR(100) DEFAULT 'Accounting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 6: REFUNDS / RESCHEDULES / BAD DEBTS / WRITE-OFFS / RECOVERIES
-- =========================================================

CREATE TABLE refund_claims (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NOT NULL,
    loan_id BIGINT NULL,
    branch_id INT NOT NULL,
    claim_no VARCHAR(50) NOT NULL UNIQUE,
    claim_date DATE NOT NULL,
    claim_type ENUM('Overpayment','Duplicate Payment','Early Settlement','Debit Order Error','Other') DEFAULT 'Overpayment',
    claim_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    approved_amount DECIMAL(18,2) DEFAULT 0,
    reason TEXT NOT NULL,
    bank_name VARCHAR(150),
    account_name VARCHAR(150),
    account_number VARCHAR(100),
    branch_code VARCHAR(50),
    status ENUM('Pending','Under Review','Approved','Rejected','Paid','Cancelled') DEFAULT 'Pending',
    requested_by BIGINT NULL,
    reviewed_by INT NULL,
    approved_by INT NULL,
    paid_by INT NULL,
    reviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejection_reason TEXT,
    document_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (requested_by) REFERENCES borrowers(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (paid_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refund_claim_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    refund_claim_id BIGINT NOT NULL,
    document_type VARCHAR(100),
    document_name VARCHAR(150),
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (refund_claim_id) REFERENCES refund_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_reschedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    reschedule_no VARCHAR(50) NOT NULL UNIQUE,
    request_date DATE NOT NULL,
    effective_date DATE NOT NULL,
    old_installment_amount DECIMAL(18,2) DEFAULT 0,
    new_installment_amount DECIMAL(18,2) DEFAULT 0,
    old_term_months INT DEFAULT 0,
    new_term_months INT DEFAULT 0,
    old_payment_day INT NULL,
    new_payment_day INT NULL,
    old_maturity_date DATE NULL,
    new_maturity_date DATE NULL,
    outstanding_balance DECIMAL(18,2) DEFAULT 0,
    interest_adjustment DECIMAL(18,2) DEFAULT 0,
    fee_adjustment DECIMAL(18,2) DEFAULT 0,
    waived_amount DECIMAL(18,2) DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('Pending','Approved','Rejected','Implemented','Cancelled') DEFAULT 'Pending',
    requested_by INT NULL,
    reviewed_by INT NULL,
    approved_by INT NULL,
    implemented_by INT NULL,
    reviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    implemented_at DATETIME NULL,
    rejection_reason TEXT,
    agreement_file VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (implemented_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_reschedule_schedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reschedule_id BIGINT NOT NULL,
    loan_id BIGINT NOT NULL,
    installment_no INT NOT NULL,
    due_date DATE NOT NULL,
    principal_due DECIMAL(18,2) DEFAULT 0,
    interest_due DECIMAL(18,2) DEFAULT 0,
    fees_due DECIMAL(18,2) DEFAULT 0,
    namfisa_levy_due DECIMAL(18,2) DEFAULT 0,
    duty_stamp_due DECIMAL(18,2) DEFAULT 0,
    penalty_due DECIMAL(18,2) DEFAULT 0,
    total_due DECIMAL(18,2) DEFAULT 0,
    status ENUM('Pending','Active','Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reschedule_id) REFERENCES loan_reschedules(id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bad_debts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    bad_debt_no VARCHAR(50) NOT NULL UNIQUE,
    identified_date DATE NOT NULL,
    outstanding_balance DECIMAL(18,2) DEFAULT 0,
    days_in_arrears INT DEFAULT 0,
    aging_bucket ENUM('31-60','61-90','91-180','180+') DEFAULT '180+',
    reason TEXT,
    status ENUM('Open','Under Recovery','Provisioned','Written Off','Recovered','Closed') DEFAULT 'Open',
    identified_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (identified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bad_debt_provisions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    bad_debt_id BIGINT NULL,
    provision_no VARCHAR(50) NOT NULL UNIQUE,
    provision_date DATE NOT NULL,
    outstanding_balance DECIMAL(18,2) DEFAULT 0,
    aging_days INT DEFAULT 0,
    provision_rate DECIMAL(10,2) DEFAULT 0,
    provision_amount DECIMAL(18,2) DEFAULT 0,
    status ENUM('Preview','Posted','Reversed') DEFAULT 'Preview',
    journal_id BIGINT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (bad_debt_id) REFERENCES bad_debts(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_write_offs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    bad_debt_id BIGINT NULL,
    write_off_no VARCHAR(50) NOT NULL UNIQUE,
    write_off_date DATE NOT NULL,
    loan_amount DECIMAL(18,2) DEFAULT 0,
    total_paid DECIMAL(18,2) DEFAULT 0,
    outstanding_balance DECIMAL(18,2) DEFAULT 0,
    provision_amount DECIMAL(18,2) DEFAULT 0,
    net_write_off_amount DECIMAL(18,2) DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('Pending','Approved','Posted','Rejected','Reversed') DEFAULT 'Pending',
    requested_by INT NULL,
    approved_by INT NULL,
    posted_by INT NULL,
    approved_at DATETIME NULL,
    posted_at DATETIME NULL,
    journal_id BIGINT NULL,
    approval_document VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (bad_debt_id) REFERENCES bad_debts(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_recoveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    write_off_id BIGINT NULL,
    recovery_no VARCHAR(50) NOT NULL UNIQUE,
    recovery_date DATE NOT NULL,
    recovered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    reference_no VARCHAR(100),
    notes TEXT,
    status ENUM('Pending','Posted','Reversed','Cancelled') DEFAULT 'Posted',
    received_by INT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    journal_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (write_off_id) REFERENCES loan_write_offs(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (received_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_recovery_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recovery_id BIGINT NOT NULL,
    write_off_id BIGINT NULL,
    loan_id BIGINT NOT NULL,
    amount_allocated DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recovery_id) REFERENCES loan_recoveries(id) ON DELETE CASCADE,
    FOREIGN KEY (write_off_id) REFERENCES loan_write_offs(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 8: COMPLIANCE / NAMFISA / DUTY STAMP
-- =========================================================

CREATE TABLE namfisa_levy_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    levy_name VARCHAR(150) NOT NULL,
    levy_rate DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    calculation_basis ENUM('Principal Amount','Interest Amount','Total Payable','Outstanding Balance') DEFAULT 'Principal Amount',
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE duty_stamp_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stamp_name VARCHAR(150) NOT NULL,
    stamp_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    calculation_type ENUM('Fixed','Percentage') DEFAULT 'Fixed',
    calculation_basis ENUM('Per Loan','Principal Amount','Total Payable') DEFAULT 'Per Loan',
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE regulatory_report_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(80) NOT NULL UNIQUE,
    report_name VARCHAR(150) NOT NULL,
    frequency ENUM('Monthly','Quarterly','Annually','On Demand') DEFAULT 'Quarterly',
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE regulatory_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_type_id INT NOT NULL,
    branch_id INT NULL,
    report_no VARCHAR(80) NOT NULL UNIQUE,
    report_period VARCHAR(50),
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_loans INT DEFAULT 0,
    total_principal DECIMAL(18,2) DEFAULT 0,
    total_interest DECIMAL(18,2) DEFAULT 0,
    total_collections DECIMAL(18,2) DEFAULT 0,
    total_bad_debts DECIMAL(18,2) DEFAULT 0,
    total_recoveries DECIMAL(18,2) DEFAULT 0,
    total_namfisa_levy DECIMAL(18,2) DEFAULT 0,
    total_duty_stamp DECIMAL(18,2) DEFAULT 0,
    status ENUM('Draft','Generated','Submitted','Approved','Rejected') DEFAULT 'Draft',
    generated_by INT NULL,
    submitted_by INT NULL,
    approved_by INT NULL,
    generated_at DATETIME NULL,
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    file_path VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_type_id) REFERENCES regulatory_report_types(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE regulatory_report_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    regulatory_report_id BIGINT NOT NULL,
    source_module VARCHAR(100),
    source_table VARCHAR(100),
    source_id BIGINT NULL,
    loan_id BIGINT NULL,
    borrower_id BIGINT NULL,
    line_category VARCHAR(150),
    line_description TEXT,
    gender ENUM('Male','Female','Other') NULL,
    salary_band VARCHAR(100),
    loan_size_band VARCHAR(100),
    loan_count INT DEFAULT 0,
    principal_amount DECIMAL(18,2) DEFAULT 0,
    interest_amount DECIMAL(18,2) DEFAULT 0,
    outstanding_amount DECIMAL(18,2) DEFAULT 0,
    collection_amount DECIMAL(18,2) DEFAULT 0,
    bad_debt_amount DECIMAL(18,2) DEFAULT 0,
    recovery_amount DECIMAL(18,2) DEFAULT 0,
    levy_amount DECIMAL(18,2) DEFAULT 0,
    duty_stamp_amount DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (regulatory_report_id) REFERENCES regulatory_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MLR Summarised Management Report -- one flexible table for all 8 sections
-- of the real NAMFISA filing (month-grouped where the filing wants it), since
-- regulatory_report_lines' flat category/gender/salary/size shape doesn't fit.
CREATE TABLE mlr_report_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    regulatory_report_id BIGINT NOT NULL,
    section VARCHAR(30) NOT NULL,
    month_key VARCHAR(7) NULL,
    month_label VARCHAR(20) NULL,
    label VARCHAR(150) NULL,
    capital_amount DECIMAL(18,2) DEFAULT 0,
    interest_amount DECIMAL(18,2) DEFAULT 0,
    total_amount DECIMAL(18,2) DEFAULT 0,
    loan_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (regulatory_report_id) REFERENCES regulatory_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Annual Financial Statement Analysis (AFS_ANNUAL): one flexible row shape
-- reused across 3 different section layouts (quarterly summary, bank
-- accounts, fixed assets) -- see AfsReportGenerationService for the exact
-- column-meaning mapping per section.
CREATE TABLE afs_report_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    regulatory_report_id BIGINT NOT NULL,
    section VARCHAR(30) NOT NULL,
    label VARCHAR(150) NOT NULL,
    sub_label VARCHAR(150) NULL,
    amount_1 DECIMAL(18,2) DEFAULT 0,
    amount_2 DECIMAL(18,2) DEFAULT 0,
    amount_3 DECIMAL(18,2) DEFAULT 0,
    amount_4 DECIMAL(18,2) DEFAULT 0,
    amount_5 DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (regulatory_report_id) REFERENCES regulatory_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quarterly_report_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    snapshot_no VARCHAR(80) NOT NULL UNIQUE,
    quarter ENUM('Q1','Q2','Q3','Q4') NOT NULL,
    year INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_borrowers INT DEFAULT 0,
    total_new_loans INT DEFAULT 0,
    total_active_loans INT DEFAULT 0,
    total_completed_loans INT DEFAULT 0,
    total_written_off_loans INT DEFAULT 0,
    total_disbursed DECIMAL(18,2) DEFAULT 0,
    total_collected DECIMAL(18,2) DEFAULT 0,
    total_outstanding DECIMAL(18,2) DEFAULT 0,
    total_bad_debts DECIMAL(18,2) DEFAULT 0,
    total_recoveries DECIMAL(18,2) DEFAULT 0,
    total_namfisa_levy DECIMAL(18,2) DEFAULT 0,
    total_duty_stamp DECIMAL(18,2) DEFAULT 0,
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE namfisa_levy_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    levy_setting_id BIGINT NULL,
    levy_date DATE NOT NULL,
    levy_rate DECIMAL(10,4) DEFAULT 0,
    basis_amount DECIMAL(18,2) DEFAULT 0,
    levy_amount DECIMAL(18,2) DEFAULT 0,
    status ENUM('Calculated','Posted','Submitted','Reversed') DEFAULT 'Calculated',
    journal_id BIGINT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (levy_setting_id) REFERENCES namfisa_levy_settings(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE duty_stamp_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    branch_id INT NOT NULL,
    duty_stamp_setting_id BIGINT NULL,
    stamp_date DATE NOT NULL,
    basis_amount DECIMAL(18,2) DEFAULT 0,
    stamp_amount DECIMAL(18,2) DEFAULT 0,
    status ENUM('Calculated','Posted','Submitted','Reversed') DEFAULT 'Calculated',
    journal_id BIGINT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (duty_stamp_setting_id) REFERENCES duty_stamp_settings(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per top-up consolidation event (see TopUpService::consolidate()).
-- Snapshots the loan/schedule/statutory-charge state as it stood immediately
-- before the consolidation, so a mistaken top-up can be fully undone --
-- restoring principal/schedule/term and reversing the incremental journal --
-- rather than only being correctable by a manual accounting adjustment.
CREATE TABLE loan_topups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    loan_id BIGINT NOT NULL,
    topup_amount DECIMAL(18,2) NOT NULL,
    journal_id BIGINT NULL,
    disbursement_id BIGINT NULL,
    previous_loan_snapshot JSON NOT NULL,
    previous_schedule_snapshot JSON NOT NULL,
    previous_namfisa_levy_snapshot JSON NULL,
    previous_duty_stamp_snapshot JSON NULL,
    status ENUM('Active','Reversed') DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reversed_by INT NULL,
    reversed_at DATETIME NULL,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (disbursement_id) REFERENCES loan_disbursements(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (reversed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_breakdown_size_bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    band_name VARCHAR(100) NOT NULL,
    min_amount DECIMAL(18,2) DEFAULT 0,
    max_amount DECIMAL(18,2) DEFAULT 0,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE salary_breakdown_bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    band_name VARCHAR(100) NOT NULL,
    min_salary DECIMAL(18,2) DEFAULT 0,
    max_salary DECIMAL(18,2) DEFAULT 0,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE regulatory_exports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    regulatory_report_id BIGINT NULL,
    export_no VARCHAR(80) NOT NULL UNIQUE,
    export_type ENUM('PDF','Excel','CSV','Word') DEFAULT 'Excel',
    file_path VARCHAR(255),
    exported_by INT NULL,
    exported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (regulatory_report_id) REFERENCES regulatory_reports(id),
    FOREIGN KEY (exported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 9: DOCUMENT / LETTER ENGINE
-- =========================================================

CREATE TABLE document_template_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    template_code VARCHAR(80) NOT NULL UNIQUE,
    template_name VARCHAR(150) NOT NULL,
    template_type ENUM('Completion Letter','Consolidation Letter','Refund Claim Form','Refund Claim Letter','Debit Order Cancellation Letter','Loan Reschedule Letter','Statement of Account','Agreement','Notice','Other') DEFAULT 'Other',
    file_type ENUM('DOCX','PDF','HTML') DEFAULT 'DOCX',
    file_path VARCHAR(255) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES document_template_categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_template_fields (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_label VARCHAR(150),
    source_table VARCHAR(100),
    source_column VARCHAR(100),
    default_value TEXT,
    is_required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_template_field (template_id, field_key),
    FOREIGN KEY (template_id) REFERENCES document_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE generated_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT NULL,
    document_no VARCHAR(80) NOT NULL UNIQUE,
    document_title VARCHAR(150) NOT NULL,
    borrower_id BIGINT NULL,
    loan_id BIGINT NULL,
    application_id BIGINT NULL,
    refund_claim_id BIGINT NULL,
    reschedule_id BIGINT NULL,
    debit_order_cancellation_id BIGINT NULL,
    source_module VARCHAR(100),
    source_table VARCHAR(100),
    source_id BIGINT NULL,
    output_type ENUM('DOCX','PDF','HTML') DEFAULT 'PDF',
    file_path VARCHAR(255),
    status ENUM('Draft','Generated','Sent','Signed','Cancelled') DEFAULT 'Generated',
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    signed_at DATETIME NULL,
    FOREIGN KEY (template_id) REFERENCES document_templates(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (refund_claim_id) REFERENCES refund_claims(id),
    FOREIGN KEY (reschedule_id) REFERENCES loan_reschedules(id),
    FOREIGN KEY (debit_order_cancellation_id) REFERENCES debit_order_cancellations(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE generated_document_values (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generated_document_id BIGINT NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_document_id) REFERENCES generated_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_signatures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generated_document_id BIGINT NOT NULL,
    signer_type ENUM('Borrower','Company','Witness','Guarantor','Staff','Other') DEFAULT 'Borrower',
    signer_name VARCHAR(150),
    signer_id_number VARCHAR(50),
    signer_email VARCHAR(150),
    signer_phone VARCHAR(50),
    signature_file VARCHAR(255),
    signature_method ENUM('Upload','Drawn','Digital','Manual') DEFAULT 'Upload',
    signed_at DATETIME NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_document_id) REFERENCES generated_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_attachments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generated_document_id BIGINT NOT NULL,
    attachment_name VARCHAR(150),
    attachment_type VARCHAR(100),
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_document_id) REFERENCES generated_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_delivery_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generated_document_id BIGINT NOT NULL,
    delivery_channel ENUM('Email','SMS Link','WhatsApp Link','Portal','Manual') DEFAULT 'Portal',
    recipient_name VARCHAR(150),
    recipient_contact VARCHAR(150),
    status ENUM('Pending','Sent','Failed','Delivered','Read') DEFAULT 'Pending',
    response_message TEXT,
    sent_by INT NULL,
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_document_id) REFERENCES generated_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_template_versions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT NOT NULL,
    version_no VARCHAR(50) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    change_notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES document_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 10: EXPENSES + NOTIFICATIONS
-- =========================================================

CREATE TABLE expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL UNIQUE,
    account_id BIGINT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    category_id INT NOT NULL,
    expense_no VARCHAR(80) NOT NULL UNIQUE,
    expense_date DATE NOT NULL,
    supplier_name VARCHAR(150),
    description TEXT NOT NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) DEFAULT 0,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    bank_account_id BIGINT NULL,
    reference_no VARCHAR(150),
    status ENUM('Draft','Pending Approval','Approved','Paid','Rejected','Cancelled') DEFAULT 'Draft',
    captured_by INT NULL,
    approved_by INT NULL,
    paid_by INT NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejection_reason TEXT,
    journal_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (bank_account_id) REFERENCES accounting_bank_accounts(id),
    FOREIGN KEY (captured_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (paid_by) REFERENCES users(id),
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expense_attachments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT NOT NULL,
    attachment_name VARCHAR(150),
    attachment_type VARCHAR(100),
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expense_approvals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT NOT NULL,
    approval_level INT DEFAULT 1,
    approver_id INT NOT NULL,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    comments TEXT,
    actioned_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_code VARCHAR(100) NOT NULL UNIQUE,
    template_name VARCHAR(150) NOT NULL,
    channel ENUM('SMS','Email','WhatsApp','Portal') DEFAULT 'SMS',
    subject VARCHAR(150),
    message_body TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    borrower_id BIGINT NULL,
    user_id INT NULL,
    template_id BIGINT NULL,
    channel ENUM('SMS','Email','WhatsApp','Portal') DEFAULT 'SMS',
    recipient_name VARCHAR(150),
    recipient_contact VARCHAR(150),
    subject VARCHAR(150),
    message TEXT NOT NULL,
    source_module VARCHAR(100),
    source_table VARCHAR(100),
    source_id BIGINT NULL,
    status ENUM('Pending','Processing','Sent','Failed','Cancelled') DEFAULT 'Pending',
    attempts INT DEFAULT 0,
    last_error TEXT,
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES notification_templates(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT NULL,
    borrower_id BIGINT NULL,
    user_id INT NULL,
    channel ENUM('SMS','Email','WhatsApp','Portal') DEFAULT 'SMS',
    recipient_contact VARCHAR(150),
    message TEXT,
    status ENUM('Sent','Failed','Read','Delivered') DEFAULT 'Sent',
    provider_reference VARCHAR(150),
    response_message TEXT,
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (notification_id) REFERENCES notification_queue(id) ON DELETE SET NULL,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(150) NOT NULL UNIQUE,
    setting_value TEXT,
    channel ENUM('SMS','Email','WhatsApp','Portal','AI') DEFAULT 'SMS',
    is_active TINYINT(1) DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 11: REPORTS DASHBOARD / PORTFOLIO PERFORMANCE
-- =========================================================

CREATE TABLE report_definitions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(100) NOT NULL UNIQUE,
    report_name VARCHAR(150) NOT NULL,
    report_category ENUM('Operational','Financial','Regulatory','Collections','Portfolio','Accounting','Audit','Other') DEFAULT 'Operational',
    description TEXT,
    default_frequency ENUM('Daily','Weekly','Monthly','Quarterly','Annually','On Demand') DEFAULT 'On Demand',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_definition_id BIGINT NOT NULL,
    branch_id INT NULL,
    run_no VARCHAR(80) NOT NULL UNIQUE,
    period_from DATE NULL,
    period_to DATE NULL,
    filters_json JSON NULL,
    status ENUM('Queued','Processing','Completed','Failed','Cancelled') DEFAULT 'Completed',
    file_type ENUM('PDF','Excel','CSV','HTML') DEFAULT 'HTML',
    file_path VARCHAR(255),
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT,
    FOREIGN KEY (report_definition_id) REFERENCES report_definitions(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dashboard_widgets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    widget_code VARCHAR(100) NOT NULL UNIQUE,
    widget_name VARCHAR(150) NOT NULL,
    widget_category ENUM('Loans','Collections','Accounting','Compliance','Portfolio','Expenses','Notifications','Other') DEFAULT 'Loans',
    description TEXT,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_dashboard_widgets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    widget_id BIGINT NOT NULL,
    display_order INT DEFAULT 0,
    is_visible TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_widget (user_id, widget_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portfolio_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    snapshot_date DATE NOT NULL,
    snapshot_type ENUM('Daily','Monthly','Quarterly','Yearly') DEFAULT 'Daily',
    total_borrowers INT DEFAULT 0,
    total_active_borrowers INT DEFAULT 0,
    total_loans INT DEFAULT 0,
    total_active_loans INT DEFAULT 0,
    total_current_loans INT DEFAULT 0,
    total_completed_loans INT DEFAULT 0,
    total_written_off_loans INT DEFAULT 0,
    total_principal_disbursed DECIMAL(18,2) DEFAULT 0,
    total_interest_expected DECIMAL(18,2) DEFAULT 0,
    total_amount_payable DECIMAL(18,2) DEFAULT 0,
    total_collected DECIMAL(18,2) DEFAULT 0,
    total_principal_collected DECIMAL(18,2) DEFAULT 0,
    total_interest_collected DECIMAL(18,2) DEFAULT 0,
    total_penalty_collected DECIMAL(18,2) DEFAULT 0,
    total_outstanding DECIMAL(18,2) DEFAULT 0,
    principal_outstanding DECIMAL(18,2) DEFAULT 0,
    interest_outstanding DECIMAL(18,2) DEFAULT 0,
    penalty_outstanding DECIMAL(18,2) DEFAULT 0,
    total_arrears DECIMAL(18,2) DEFAULT 0,
    loans_in_arrears INT DEFAULT 0,
    total_bad_debts DECIMAL(18,2) DEFAULT 0,
    total_provisions DECIMAL(18,2) DEFAULT 0,
    total_write_offs DECIMAL(18,2) DEFAULT 0,
    total_recoveries DECIMAL(18,2) DEFAULT 0,
    portfolio_at_risk_30 DECIMAL(10,2) DEFAULT 0,
    portfolio_at_risk_60 DECIMAL(10,2) DEFAULT 0,
    portfolio_at_risk_90 DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_branch_snapshot (branch_id, snapshot_date, snapshot_type),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portfolio_aging_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    portfolio_snapshot_id BIGINT NOT NULL,
    aging_bucket ENUM('Current','1-30','31-60','61-90','91-180','180+') NOT NULL,
    loan_count INT DEFAULT 0,
    outstanding_amount DECIMAL(18,2) DEFAULT 0,
    arrears_amount DECIMAL(18,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (portfolio_snapshot_id) REFERENCES portfolio_snapshots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE collection_performance_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    snapshot_date DATE NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    expected_collections DECIMAL(18,2) DEFAULT 0,
    actual_collections DECIMAL(18,2) DEFAULT 0,
    collection_rate DECIMAL(10,2) DEFAULT 0,
    missed_collections DECIMAL(18,2) DEFAULT 0,
    over_collections DECIMAL(18,2) DEFAULT 0,
    total_payments INT DEFAULT 0,
    failed_debit_orders INT DEFAULT 0,
    successful_debit_orders INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cash_disbursed_report_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    report_date DATE NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    total_disbursements INT DEFAULT 0,
    total_cash_disbursed DECIMAL(18,2) DEFAULT 0,
    total_bank_disbursed DECIMAL(18,2) DEFAULT 0,
    total_wallet_disbursed DECIMAL(18,2) DEFAULT 0,
    total_other_disbursed DECIMAL(18,2) DEFAULT 0,
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cash_collection_report_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    report_date DATE NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    total_collections INT DEFAULT 0,
    total_cash_collected DECIMAL(18,2) DEFAULT 0,
    total_debit_order_collected DECIMAL(18,2) DEFAULT 0,
    total_bank_transfer_collected DECIMAL(18,2) DEFAULT 0,
    total_wallet_collected DECIMAL(18,2) DEFAULT 0,
    total_other_collected DECIMAL(18,2) DEFAULT 0,
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_exports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_run_id BIGINT NULL,
    report_code VARCHAR(100),
    export_no VARCHAR(80) NOT NULL UNIQUE,
    export_type ENUM('PDF','Excel','CSV','Word') DEFAULT 'Excel',
    file_path VARCHAR(255),
    exported_by INT NULL,
    exported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_run_id) REFERENCES report_runs(id) ON DELETE SET NULL,
    FOREIGN KEY (exported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- PHASE 12: INDEXES
-- =========================================================

ALTER TABLE borrowers ADD INDEX idx_borrowers_branch (branch_id), ADD INDEX idx_borrowers_status (status), ADD INDEX idx_borrowers_gender (gender), ADD INDEX idx_borrowers_id_number (id_number), ADD INDEX idx_borrowers_phone (phone);
ALTER TABLE borrower_employment ADD INDEX idx_employment_borrower (borrower_id), ADD INDEX idx_employment_employer (employer_name), ADD INDEX idx_employment_salary (gross_salary, net_salary);
ALTER TABLE borrower_documents ADD INDEX idx_documents_borrower (borrower_id), ADD INDEX idx_documents_type_status (document_type, status);
ALTER TABLE loan_applications ADD INDEX idx_applications_borrower (borrower_id), ADD INDEX idx_applications_branch (branch_id), ADD INDEX idx_applications_status (status), ADD INDEX idx_applications_source (application_source), ADD INDEX idx_applications_id_number (applicant_id_number), ADD INDEX idx_applications_created (created_at);
ALTER TABLE loan_application_status_history ADD INDEX idx_application_history_application (application_id), ADD INDEX idx_application_history_status (new_status);
ALTER TABLE loans ADD INDEX idx_loans_borrower (borrower_id), ADD INDEX idx_loans_branch (branch_id), ADD INDEX idx_loans_status (loan_status), ADD INDEX idx_loans_approval_status (approval_status), ADD INDEX idx_loans_released_at (released_at), ADD INDEX idx_loans_start_maturity (start_date, maturity_date), ADD INDEX idx_loans_quarter_month (quarter_month);
ALTER TABLE loan_schedules ADD INDEX idx_schedule_loan (loan_id), ADD INDEX idx_schedule_due_date (due_date), ADD INDEX idx_schedule_status (status), ADD INDEX idx_schedule_loan_due (loan_id, due_date);
ALTER TABLE loan_disbursements ADD INDEX idx_disbursement_loan (loan_id), ADD INDEX idx_disbursement_borrower (borrower_id), ADD INDEX idx_disbursement_date (disbursement_date), ADD INDEX idx_disbursement_status (status);
ALTER TABLE payments ADD INDEX idx_payments_loan (loan_id), ADD INDEX idx_payments_borrower (borrower_id), ADD INDEX idx_payments_branch (branch_id), ADD INDEX idx_payments_date (payment_date), ADD INDEX idx_payments_status (status), ADD INDEX idx_payments_source (payment_source), ADD INDEX idx_payments_reference (reference_no);
ALTER TABLE payment_allocations ADD INDEX idx_allocations_payment (payment_id), ADD INDEX idx_allocations_loan (loan_id), ADD INDEX idx_allocations_schedule (schedule_id);
ALTER TABLE cash_collections ADD INDEX idx_cash_collections_branch (branch_id), ADD INDEX idx_cash_collections_borrower (borrower_id), ADD INDEX idx_cash_collections_loan (loan_id), ADD INDEX idx_cash_collections_date (collection_date), ADD INDEX idx_cash_collections_status (status);
ALTER TABLE collection_batches ADD INDEX idx_collection_batches_branch (branch_id), ADD INDEX idx_collection_batches_date (batch_date), ADD INDEX idx_collection_batches_status (status);
ALTER TABLE debit_orders ADD INDEX idx_debit_orders_borrower (borrower_id), ADD INDEX idx_debit_orders_loan (loan_id), ADD INDEX idx_debit_orders_status (status), ADD INDEX idx_debit_orders_day (debit_day);
ALTER TABLE debit_order_runs ADD INDEX idx_debit_runs_branch (branch_id), ADD INDEX idx_debit_runs_date (run_date), ADD INDEX idx_debit_runs_status (status);
ALTER TABLE penalties ADD INDEX idx_penalties_loan (loan_id), ADD INDEX idx_penalties_borrower (borrower_id), ADD INDEX idx_penalties_date (penalty_date), ADD INDEX idx_penalties_status (status);
ALTER TABLE arrears_tracking ADD INDEX idx_arrears_loan (loan_id), ADD INDEX idx_arrears_borrower (borrower_id), ADD INDEX idx_arrears_date (as_at_date), ADD INDEX idx_arrears_bucket (aging_bucket), ADD INDEX idx_arrears_status (status);
ALTER TABLE refund_claims ADD INDEX idx_refund_borrower (borrower_id), ADD INDEX idx_refund_loan (loan_id), ADD INDEX idx_refund_branch (branch_id), ADD INDEX idx_refund_status (status), ADD INDEX idx_refund_date (claim_date);
ALTER TABLE loan_reschedules ADD INDEX idx_reschedule_loan (loan_id), ADD INDEX idx_reschedule_borrower (borrower_id), ADD INDEX idx_reschedule_branch (branch_id), ADD INDEX idx_reschedule_status (status), ADD INDEX idx_reschedule_effective_date (effective_date);
ALTER TABLE bad_debts ADD INDEX idx_bad_debts_loan (loan_id), ADD INDEX idx_bad_debts_borrower (borrower_id), ADD INDEX idx_bad_debts_branch (branch_id), ADD INDEX idx_bad_debts_status (status), ADD INDEX idx_bad_debts_aging (aging_bucket);
ALTER TABLE bad_debt_provisions ADD INDEX idx_provisions_loan (loan_id), ADD INDEX idx_provisions_borrower (borrower_id), ADD INDEX idx_provisions_date (provision_date), ADD INDEX idx_provisions_status (status), ADD INDEX idx_provisions_journal (journal_id);
ALTER TABLE loan_write_offs ADD INDEX idx_writeoffs_loan (loan_id), ADD INDEX idx_writeoffs_borrower (borrower_id), ADD INDEX idx_writeoffs_branch (branch_id), ADD INDEX idx_writeoffs_date (write_off_date), ADD INDEX idx_writeoffs_status (status), ADD INDEX idx_writeoffs_journal (journal_id);
ALTER TABLE loan_recoveries ADD INDEX idx_recoveries_loan (loan_id), ADD INDEX idx_recoveries_borrower (borrower_id), ADD INDEX idx_recoveries_branch (branch_id), ADD INDEX idx_recoveries_date (recovery_date), ADD INDEX idx_recoveries_status (status), ADD INDEX idx_recoveries_journal (journal_id);
ALTER TABLE accounting_accounts ADD INDEX idx_accounts_type (account_type), ADD INDEX idx_accounts_active (is_active), ADD INDEX idx_accounts_cash_bank (is_cash_bank_account);
ALTER TABLE accounting_journal_entries ADD INDEX idx_journal_date (journal_date), ADD INDEX idx_journal_status (status), ADD INDEX idx_journal_source (source_module, source_table, source_id), ADD INDEX idx_journal_type (journal_type), ADD INDEX idx_journal_period (period_id), ADD INDEX idx_journal_reference (reference_no);
ALTER TABLE accounting_journal_lines ADD INDEX idx_journal_lines_journal (journal_id), ADD INDEX idx_journal_lines_account (account_id), ADD INDEX idx_journal_lines_account_journal (account_id, journal_id);
ALTER TABLE accounting_bank_statement ADD INDEX idx_bank_statement_account (bank_account_id), ADD INDEX idx_bank_statement_date (transaction_date), ADD INDEX idx_bank_statement_reference (reference_no), ADD INDEX idx_bank_statement_reconciled (reconciled);
ALTER TABLE accounting_bank_reconciliation ADD INDEX idx_bank_recon_statement (bank_statement_id), ADD INDEX idx_bank_recon_line (journal_line_id), ADD INDEX idx_bank_recon_date (reconciled_at);
ALTER TABLE accounting_cash_book ADD INDEX idx_cash_book_bank (bank_account_id), ADD INDEX idx_cash_book_journal (journal_id), ADD INDEX idx_cash_book_date (transaction_date), ADD INDEX idx_cash_book_source (source_module, source_id);
ALTER TABLE generated_documents ADD INDEX idx_generated_borrower (borrower_id), ADD INDEX idx_generated_loan (loan_id), ADD INDEX idx_generated_source (source_module, source_table, source_id), ADD INDEX idx_generated_status (status);
ALTER TABLE notification_queue ADD INDEX idx_notification_borrower (borrower_id), ADD INDEX idx_notification_user (user_id), ADD INDEX idx_notification_status (status), ADD INDEX idx_notification_channel (channel), ADD INDEX idx_notification_scheduled (scheduled_at);
ALTER TABLE notification_logs ADD INDEX idx_notification_logs_borrower (borrower_id), ADD INDEX idx_notification_logs_status (status), ADD INDEX idx_notification_logs_channel (channel);
ALTER TABLE regulatory_reports ADD INDEX idx_reg_reports_type (report_type_id), ADD INDEX idx_reg_reports_period (period_start, period_end), ADD INDEX idx_reg_reports_status (status);
ALTER TABLE regulatory_report_lines ADD INDEX idx_reg_lines_report (regulatory_report_id), ADD INDEX idx_reg_lines_loan (loan_id), ADD INDEX idx_reg_lines_borrower (borrower_id), ADD INDEX idx_reg_lines_category (line_category);
ALTER TABLE portfolio_snapshots ADD INDEX idx_portfolio_snapshot_date (snapshot_date), ADD INDEX idx_portfolio_snapshot_type (snapshot_type);
ALTER TABLE report_runs ADD INDEX idx_report_runs_definition (report_definition_id), ADD INDEX idx_report_runs_period (period_from, period_to), ADD INDEX idx_report_runs_status (status);
ALTER TABLE audit_logs ADD INDEX idx_audit_user (user_id), ADD INDEX idx_audit_action (action), ADD INDEX idx_audit_module (module_name), ADD INDEX idx_audit_created (created_at);
ALTER TABLE login_logs ADD INDEX idx_login_user (user_id), ADD INDEX idx_login_email (email), ADD INDEX idx_login_status (login_status), ADD INDEX idx_login_created (created_at);

-- =========================================================
-- SEED DATA
-- =========================================================

INSERT INTO companies (company_name, registration_no, namfisa_license_no, email, phone, address)
VALUES ('Solid Desert Cash Loan Express CC', 'CC/0000/0000', 'NAMFISA-0000', 'info@solid-desert.com', '+264', 'Namibia');

INSERT INTO branches (company_id, branch_name, branch_code, is_active)
VALUES (1, 'Head Office', 'HO', 1);

INSERT INTO roles (role_name, description) VALUES
('Super Admin', 'Full system access'),
('Admin', 'Administrative access'),
('Manager', 'Branch and approval access'),
('Loan Officer', 'Borrower and loan processing access'),
('Cashier', 'Cash collection and disbursement access'),
('Accountant', 'Accounting and reporting access'),
('Collector', 'Collections and arrears access'),
('Borrower', 'Borrower portal access');

INSERT INTO users (branch_id, name, username, email, password, user_type, is_active)
VALUES (1, 'System Administrator', 'admin', 'admin@example.com', '$2y$10$556SFV4bhH5WTohgCguEt.vUWIqffRIQM6LOH8hChiyJ/yBfoEnI6', 'Super Admin', 1);

INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r ON r.role_name = 'Super Admin' WHERE u.username='admin';

INSERT INTO application_upload_requirements (requirement_name, document_type, is_required, applies_to, is_active) VALUES
('National ID Copy', 'ID Copy', 1, 'All', 1),
('Latest Payslip', 'Payslip', 1, 'All', 1),
('Bank Statement', 'Bank Statement', 1, 'All', 1),
('Employment Confirmation', 'Employment Confirmation', 0, 'All', 1),
('Debit Order Authority', 'Debit Order Authority', 1, 'All', 1);

INSERT INTO loan_products (product_code, product_name, min_amount, max_amount, min_term_months, max_term_months, interest_method, interest_rate, penalty_rate, is_active) VALUES
('STD-CASH', 'Standard Cash Loan', 100, 50000, 1, 24, 'Flat', 20.00, 5.00, 1),
('SALARY-ADV', 'Salary Advance Loan', 100, 15000, 1, 3, 'Flat', 15.00, 5.00, 1);

INSERT INTO loan_plans (product_id, plan_name, months, interest_rate, penalty_rate, admin_fee, is_active) VALUES
(1, '1 Month Standard', 1, 20.00, 5.00, 0.00, 1),
(1, '3 Months Standard', 3, 20.00, 5.00, 0.00, 1),
(1, '6 Months Standard', 6, 20.00, 5.00, 0.00, 1),
(1, '12 Months Standard', 12, 20.00, 5.00, 0.00, 1),
(2, '1 Month Salary Advance', 1, 15.00, 5.00, 0.00, 1);

INSERT INTO payment_methods (method_name, description, is_active) VALUES
('Cash', 'Cash received at branch', 1),
('Debit Order', 'Debit order collection', 1),
('Bank Transfer', 'EFT or bank transfer', 1),
('Wallet', 'Wallet payment', 1),
('Manual Adjustment', 'Internal adjustment', 1);

INSERT INTO accounting_accounts (account_code, account_name, account_type, normal_balance, is_control_account, is_cash_bank_account, is_active) VALUES
('1000', 'Assets', 'Asset', 'Debit', 1, 0, 1),
('1010', 'Bank Account', 'Asset', 'Debit', 1, 1, 1),
('1020', 'Loans Receivable', 'Asset', 'Debit', 1, 0, 1),
('1030', 'Interest Receivable', 'Asset', 'Debit', 1, 0, 1),
('1040', 'Penalty Receivable', 'Asset', 'Debit', 1, 0, 1),
('1050', 'Provision for Doubtful Debts', 'Contra Asset', 'Credit', 1, 0, 1),
('2000', 'Liabilities', 'Liability', 'Credit', 1, 0, 1),
('2010', 'Accounts Payable', 'Liability', 'Credit', 1, 0, 1),
('2020', 'Refunds Payable', 'Liability', 'Credit', 1, 0, 1),
('2030', 'NAMFISA Levy Payable', 'Liability', 'Credit', 1, 0, 1),
('2040', 'Duty Stamp Payable', 'Liability', 'Credit', 1, 0, 1),
('3000', 'Equity', 'Equity', 'Credit', 1, 0, 1),
('3010', 'Owner Capital', 'Equity', 'Credit', 1, 0, 1),
('3020', 'Retained Earnings', 'Equity', 'Credit', 1, 0, 1),
('4000', 'Income', 'Income', 'Credit', 1, 0, 1),
('4010', 'Interest Income', 'Income', 'Credit', 0, 0, 1),
('4020', 'Penalty Income', 'Income', 'Credit', 0, 0, 1),
('4030', 'Admin Fee Income', 'Income', 'Credit', 0, 0, 1),
('4040', 'Bad Debt Recovery Income', 'Income', 'Credit', 0, 0, 1),
('5000', 'Expenses', 'Expense', 'Debit', 1, 0, 1),
('5010', 'Bad Debt Expense', 'Expense', 'Debit', 0, 0, 1),
('5020', 'Bank Charges', 'Expense', 'Debit', 0, 0, 1),
('5030', 'Operating Expenses', 'Expense', 'Debit', 0, 0, 1),
('5040', 'Salary Expenses', 'Expense', 'Debit', 0, 0, 1);

-- Annual Financial Statement (AFS) export line-item tagging and supporting
-- accounts -- see App\Services\AfsReportService. Every account below feeds
-- exactly one named line of the client's Profit & Loss / Balance Sheet /
-- Cash Flow export template.
UPDATE accounting_accounts SET afs_line_code = 'pl_interest_income' WHERE account_code = '4010';
UPDATE accounting_accounts SET afs_line_code = 'pl_opex_bad_debts' WHERE account_code = '5010';
UPDATE accounting_accounts SET afs_line_code = 'pl_opex_bank_charges' WHERE account_code = '5020';
UPDATE accounting_accounts SET afs_line_code = 'pl_opex_salaries_wages' WHERE account_code = '5040';
UPDATE accounting_accounts SET afs_line_code = 'pl_opex_depreciation' WHERE account_code = '5050';
UPDATE accounting_accounts SET afs_line_code = 'bs_movable_assets' WHERE account_code = '1060';
UPDATE accounting_accounts SET afs_line_code = 'bs_accounts_payable' WHERE account_code = '2010';
UPDATE accounting_accounts SET afs_line_code = 'bs_retained_profit' WHERE account_code = '3020';
-- These are the accounts the app actually posts loan disbursement/collection
-- to -- tag them directly rather than adding unused parallel accounts, or
-- the export silently omits the loan book, regulatory payables and other
-- lending income from the AFS.
UPDATE accounting_accounts SET afs_line_code = 'bs_loan_to_members' WHERE account_code = '1020';
UPDATE accounting_accounts SET afs_line_code = 'bs_members_contributions' WHERE account_code = '3010';
UPDATE accounting_accounts SET afs_line_code = 'bs_accounts_payable' WHERE account_code IN ('2020', '2030', '2040');
UPDATE accounting_accounts SET afs_line_code = 'pl_interest_income' WHERE account_code IN ('4020', '4030', '4040');
UPDATE accounting_accounts SET afs_line_code = 'bs_provision_doubtful_debts' WHERE account_code = '1050';
-- Penalty Receivable only ever gets a real balance once penalty accrual
-- runs (see App\Services\PenaltyAccrualService) -- tag the real account
-- rather than leaving the unused 1130 placeholder as its own line.
UPDATE accounting_accounts SET afs_line_code = 'bs_receivables_prepayments' WHERE account_code = '1040';

INSERT INTO accounting_accounts (account_code, account_name, account_type, afs_line_code, normal_balance, is_control_account, is_cash_bank_account, is_active) VALUES
('4015', 'Interest Received from Investments', 'Income', 'pl_interest_investment', 'Credit', 0, 0, 1),
('5100', 'Document Storage Fees', 'Expense', 'pl_cos_document_storage', 'Debit', 0, 0, 1),
('5101', 'Subscriptions & Service Provider Fees', 'Expense', 'pl_cos_subscriptions', 'Debit', 0, 0, 1),
('5102', 'Annuality (BIPA Fees & SSC)', 'Expense', 'pl_cos_annuality', 'Debit', 0, 0, 1),
('5103', 'NAMFISA Levies (Cost of Sale)', 'Expense', 'pl_cos_namfisa_levy', 'Debit', 0, 0, 1),
('5104', 'License Fees (NAMFISA Renewal)', 'Expense', 'pl_cos_license_fees', 'Debit', 0, 0, 1),
('5105', 'AFS Rounding Difference (Cost of Sale)', 'Expense', 'pl_cos_rounding', 'Debit', 0, 0, 1),
('5200', 'Accounting Officer Fees', 'Expense', 'pl_opex_accounting_officer', 'Debit', 0, 0, 1),
('5201', 'Administration Fees', 'Expense', 'pl_opex_admin', 'Debit', 0, 0, 1),
('5202', 'Advertising and Promotions', 'Expense', 'pl_opex_advertising', 'Debit', 0, 0, 1),
('5203', 'Building Maintenance', 'Expense', 'pl_opex_building_maintenance', 'Debit', 0, 0, 1),
('5204', 'Cleaning', 'Expense', 'pl_opex_cleaning', 'Debit', 0, 0, 1),
('5205', 'Consulting Fees', 'Expense', 'pl_opex_consulting', 'Debit', 0, 0, 1),
('5206', 'Computer Expenses', 'Expense', 'pl_opex_computer', 'Debit', 0, 0, 1),
('5207', 'Courier and Postage', 'Expense', 'pl_opex_courier', 'Debit', 0, 0, 1),
('5208', 'Employee Welfare', 'Expense', 'pl_opex_employee_welfare', 'Debit', 0, 0, 1),
('5209', 'Freight on Goods Purchased', 'Expense', 'pl_opex_freight', 'Debit', 0, 0, 1),
('5210', 'General Expenses', 'Expense', 'pl_opex_general', 'Debit', 0, 0, 1),
('5211', 'Insurance', 'Expense', 'pl_opex_insurance', 'Debit', 0, 0, 1),
('5212', 'Interest Paid', 'Expense', 'pl_opex_interest_paid', 'Debit', 0, 0, 1),
('5213', 'Rent Payment', 'Expense', 'pl_opex_rent', 'Debit', 0, 0, 1),
('5214', 'Legal Fees', 'Expense', 'pl_opex_legal', 'Debit', 0, 0, 1),
('5215', 'Medical Expenses', 'Expense', 'pl_opex_medical', 'Debit', 0, 0, 1),
('5216', 'Members Salaries', 'Expense', 'pl_opex_members_salaries', 'Debit', 0, 0, 1),
('5217', 'Motor Vehicle Rental', 'Expense', 'pl_opex_vehicle_rental', 'Debit', 0, 0, 1),
('5218', 'Municipal Expenses', 'Expense', 'pl_opex_municipal', 'Debit', 0, 0, 1),
('5219', 'Office Supplies', 'Expense', 'pl_opex_office_supplies', 'Debit', 0, 0, 1),
('5220', 'Printing and Stationery', 'Expense', 'pl_opex_printing', 'Debit', 0, 0, 1),
('5221', 'Fuel, Repairs and Maintenance of Vehicle', 'Expense', 'pl_opex_vehicle_maintenance', 'Debit', 0, 0, 1),
('5222', 'Security Services', 'Expense', 'pl_opex_security', 'Debit', 0, 0, 1),
('5223', 'Telephone and Fax', 'Expense', 'pl_opex_telephone', 'Debit', 0, 0, 1),
('5224', 'Transport on Goods Purchased', 'Expense', 'pl_opex_transport', 'Debit', 0, 0, 1),
('5225', 'Travel, Entertainment and Accommodation', 'Expense', 'pl_opex_travel', 'Debit', 0, 0, 1),
('5226', 'Uniform (Staff)', 'Expense', 'pl_opex_uniform', 'Debit', 0, 0, 1),
('5300', 'Finance Cost', 'Expense', 'pl_finance_cost', 'Debit', 0, 0, 1),
('5301', 'Taxation', 'Expense', 'pl_taxation', 'Debit', 0, 0, 1),
('1100', 'Land & Building', 'Asset', 'bs_land_building', 'Debit', 0, 0, 1),
('1110', 'Inventory', 'Asset', 'bs_inventory', 'Debit', 0, 0, 1),
('1140', 'Investments (Non-current)', 'Asset', 'cf_investments_made', 'Debit', 0, 0, 1),
('3110', 'Distributions to Members', 'Equity', 'cf_distributions_members', 'Debit', 0, 0, 1),
('2050', 'Deferred Penalty Income', 'Liability', 'bs_accounts_payable', 'Credit', 0, 0, 1),
('2100', 'Interest Bearing Borrowings (Loans from Member)', 'Liability', 'bs_interest_bearing_borrowings', 'Credit', 0, 0, 1),
('2101', 'Long-term Borrowings (Asset Finance)', 'Liability', 'bs_longterm_borrowings', 'Credit', 0, 0, 1),
('2110', 'Tax Payable', 'Liability', 'bs_tax_payable', 'Credit', 0, 0, 1),
('2120', 'Bank Overdrafts', 'Liability', 'bs_bank_overdrafts', 'Credit', 0, 0, 1);

INSERT INTO accounting_event_rules (event_name, debit_account_id, credit_account_id, is_active) VALUES
('LOAN_RELEASED', (SELECT id FROM accounting_accounts WHERE account_code='1020'), (SELECT id FROM accounting_accounts WHERE account_code='1010'), 1),
('PAYMENT_RECEIVED', (SELECT id FROM accounting_accounts WHERE account_code='1010'), (SELECT id FROM accounting_accounts WHERE account_code='1020'), 1),
('PENALTY_CHARGED', (SELECT id FROM accounting_accounts WHERE account_code='1040'), (SELECT id FROM accounting_accounts WHERE account_code='4020'), 1),
('MONTHLY_PROVISION', (SELECT id FROM accounting_accounts WHERE account_code='5010'), (SELECT id FROM accounting_accounts WHERE account_code='1050'), 1),
('LOAN_WRITTEN_OFF', (SELECT id FROM accounting_accounts WHERE account_code='1050'), (SELECT id FROM accounting_accounts WHERE account_code='1020'), 1),
('RECOVERY_RECEIVED', (SELECT id FROM accounting_accounts WHERE account_code='1010'), (SELECT id FROM accounting_accounts WHERE account_code='4040'), 1);

INSERT INTO namfisa_levy_settings (levy_name, levy_rate, calculation_basis, effective_from, is_active)
VALUES ('NAMFISA Levy', 1.0300, 'Principal Amount', '2026-01-01', 1);

INSERT INTO duty_stamp_settings (stamp_name, stamp_amount, calculation_type, calculation_basis, effective_from, is_active)
VALUES ('Duty Stamp', 5.00, 'Fixed', 'Per Loan', '2026-01-01', 1);

INSERT INTO regulatory_report_types (report_code, report_name, frequency, description, is_active) VALUES
('BAD_DEBTS_QTR', 'Bad Debts Quarterly Report', 'Quarterly', 'Quarterly report of bad debts.', 1),
('BAD_DEBT_RECOVERY_QTR', 'Bad Debt Recovery Quarterly Report', 'Quarterly', 'Quarterly report of bad debt recoveries.', 1),
('LOAN_SIZE_GENDER_QTR', 'Loan Breakdown by Size and Gender Quarterly Report', 'Quarterly', 'Breakdown of loans by size and gender.', 1),
('LOAN_GENDER_QTR', 'Loan Breakdown by Gender Quarterly Report', 'Quarterly', 'Breakdown of loans by borrower gender.', 1),
('LOAN_SALARY_QTR', 'Loan Breakdown by Salary Quarterly Report', 'Quarterly', 'Breakdown of loans by borrower salary band.', 1),
('NAMFISA_LEVY_QTR', 'NAMFISA Levy Quarterly Report', 'Quarterly', 'Quarterly NAMFISA levy report.', 1),
('DUTY_STAMP_QTR', 'Duty Stamp Quarterly Report', 'Quarterly', 'Quarterly duty stamp report.', 1),
('CURRENT_LOAN_QTR', 'Current Loan Quarterly Report', 'Quarterly', 'Quarterly current loan portfolio report.', 1),
('MLR_SUMMARISED_QTR', 'MLR Summarised Management Report', 'Quarterly', 'Consolidated NAMFISA quarterly filing: disbursements, gender/size breakdown, loan book balance, write-offs, expenses, interest income and levies, all by month.', 1),
('AFS_ANNUAL', 'Annual Financial Statement Analysis', 'Annually', 'Annual financial statement summary for the company financial year (April-March): quarterly income/expense summary, bank accounts, and fixed assets register.', 1);

INSERT INTO loan_breakdown_size_bands (band_name, min_amount, max_amount, display_order, is_active) VALUES
('0 - 1,000', 0, 1000, 1, 1),
('1,001 - 5,000', 1001, 5000, 2, 1),
('5,001 - 10,000', 5001, 10000, 3, 1),
('10,001 - 20,000', 10001, 20000, 4, 1),
('20,001 - 50,000', 20001, 50000, 5, 1),
('50,001+', 50001, 999999999, 6, 1);

INSERT INTO salary_breakdown_bands (band_name, min_salary, max_salary, display_order, is_active) VALUES
('0 - 2,000', 0, 2000, 1, 1),
('2,001 - 5,000', 2001, 5000, 2, 1),
('5,001 - 10,000', 5001, 10000, 3, 1),
('10,001 - 20,000', 10001, 20000, 4, 1),
('20,001 - 50,000', 20001, 50000, 5, 1),
('50,001+', 50001, 999999999, 6, 1);

INSERT INTO document_template_categories (category_name, description, is_active) VALUES
('Loan Letters', 'Letters related to loan lifecycle.', 1),
('Refund Claims', 'Refund claim forms and letters.', 1),
('Debit Orders', 'Debit order cancellation and authority documents.', 1),
('Reschedules', 'Loan reschedule agreements and notices.', 1),
('Statements', 'Borrower account statements.', 1),
('Compliance', 'Regulatory and compliance documents.', 1);

INSERT INTO document_templates (category_id, template_code, template_name, template_type, file_type, file_path, description, is_active) VALUES
(1, 'COMPLETION_LETTER', 'Completion Letter', 'Completion Letter', 'DOCX', 'document_templates/completion_letter.docx', 'Issued when a borrower completes repayment.', 1),
(1, 'CONSOLIDATION_ONE_LOAN', 'Consolidation Letter - One Loan', 'Consolidation Letter', 'DOCX', 'document_templates/consolidation_one_loan.docx', 'Consolidation letter for one selected loan.', 1),
(1, 'CONSOLIDATION_ALL_LOANS', 'Consolidation Letter - All Client Loans', 'Consolidation Letter', 'DOCX', 'document_templates/consolidation_all_loans.docx', 'Consolidation letter combining all active client loans.', 1),
(2, 'REFUND_CLAIM_FORM', 'Refund Claim Form', 'Refund Claim Form', 'DOCX', 'document_templates/refund_claim_form.docx', 'Form completed for refund claims.', 1),
(2, 'REFUND_CLAIM_LETTER', 'Refund Claim Letter', 'Refund Claim Letter', 'DOCX', 'document_templates/refund_claim_letter.docx', 'Letter issued to client for refund claim.', 1),
(3, 'DEBIT_ORDER_CANCELLATION', 'Debit Order Cancellation Letter', 'Debit Order Cancellation Letter', 'DOCX', 'templates/debit_order_cancellation.docx', 'Letter for cancelling debit order instruction.', 1),
(4, 'LOAN_RESCHEDULE_LETTER', 'Loan Reschedule Letter', 'Loan Reschedule Letter', 'DOCX', 'templates/loan_reschedule_letter.docx', 'Letter confirming approved loan reschedule.', 1),
(5, 'STATEMENT_OF_ACCOUNT', 'Statement of Account', 'Statement of Account', 'DOCX', 'templates/statement_of_account.docx', 'Borrower statement of account.', 1);

-- Placeholder -> data-source mapping for the template merge engine (see
-- App\Services\DocumentGenerationService). Onboarding a new client's own
-- letter wording is a matter of uploading their .docx (using the same
-- ${PLACEHOLDER} keys) and adjusting these mappings -- not a code change.
INSERT INTO document_template_fields (template_id, field_key, field_label, source_table, source_column, is_required) VALUES
(1, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1),
(1, 'ID_NUMBER', 'ID Number', 'borrowers', 'id_number', 1),
(1, 'CURRENT_DATE', 'Current Date', 'computed', 'current_date', 1),
(1, 'CLEARED_BALANCE', 'Cleared Balance', 'computed', 'cleared_balance', 1),
(1, 'WORDS', 'Cleared Balance in Words', 'computed', 'cleared_balance_words', 1),
(2, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1),
(2, 'ID_NUMBER', 'ID Number', 'borrowers', 'id_number', 1),
(2, 'OUTSTANDING_BALANCE', 'Outstanding Balance', 'computed', 'outstanding_balance', 1),
(2, 'WORDS', 'Outstanding Balance in Words', 'computed', 'outstanding_balance_words', 1),
(2, 'TRANSACTION_TABLE', 'Transaction History Table', 'computed', 'transaction_table', 0),
(2, 'LOAN_END_DATE', 'Loan End Date', 'computed', 'loan_end_date', 1),
(3, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1),
(3, 'ID_NUMBER', 'ID Number', 'borrowers', 'id_number', 1),
(3, 'OUTSTANDING_BALANCE', 'Outstanding Balance', 'computed', 'outstanding_balance', 1),
(3, 'WORDS', 'Outstanding Balance in Words', 'computed', 'outstanding_balance_words', 1),
(3, 'LOAN_END_DATE', 'Loan End Date', 'computed', 'loan_end_date', 1),
(4, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1),
(4, 'CONTACT_NUMBER', 'Contact Number', 'borrowers', 'phone', 0),
(4, 'CURRENT_DATE', 'Current Date', 'computed', 'current_date', 1),
(4, 'ID_NUMBER', 'ID Number', 'borrowers', 'id_number', 1),
(4, 'LOAN_REF', 'Loan Reference', 'loans', 'loan_no', 0),
(4, 'PRINCIPLE_AMOUNT', 'Principal Amount', 'loans', 'principal_amount', 0),
(4, 'REFUND_AMOUNT', 'Refund Amount', 'computed', 'refund_amount', 1),
(4, 'REFUND_AMOUNT_WORDS', 'Refund Amount in Words', 'computed', 'refund_amount_words', 1),
(4, 'RESAON', 'Reason', 'refund_claims', 'reason', 1),
(5, 'AMOUNT_CLAIMED', 'Amount Claimed', 'refund_claims', 'claim_amount', 1),
(5, 'AMOUNT_PROCESSED', 'Amount Processed', 'refund_claims', 'approved_amount', 1),
(5, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1),
(5, 'DATE_CREATED', 'Date Created', 'refund_claims', 'claim_date', 1),
(5, 'TOTAL_REPAYMENT', 'Total Repayment', 'loans', 'total_payable', 0);

-- Solid Desert Cash Loan Express's public "Apply Now" website form posts
-- these exact field names today (via its own legacy PHP backend). This
-- mapping lets ApplicationIntakeController accept that form's POST as-is
-- once its submit URL is repointed at /api/applications/solid-desert --
-- no changes needed on the client's website. IMPORTANT: rotate api_token
-- to a real secret before this goes live; the seeded value is a placeholder.
INSERT INTO intake_sources (source_code, source_name, api_token, allowed_origin, is_active) VALUES
('solid-desert', 'Solid Desert Cash Loan Express cc', 'CHANGE_ME_ROTATE_BEFORE_LIVE_7f3a9c2e1b', 'https://solid-desert.com', 1);

INSERT INTO intake_field_mappings (intake_source_id, incoming_field_name, target_field, is_required) VALUES
(1, 'firstname', 'applicant_first_name', 1),
(1, 'lastname', 'applicant_last_name', 1),
(1, 'id_number', 'applicant_id_number', 1),
(1, 'gender', 'applicant_gender', 0),
(1, 'marital_status', 'extra:marital_status', 0),
(1, 'account_no', 'extra:account_no', 0),
(1, 'address', 'applicant_address', 0),
(1, 'postal_address', 'extra:postal_address', 0),
(1, 'salary', 'net_salary', 0),
(1, 'commission', 'extra:commission', 0),
(1, 'pension', 'extra:pension', 0),
(1, 'business_income', 'extra:business_income', 0),
(1, 'groceries', 'extra:groceries', 0),
(1, 'school_fees', 'extra:school_fees', 0),
(1, 'transport', 'extra:transport', 0),
(1, 'home_loan', 'extra:home_loan', 0),
(1, 'home_rental', 'extra:home_rental', 0),
(1, 'credit_card', 'extra:credit_card', 0),
(1, 'personal_loans', 'extra:personal_loans', 0),
(1, 'education_loan', 'extra:education_loan', 0),
(1, 'insurance', 'extra:insurance', 0),
(1, 'car_payments', 'extra:car_payments', 0),
(1, 'cell_phone', 'extra:cell_phone', 0),
(1, 'other_credit', 'extra:other_credit', 0),
(1, 'occupation', 'extra:occupation', 0),
(1, 'employee_number', 'employee_no', 0),
(1, 'company_name', 'employer_name', 0),
(1, 'company_tel', 'extra:company_tel', 0),
(1, 'gross_salary', 'gross_salary', 1),
(1, 'payment_day', 'payment_day', 0),
(1, 'employer_address', 'extra:employer_address', 0),
(1, 'contact_no', 'applicant_phone', 1),
(1, 'email', 'applicant_email', 1),
(1, 'relative_contact', 'extra:relative_contact', 0),
(1, 'bank_name', 'bank_name', 0),
(1, 'bank_branch', 'extra:bank_branch_name', 0),
(1, 'account_number', 'bank_account_number', 0),
(1, 'account_type', 'extra:account_type', 0),
(1, 'ref1_name', 'extra:ref1_name', 0),
(1, 'ref1_tel', 'extra:ref1_tel', 0),
(1, 'ref2_name', 'extra:ref2_name', 0),
(1, 'ref2_tel', 'extra:ref2_tel', 0),
(1, 'loan_types', 'requested_purpose', 0),
(1, 'loan_amount', 'requested_amount', 1),
(1, 'num_installments', 'requested_term_months', 1),
(1, 'first_due_date', 'extra:first_due_date', 0),
(1, 'last_due_date', 'extra:last_due_date', 0),
(1, 'signing_place', 'extra:signing_place', 0),
(1, 'signing_day', 'extra:signing_day', 0),
(1, 'signing_month', 'extra:signing_month', 0),
(1, 'signing_year', 'extra:signing_year', 0);

INSERT INTO expense_categories (category_name, account_id, description, is_active) VALUES
('Bank Charges', (SELECT id FROM accounting_accounts WHERE account_code='5020'), 'Bank related charges.', 1),
('Operating Expenses', (SELECT id FROM accounting_accounts WHERE account_code='5030'), 'General operating expenses.', 1),
('Salary Expenses', (SELECT id FROM accounting_accounts WHERE account_code='5040'), 'Staff salary expenses.', 1);

INSERT INTO notification_templates (template_code, template_name, channel, subject, message_body, is_active) VALUES
('APPLICATION_APPROVED_SMS', 'Application Approved SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your loan application {{application_no}} has been approved.', 1),
('APPLICATION_REJECTED_SMS', 'Application Rejected SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your loan application {{application_no}} was not approved. Please contact us for more information.', 1),
('PAYMENT_REMINDER_SMS', 'Payment Reminder SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your loan payment of {{amount_due}} is due on {{due_date}}.', 1),
('ARREARS_NOTICE_SMS', 'Arrears Notice SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your account is in arrears by {{arrears_amount}}. Please make payment urgently.', 1),
('LOAN_COMPLETED_SMS', 'Loan Completed SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your loan {{loan_no}} has been fully paid. Thank you.', 1),
('REFUND_APPROVED_SMS', 'Refund Approved SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, your refund claim {{claim_no}} has been approved.', 1);

INSERT INTO report_definitions (report_code, report_name, report_category, default_frequency, description, is_active) VALUES
('OP_LOAN_LIST', 'Loan List Report', 'Operational', 'On Demand', 'Complete loan listing report.', 1),
('OP_ACTIVE_LOANS', 'Active Loans Report', 'Operational', 'Daily', 'All active loans.', 1),
('OP_CURRENT_LOANS', 'Current Loans Report', 'Operational', 'Daily', 'All current performing loans.', 1),
('OP_COMPLETED_LOANS', 'Completed Loans Report', 'Operational', 'Monthly', 'Completed loan report.', 1),
('OP_CASH_DISBURSED', 'Cash Disbursed Report', 'Collections', 'Daily', 'Cash and bank disbursement report.', 1),
('OP_CASH_COLLECTION', 'Cash Collection Report', 'Collections', 'Daily', 'Cash, debit order and bank collection report.', 1),
('OP_ARREARS_AGING', 'Arrears Aging Report', 'Collections', 'Daily', 'Aging report by arrears bucket.', 1),
('OP_BAD_DEBTS', 'Bad Debts Report', 'Portfolio', 'Monthly', 'Bad debt portfolio report.', 1),
('OP_BAD_DEBT_RECOVERIES', 'Bad Debt Recoveries Report', 'Portfolio', 'Monthly', 'Recoveries after write-off.', 1),
('FIN_GENERAL_LEDGER', 'General Ledger Report', 'Financial', 'On Demand', 'General ledger transactions.', 1),
('FIN_TRIAL_BALANCE', 'Trial Balance', 'Financial', 'Monthly', 'Trial balance report.', 1),
('FIN_INCOME_STATEMENT', 'Income Statement', 'Financial', 'Monthly', 'Profit and loss report.', 1),
('FIN_BALANCE_SHEET', 'Balance Sheet', 'Financial', 'Monthly', 'Balance sheet report.', 1),
('FIN_CASH_FLOW', 'Cash Flow Statement', 'Financial', 'Monthly', 'Cash flow statement.', 1),
('REG_BAD_DEBTS_QTR', 'Bad Debts Quarterly Report', 'Regulatory', 'Quarterly', 'NAMFISA bad debts quarterly report.', 1),
('REG_BAD_DEBT_RECOVERY_QTR', 'Bad Debt Recovery Quarterly Report', 'Regulatory', 'Quarterly', 'NAMFISA bad debt recovery quarterly report.', 1),
('REG_LOAN_SIZE_GENDER_QTR', 'Loan Breakdown by Size and Gender', 'Regulatory', 'Quarterly', 'Loan size and gender quarterly report.', 1),
('REG_LOAN_GENDER_QTR', 'Loan Breakdown by Gender', 'Regulatory', 'Quarterly', 'Gender quarterly report.', 1),
('REG_LOAN_SALARY_QTR', 'Loan Breakdown by Salary', 'Regulatory', 'Quarterly', 'Salary band quarterly report.', 1),
('AUDIT_TRAIL', 'Audit Trail Report', 'Audit', 'On Demand', 'System audit trail report.', 1);

INSERT INTO dashboard_widgets (widget_code, widget_name, widget_category, display_order, is_active) VALUES
('TOTAL_BORROWERS', 'Total Borrowers', 'Loans', 1, 1),
('ACTIVE_LOANS', 'Active Loans', 'Loans', 2, 1),
('CURRENT_LOANS', 'Current Loans', 'Loans', 3, 1),
('COMPLETED_LOANS', 'Completed Loans', 'Loans', 4, 1),
('TOTAL_DISBURSED', 'Total Disbursed', 'Loans', 5, 1),
('TOTAL_COLLECTED', 'Total Collected', 'Collections', 6, 1),
('TOTAL_OUTSTANDING', 'Total Outstanding', 'Portfolio', 7, 1),
('LOANS_IN_ARREARS', 'Loans in Arrears', 'Collections', 8, 1),
('PORTFOLIO_AT_RISK', 'Portfolio at Risk', 'Portfolio', 9, 1),
('BAD_DEBTS', 'Bad Debts', 'Portfolio', 10, 1),
('RECOVERIES', 'Recoveries', 'Portfolio', 11, 1),
('BANK_BALANCE', 'Bank Balance', 'Accounting', 12, 1),
('TRIAL_BALANCE_STATUS', 'Trial Balance Status', 'Accounting', 13, 1),
('NAMFISA_LEVY', 'NAMFISA Levy', 'Compliance', 14, 1),
('DUTY_STAMP', 'Duty Stamp', 'Compliance', 15, 1);

-- Permissions
INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('dashboard.view', 'View Dashboard', 'Dashboard'),
('borrowers.view', 'View Borrowers', 'Borrowers'),
('borrowers.create', 'Create Borrowers', 'Borrowers'),
('borrowers.edit', 'Edit Borrowers', 'Borrowers'),
('borrowers.approve', 'Approve Borrowers', 'Borrowers'),
('borrowers.delete', 'Delete Borrowers', 'Borrowers'),
('borrowers.documents', 'Manage Borrower Documents', 'Borrowers'),
('borrowers.portal', 'Manage Borrower Portal Access', 'Borrowers'),
('applications.view', 'View Applications', 'Applications'),
('applications.create', 'Create Applications', 'Applications'),
('applications.screen', 'Screen Applications', 'Applications'),
('applications.approve', 'Approve Applications', 'Applications'),
('applications.reject', 'Reject Applications', 'Applications'),
('applications.convert', 'Convert Application to Loan', 'Applications'),
('loans.view', 'View Loans', 'Loans'),
('loans.create', 'Create Loans', 'Loans'),
('loans.edit', 'Edit Loans', 'Loans'),
('loans.approve', 'Approve Loans', 'Loans'),
('loans.release', 'Release Loans', 'Loans'),
('loans.deny', 'Deny Loans', 'Loans'),
('loans.complete', 'Complete Loans', 'Loans'),
('loans.writeoff', 'Write Off Loans', 'Loans'),
('collections.view', 'View Collections', 'Collections'),
('collections.create', 'Create Collections', 'Collections'),
('collections.post', 'Post Collections', 'Collections'),
('collections.reverse', 'Reverse Collections', 'Collections'),
('collections.debit_orders', 'Manage Debit Orders', 'Collections'),
('collections.arrears', 'View Arrears', 'Collections'),
('collections.penalties', 'Manage Penalties', 'Collections'),
('refunds.view', 'View Refund Claims', 'Refunds'),
('refunds.create', 'Create Refund Claims', 'Refunds'),
('refunds.approve', 'Approve Refund Claims', 'Refunds'),
('refunds.pay', 'Pay Refund Claims', 'Refunds'),
('reschedules.view', 'View Loan Reschedules', 'Reschedules'),
('reschedules.create', 'Create Loan Reschedules', 'Reschedules'),
('reschedules.approve', 'Approve Loan Reschedules', 'Reschedules'),
('reschedules.implement', 'Implement Loan Reschedules', 'Reschedules'),
('accounting.view', 'View Accounting Module', 'Accounting'),
('accounting.chart', 'Manage Chart of Accounts', 'Accounting'),
('accounting.journals', 'Manage Journal Entries', 'Accounting'),
('accounting.adjustment_journals', 'Manage Adjustment Journals', 'Accounting'),
('accounting.recurring_journals', 'Manage Recurring Journals', 'Accounting'),
('accounting.cashbook', 'Manage Cash Book', 'Accounting'),
('accounting.bank_accounts', 'Manage Bank Accounts', 'Accounting'),
('accounting.bank_reconciliation', 'Manage Bank Reconciliation', 'Accounting'),
('accounting.reconciliation_override', 'Override Completed Reconciliation Locks', 'Accounting'),
('accounting.ledger', 'View General Ledger', 'Accounting'),
('accounting.trial_balance', 'View Trial Balance', 'Accounting'),
('accounting.income_statement', 'View Income Statement', 'Accounting'),
('accounting.balance_sheet', 'View Balance Sheet', 'Accounting'),
('accounting.cash_flow', 'View Cash Flow Statement', 'Accounting'),
('accounting.provisions', 'Manage Bad Debt Provisions', 'Accounting'),
('accounting.writeoffs', 'Manage Accounting Write-Offs', 'Accounting'),
('accounting.recoveries', 'Manage Recovery Accounting', 'Accounting'),
('accounting.settings', 'Manage Accounting Settings', 'Accounting'),
('expenses.view', 'View Expenses', 'Expenses'),
('expenses.create', 'Create Expenses', 'Expenses'),
('expenses.approve', 'Approve Expenses', 'Expenses'),
('expenses.pay', 'Pay Expenses', 'Expenses'),
('expenses.reports', 'View Expense Reports', 'Expenses'),
('documents.view', 'View Documents', 'Documents'),
('documents.templates', 'Manage Document Templates', 'Documents'),
('documents.generate', 'Generate Documents', 'Documents'),
('documents.send', 'Send Documents', 'Documents'),
('documents.signatures', 'Manage Document Signatures', 'Documents'),
('compliance.view', 'View Compliance Module', 'Compliance'),
('compliance.namfisa', 'Manage NAMFISA Levy Reports', 'Compliance'),
('compliance.duty_stamp', 'Manage Duty Stamp Reports', 'Compliance'),
('compliance.quarterly', 'Generate Quarterly Reports', 'Compliance'),
('compliance.payment_methods', 'View Payment Methods Report', 'Compliance'),
('compliance.exports', 'Export Regulatory Reports', 'Compliance'),
('reports.operational', 'View Operational Reports', 'Reports'),
('reports.financial', 'View Financial Reports', 'Reports'),
('reports.regulatory', 'View Regulatory Reports', 'Reports'),
('reports.collections', 'View Collection Reports', 'Reports'),
('reports.audit', 'View Audit Reports', 'Reports'),
('reports.export', 'Export Reports', 'Reports'),
('notifications.view', 'View Notifications', 'Notifications'),
('notifications.send', 'Send Notifications', 'Notifications'),
('notifications.templates', 'Manage Notification Templates', 'Notifications'),
('notifications.settings', 'Manage Notification Settings', 'Notifications'),
('admin.users', 'Manage Users', 'Admin'),
('admin.roles', 'Manage Roles', 'Admin'),
('admin.permissions', 'Manage Permissions', 'Admin'),
('admin.branches', 'Manage Branches', 'Admin'),
('admin.company', 'Manage Company Settings', 'Admin'),
('admin.audit', 'View Audit Trail', 'Admin'),
('admin.system_settings', 'Manage System Settings', 'Admin');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin';

SET FOREIGN_KEY_CHECKS = 1;
