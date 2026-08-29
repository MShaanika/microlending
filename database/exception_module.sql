-- Enterprise Control Architecture -- Phase 3: Operational Control
-- (Exception Management Centre).
--
-- Central operational-problem queue across every module (Part 22-27).
-- exception_type/category are free-form strings, not an ENUM -- the
-- master prompt's own type list (Part 23) spans lending, payments,
-- accounting, APIs, data quality, and system categories that will grow
-- as later phases (Data Quality in Phase 4, Health Checks in Phase 5)
-- feed this table; a hardcoded ENUM would need a migration every time a
-- new category is needed.
CREATE TABLE exceptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    exception_uuid VARCHAR(36) NOT NULL UNIQUE,
    correlation_id VARCHAR(40) NULL,
    exception_type VARCHAR(100) NOT NULL,
    category VARCHAR(60) NOT NULL,
    module VARCHAR(60) NOT NULL,
    severity ENUM('Low','Medium','High','Critical') NOT NULL,
    priority INT NOT NULL DEFAULT 3,
    resource_type VARCHAR(60) NULL,
    resource_id BIGINT NULL,
    owner_user_id INT NULL,
    status ENUM('OPEN','ASSIGNED','INVESTIGATING','WAITING','RESOLVED','ACCEPTED_RISK','CLOSED') DEFAULT 'OPEN',
    description TEXT NOT NULL,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    resolution TEXT NULL,
    root_cause TEXT NULL,
    reopened_count INT NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    INDEX idx_exc_status (status),
    INDEX idx_exc_severity (severity),
    INDEX idx_exc_module (module, category),
    INDEX idx_exc_owner (owner_user_id, status),
    INDEX idx_exc_resource (resource_type, resource_id),
    INDEX idx_exc_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Free-form notes/investigation trail on an exception -- separate from
-- the single resolution/root_cause pair captured at close time, so an
-- investigation with several updates isn't squeezed into one field.
CREATE TABLE exception_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    exception_id BIGINT NOT NULL,
    author_user_id INT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exception_id) REFERENCES exceptions(id) ON DELETE CASCADE,
    FOREIGN KEY (author_user_id) REFERENCES users(id),
    INDEX idx_excnote_exception (exception_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('exception_module', 'enterprise-control-phase-3');
