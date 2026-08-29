-- The admin.audit permission ("View Audit Trail") already exists in the
-- permissions seed (schema.sql) and is auto-granted to Super Admin via the
-- blanket CROSS JOIN grant, but was never actually used by any route until
-- the new /settings/audit-log page (AuditLogController). Grant it to Admin
-- and Manager too, matching the role list already used for other
-- settings/admin-facing screens (e.g. admin.permissions).

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'admin.audit'
  AND r.role_name IN ('Admin', 'Manager');
