-- Loan Questions alerts: a role for the staff who answer the Loan Questions (they get the pop-up, the e-mail for each new application and the morning reminder).
-- Admins and Super Admins already hold the permission. The people are added to this role one by one (user_roles), by user id after checking who they are.

INSERT INTO roles (role_name, description)
SELECT 'Loan Questions', 'Answers the Loan Questions (approved, paid out, instalment paid) and is alerted when one is waiting'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Loan Questions');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name = 'Loan Questions' AND p.permission_key = 'loans.followups'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
