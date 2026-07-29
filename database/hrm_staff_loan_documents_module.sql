-- =========================================================
-- HRM MODULE -- Staff Loan Documents
-- Per-staff-loan document uploads (signed loan agreement,
-- approval letter, supporting payslips/guarantor ID, etc.).
-- Reuses the existing hrm_document_types lookup (already used
-- for employee documents) rather than a separate type table --
-- "kind of document" is a generic concept shared across both
-- contexts, and the admin already manages that one list.
-- Run AFTER database/hrm_staff_loans_module.sql and
-- database/hrm_employee_documents_module.sql have been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_staff_loan_documents;

CREATE TABLE hrm_staff_loan_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_loan_id INT NOT NULL,
    document_type_id INT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NULL,
    file_size INT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_loan_id) REFERENCES hrm_staff_loans(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES hrm_document_types(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
