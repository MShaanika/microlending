-- =========================================================
-- HRM MODULE -- PHASE 7: Employee Documents
-- Per-employee document uploads (ID copies, contracts,
-- certificates), each tagged with a document type. Scoped
-- strictly per-employee -- there is no company-wide document
-- library here (that's a separate, unbuilt reference-module
-- feature: HrmDocument/DocumentCategory with its own
-- pending/approve/reject workflow, deliberately out of scope
-- per explicit decision).
-- Run AFTER database/hrm_module.sql has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_employee_documents;
DROP TABLE IF EXISTS hrm_document_types;

CREATE TABLE hrm_document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type_id INT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NULL,
    file_size INT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES hrm_document_types(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
