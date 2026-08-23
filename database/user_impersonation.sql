-- "Login As" feature: lets eligible staff temporarily switch into another
-- account's session (Auth::startImpersonation/stopImpersonation) instead of
-- needing that person's password. Two separate capabilities:
--   - borrowers.login_as_portal: log into a borrower's own self-service
--     portal (separate PortalAuth system) for support, without a password.
--     Granted to every existing staff role -- same role list already used
--     for tickets.view.
--   - users.login_as_any: full staff-identity impersonation, any user, any
--     role. Bootstrap-granted to Super Admin only (same pattern already
--     used for tickets.support_session) -- a real "Developer" role, once
--     created via Settings > Roles, must be granted this explicitly.
-- Super Admin/Admin's narrower ability to log in as a Marketing Agent
-- (this system's "Sales Agent") is enforced in code (Auth::canLoginAsUser)
-- rather than via a permission row, mirroring how isSuperAdmin()/
-- ipAllowed() already hardcode that pair elsewhere.

INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('borrowers.login_as_portal', 'Log In to Borrower Portal (Support)', 'Borrowers'),
('users.login_as_any', 'Log In As Any User (Developer)', 'Users');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'borrowers.login_as_portal'
  AND r.role_name IN ('Super Admin','Admin','Manager','Loan Officer','Cashier','Accountant','Collector');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'users.login_as_any'
  AND r.role_name = 'Super Admin';
