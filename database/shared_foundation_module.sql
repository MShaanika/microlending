-- Enterprise Control Architecture -- Phase 1: Shared Foundation.
--
-- Adds: correlation_id threading on the two existing tables that
-- already carry cross-cutting request evidence (audit_logs,
-- security_events); a lightweight schema_migrations bookkeeping table
-- for this initiative specifically (the app has no migration runner --
-- see the Phase 0 audit -- this exists only so an eleven-framework
-- rollout has a record of what's landed where, not a general-purpose
-- migration tool); and the permission keys every later phase's pages
-- will gate behind. No page/controller wired to these yet -- that's
-- Phase 2 onward.

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(150) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    applied_by VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by) VALUES
('shared_foundation_module', 'enterprise-control-phase-1');

-- Correlation ID -- REQ-YYYYMMDD-XXXXXXXX, see App\Core\Correlation.
-- Nullable/backward-compatible: every row that already exists keeps
-- working, only new writes populate it.
ALTER TABLE audit_logs
    ADD COLUMN correlation_id VARCHAR(40) NULL AFTER reference_key,
    ADD INDEX idx_audit_correlation (correlation_id);

ALTER TABLE security_events
    ADD COLUMN correlation_id VARCHAR(40) NULL AFTER metadata,
    ADD INDEX idx_sec_ev_correlation (correlation_id);

-- Permission keys for the whole initiative, seeded now so later phases
-- only need to build the page/controller, not another permissions
-- migration each time. module_name groups them for the Super Admin
-- blanket-grant pattern already used by every other module in this app.
INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('approvals.view', 'View Approval Queue', 'Governance'),
('approvals.approve', 'Approve or Reject Requests', 'Governance'),
('delegations.view', 'View Delegations', 'Governance'),
('delegations.manage', 'Create and Revoke Delegations', 'Governance'),
('exceptions.view', 'View Exception Management Centre', 'Operations'),
('exceptions.manage', 'Assign, Investigate, and Resolve Exceptions', 'Operations'),
('sla.view', 'View SLA Performance', 'Operations'),
('sla.manage', 'Configure SLA and Escalation Policies', 'Operations'),
('data_quality.view', 'View Data Quality Dashboard', 'Quality'),
('data_quality.manage', 'Configure Data Quality Rules', 'Quality'),
('health.view', 'View System Health', 'Platform'),
('feature_flags.view', 'View Feature Flags', 'Platform'),
('feature_flags.manage', 'Manage Feature Flags', 'Platform'),
('errors.view', 'View Error Tracking', 'Platform'),
('retention.view', 'View Retention Policies', 'Continuity'),
('retention.manage', 'Manage Retention Policies and Legal Holds', 'Continuity'),
('continuity.view', 'View Business Continuity Dashboard', 'Continuity'),
('continuity.manage', 'Manage Backup and Recovery Configuration', 'Continuity'),
('intelligence.view', 'View Decision Intelligence', 'Intelligence');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name = 'Super Admin' AND p.module_name IN ('Governance', 'Operations', 'Quality', 'Platform', 'Continuity', 'Intelligence');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN (
    'approvals.view', 'approvals.approve', 'delegations.view',
    'exceptions.view', 'exceptions.manage', 'sla.view',
    'data_quality.view', 'health.view', 'errors.view',
    'retention.view', 'continuity.view', 'intelligence.view'
) AND r.role_name IN ('Admin', 'Manager');

-- feature_flags.* deliberately excluded here -- Super Admin only (already
-- covered by the blanket module_name grant above), since a flag change is
-- effectively a code-behavior change, not an operational admin task.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN (
    'delegations.manage', 'sla.manage', 'data_quality.manage',
    'retention.manage', 'continuity.manage'
) AND r.role_name = 'Admin';
