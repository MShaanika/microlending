-- Stored Creditinfo credit reports are deleted automatically (Form C clause 4.3: keep bureau data only as long as needed).
--   creditinfo_report_content_purge          report content + PDF are deleted 90 days after the lender records its decision on the application
--   creditinfo_report_content_undecided_purge backstop: a report whose application never gets a decision is deleted 180 days after the check
-- The audit record of the check (creditinfo_report_cache: lender, user, application, outcome, times) is NEVER deleted; its purged_at is stamped.
-- Legal holds are honoured. The nightly job bin/evaluate_retention.php runs the policies (must be in the crontab).
-- 90 / 180 days confirmed by the business owner on 2026-10-29. Change retention_days on Settings > Retention to adjust later, no deploy needed.
INSERT INTO retention_policies (policy_key, policy_name, data_category, resource_table, date_column, comparison_mode, retention_days, legal_hold_supported, requires_legal_confirmation, is_active) VALUES
('creditinfo_report_content_purge', 'Credit Bureau Report Content Purge (after decision)', 'Credit Bureau Data', 'creditinfo_report_content', 'decision_at', 'AGE_FROM_DATE_COLUMN', 90, 1, 0, 1),
('creditinfo_report_content_undecided_purge', 'Credit Bureau Report Content Purge (no decision recorded)', 'Credit Bureau Data', 'creditinfo_report_content', 'created_at', 'AGE_FROM_DATE_COLUMN', 180, 1, 0, 1)
ON DUPLICATE KEY UPDATE
    policy_name = VALUES(policy_name), date_column = VALUES(date_column), comparison_mode = VALUES(comparison_mode),
    retention_days = VALUES(retention_days), legal_hold_supported = 1, requires_legal_confirmation = 0, is_active = 1;
