-- Narrow, role-scoped access for staff who should only ever see newly
-- Submitted online applications (e.g. Marketing Officer, whose one job
-- here is acknowledging receipt by SMS) -- not the full applications.view
-- permission, which exposes every status including approved/rejected
-- decisions, credit bureau consent, and screening detail. See
-- App\Controllers\ApplicationController::canViewAllApplications() for how
-- the two permissions interact: applications.view still sees everything;
-- applications.view_submitted sees ONLY Submitted applications, enforced
-- server-side in index()/show(), not just hidden in the UI.

INSERT INTO permissions (permission_key, permission_name, module_name) VALUES
('applications.view_submitted', 'View Submitted Applications Only', 'Applications'),
('applications.acknowledge_receipt', 'Acknowledge Application Receipt (SMS)', 'Applications');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name = 'Marketing Officer' AND p.permission_key IN ('applications.view_submitted', 'applications.acknowledge_receipt');

-- Marketing Officer previously had the full applications.view permission
-- (broader than intended -- she could see every application regardless
-- of status). Removed in favour of the narrow permission above.
DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.role_name = 'Marketing Officer' AND p.permission_key = 'applications.view';

INSERT INTO notification_templates (template_code, template_name, channel, subject, message_body, is_active) VALUES
('APPLICATION_RECEIVED_SMS', 'Application Received SMS', 'SMS', NULL, 'Dear {{borrower_full_name}}, we have received your loan application {{application_no}} and will be in touch soon. - {{company_name}}', 1);
