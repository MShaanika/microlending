-- Creditinfo UAT-readiness hardening: safe diagnostic evidence (never
-- credentials/tokens/full IDs/report content -- see
-- CreditinfoDiagnosticLog::record()), the UAT Test Centre's own audit
-- trail is this same table. Additive only.

CREATE TABLE creditinfo_diagnostic_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    triggered_by INT NULL,
    source ENUM('uat_test_centre','application_check','settings_test','poll') NOT NULL DEFAULT 'application_check',
    environment ENUM('uat','production') NOT NULL DEFAULT 'uat',
    endpoint VARCHAR(150) NOT NULL,
    http_status INT NULL,
    creditinfo_request_id VARCHAR(100) NULL,
    workflow_id VARCHAR(100) NULL,
    outcome_status VARCHAR(50) NULL,
    subject_token_present TINYINT(1) NOT NULL DEFAULT 0,
    report_token_present TINYINT(1) NOT NULL DEFAULT 0,
    national_id_masked VARCHAR(20) NULL,
    duration_ms INT NULL,
    error_code VARCHAR(50) NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (triggered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
