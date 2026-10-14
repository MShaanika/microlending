-- "System & Platform" and "Intelligence" are internal dev/ops tooling --
-- System Health, Error Tracking and Feature Flags are already Developer-only
-- in production; Business Continuity and Decision Intelligence were not.
-- Brings both in line with that existing precedent. The Integrations
-- sub-group (Debit Order / Credit Bureau API status pages) is handled by
-- removing its two nav entries in main.php instead of touching permissions
-- -- collections.debit_orders/applications.screen are real operational
-- permissions (Collector/Loan Officer day-to-day work, plus inline
-- "set up API credentials here" links and the CPL UAT sign-off page that
-- depend on them), so narrowing them here would break those unrelated
-- flows. Social & Web Analytics (Marketing Officer's own page) moves to
-- its own top-level nav entry in main.php rather than being caught by this.

DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.permission_key = 'continuity.view' AND r.role_name <> 'Developer';

DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.permission_key = 'intelligence.view' AND r.role_name <> 'Developer';
