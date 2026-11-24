-- Changing a client's BANK details (borrowers/{id}/edit, "Primary Bank Details") now needs its own permission, borrowers.edit_bank,
-- on top of borrowers.edit. Without it the bank section is shown read-only on the edit screen and is ignored on save.
--
-- Behaviour is UNCHANGED until the owner restricts it: every role that currently holds borrowers.edit is granted the new permission.
-- To restrict, remove "Edit Client Bank Details" from the roles that should not change bank accounts (Settings > Roles).
-- Users must log out and in again for a new permission to reach their session.
--
-- Idempotent: safe to run more than once (INSERT ... WHERE NOT EXISTS / NOT EXISTS guards).

INSERT INTO permissions (permission_key, permission_name, module_name)
SELECT 'borrowers.edit_bank', 'Edit Client Bank Details', 'Borrowers'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'borrowers.edit_bank');

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp_edit.role_id, p_bank.id
FROM role_permissions rp_edit
JOIN permissions p_edit ON p_edit.id = rp_edit.permission_id AND p_edit.permission_key = 'borrowers.edit'
JOIN permissions p_bank ON p_bank.permission_key = 'borrowers.edit_bank'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions x WHERE x.role_id = rp_edit.role_id AND x.permission_id = p_bank.id
);
