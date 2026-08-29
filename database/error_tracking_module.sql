-- Enterprise Control Architecture -- Phase 5: Platform Reliability
-- (Error Tracking).
--
-- Closes a real, independent gap the Phase 0 audit found: no
-- centralized exception handler exists anywhere in this app --
-- Router::dispatch() has no try/catch, bootstrap/app.php registers no
-- set_exception_handler()/set_error_handler(), and display_errors is
-- hardcoded on regardless of environment. A stray exception could
-- print a raw stack trace to a borrower. This migration adds the
-- storage; App\Core\ErrorHandler (wired in bootstrap/app.php) is what
-- actually catches things from here on.
--
-- Deduplication mirrors SecurityIncident::createOrAppend()'s already-
-- proven pattern in this codebase: a fingerprint (exception class +
-- source file + line) reuses the existing row and bumps
-- occurrence_count instead of creating a new row per occurrence.
CREATE TABLE system_errors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    error_uuid VARCHAR(36) NOT NULL UNIQUE,
    fingerprint VARCHAR(64) NOT NULL,
    correlation_id VARCHAR(40) NULL,
    user_id INT NULL,
    module VARCHAR(60) NULL,
    route VARCHAR(255) NULL,
    request_method VARCHAR(10) NULL,
    error_type VARCHAR(30) NOT NULL,
    exception_class VARCHAR(150) NULL,
    -- Sanitized, safe to show an admin -- never the raw exception
    -- message if it might carry user input/secrets; see
    -- ErrorHandler::sanitize().
    safe_message TEXT NOT NULL,
    source_file VARCHAR(255) NULL,
    source_line INT NULL,
    environment VARCHAR(20) NOT NULL DEFAULT 'production',
    severity ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'High',
    status ENUM('NEW','INVESTIGATING','RESOLVED','IGNORED','REOPENED') DEFAULT 'NEW',
    occurrence_count INT NOT NULL DEFAULT 1,
    exception_id BIGINT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    metadata JSON NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    FOREIGN KEY (exception_id) REFERENCES exceptions(id),
    UNIQUE KEY uq_error_fingerprint (fingerprint),
    INDEX idx_err_status (status),
    INDEX idx_err_severity (severity),
    INDEX idx_err_correlation (correlation_id),
    INDEX idx_err_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('error_tracking_module', 'enterprise-control-phase-5');

-- Controls what App\Core\ErrorHandler actually shows a user when it
-- catches something -- defaults to 'detailed' (today's existing
-- behavior: PHP's own raw error output, unchanged) so deploying this
-- migration and the new handler together changes nothing on its own.
-- An administrator switches to 'safe' (generic message + correlation
-- reference, Part 4's exact example) once ready -- a deliberate,
-- reviewed decision, not an automatic behavior change bundled into a
-- deploy.
INSERT IGNORE INTO system_settings (setting_key, setting_value, module_name) VALUES
('error_display_mode', 'detailed', 'Platform');
