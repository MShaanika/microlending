-- Enterprise Control Architecture -- Phase 5: Platform Reliability
-- (Automated Health Checks).
--
-- One row per check run (append-only history, same convention as
-- security_events/sla_events) -- "current state" is just the latest
-- row per check_key, and simple uptime is computable from the history
-- without a separate mutable "status" table drifting out of sync with
-- what actually happened.
CREATE TABLE health_check_results (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    check_key VARCHAR(60) NOT NULL,
    target_name VARCHAR(100) NOT NULL,
    status ENUM('HEALTHY','DEGRADED','UNHEALTHY','UNKNOWN') NOT NULL,
    response_time_ms INT NULL,
    message TEXT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_health_key_time (check_key, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Part 38: a heartbeat every bin/*.php script pings on successful
-- completion -- what "Scheduled Jobs: last execution / missed jobs"
-- actually reads. One row per job_key, upserted (not appended), since
-- only the most recent run time is ever needed here; sla_events/
-- security_events-style full history exists for the frameworks that
-- need per-event detail, this table deliberately doesn't.
CREATE TABLE scheduled_job_heartbeats (
    job_key VARCHAR(100) NOT NULL PRIMARY KEY,
    last_run_at TIMESTAMP NOT NULL,
    last_summary TEXT NULL,
    expected_frequency_minutes INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('health_checks_module', 'enterprise-control-phase-5');
