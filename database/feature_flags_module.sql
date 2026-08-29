-- Enterprise Control Architecture -- Phase 5: Platform Reliability
-- (Feature Flags).
--
-- No flags are seeded -- a flag only means something once real code
-- checks it (Part 39: "deploy functionality without immediately
-- enabling it"). FeatureFlagService::isEnabled() is built and tested
-- as standalone infrastructure this phase; the first feature that
-- actually needs staged rollout wires itself to a flag_key it creates,
-- same as any other future consumer.
--
-- Part 42 is enforced by convention, not by this schema: a flag is
-- never a substitute for Auth::authorize() -- every call site must
-- check both.
CREATE TABLE feature_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flag_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    enabled TINYINT(1) DEFAULT 0,
    rollout_type ENUM('OFF','ALL_USERS','SPECIFIC_USERS','SPECIFIC_ROLES','SPECIFIC_BRANCHES','PERCENTAGE','INTERNAL_ONLY') NOT NULL DEFAULT 'OFF',
    rollout_percentage INT NULL,
    environment VARCHAR(20) NOT NULL DEFAULT 'production',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    -- Holds the target list for SPECIFIC_USERS ({"user_ids":[...]}),
    -- SPECIFIC_ROLES ({"role_names":[...]}), or SPECIFIC_BRANCHES
    -- ({"branch_ids":[...]}) -- unused for the other rollout types.
    metadata JSON NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('feature_flags_module', 'enterprise-control-phase-5');
