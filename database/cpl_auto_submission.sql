-- Automatic daily + monthly CPL submission to Creditinfo, built from the Loan Questions answers (see App\Services\CplAutoSubmissionService).
-- Idempotent: safe to run more than once. RUN THIS BEFORE deploying the code.
--
-- PREREQUISITE MIGRATIONS (must already have been run, in this order):
--   cpl_batches_and_snapshots.sql   cpl_batches (approval + rejection columns), cpl_submissions
--   cpl_settings_and_corrections.sql  cpl_settings
--   cpl_sftp_submission.sql         the live SFTP switch
--   cpl_uat_signoff.sql             cpl_batches.is_uat_test, submission_outcome / submission_notes / submission_recorded_*
--   cpl_legacy_source.sql           cpl_batches.source, legacy_exceptions, legacy_summary
--   health_checks_module.sql        scheduled_job_heartbeats (the cron health check reads it)
--   loan_followups.sql              loan_followups, loan_followup_instalments, loan_followup_payments, loan_followup_answers (the Loan Questions)
--   applicant_correction_and_purge.sql  loan_applications.purged_at (a purged application is never reported)
--   schema.sql + cpl_applicant_intake_fields.sql + cpl_required_intake_fields.sql  the loan_applications intake columns the mapper reads
-- The check below fails LOUDLY (MySQL stops with "Unknown column ..." or "Table ... doesn't exist" naming exactly what is missing) when one of them
-- has not been run, before anything is changed. Nothing here is silently skipped.

-- ---- prerequisite check: every statement must succeed, none returns a row ----
SELECT source, legacy_exceptions, legacy_summary, is_uat_test, submission_outcome, submission_notes, submission_recorded_by, submission_recorded_at,
       approval_request_id, approved_by, approved_at, rejected_by, rejected_at, rejection_reason, file_content, generated_by, generated_at
  FROM cpl_batches LIMIT 0;
SELECT batch_id, sequence_number, file_name, submitted_by, submitted_at, outcome, notes FROM cpl_submissions LIMIT 0;
SELECT setting_key, setting_value, updated_by FROM cpl_settings LIMIT 0;
SELECT job_key, last_run_at, last_summary, expected_frequency_minutes FROM scheduled_job_heartbeats LIMIT 0;
SELECT id, application_id, application_no, status, approved, approved_amount, disbursed, disbursed_on, term_months, pay_day, next_ask_at FROM loan_followups LIMIT 0;
SELECT id, followup_id, instalment_no, due_date, expected_amount, paid_amount, paid_on, answered_at, next_ask_at FROM loan_followup_instalments LIMIT 0;
SELECT id, instalment_id, amount, paid_on FROM loan_followup_payments LIMIT 0;
SELECT id, application_no, status, created_at, purged_at, applicant_first_name, applicant_middle_name, applicant_last_name, applicant_id_number, applicant_gender, applicant_phone,
       applicant_address, employer_name, gross_salary, payment_day, requested_term_months FROM loan_applications LIMIT 0;
SELECT user_id, channel, recipient_name, recipient_contact, subject, message, source_module, source_table, source_id, status FROM notification_queue LIMIT 0;
--
-- cpl_auto_reported records WHAT was reported, per loan (loan_followups.id) and kind:
--   R = daily registration (a loan newly paid out)         -- period_key 'ONCE': a loan is registered at most once, ever
--   C = daily closure (a loan fully settled)               -- period_key 'ONCE': a loan is closed at most once, ever
--   M = month-end book record                              -- period_key = the month end (YYYY-MM-DD): once per loan per month
-- The UNIQUE key is what makes it impossible to send the same R or C twice, whatever else goes wrong.
-- state 'Planned' = the loan is in a batch that has NOT been confirmed as delivered (a draft, or an upload whose outcome is unclear);
-- state 'Reported' = Creditinfo's server confirmed the file arrived. Only 'Reported' rows stop a loan from being selected again.

CREATE TABLE IF NOT EXISTS cpl_auto_reported (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    followup_id BIGINT NOT NULL,
    application_no VARCHAR(50) NOT NULL,
    report_kind ENUM('R', 'C', 'M') NOT NULL,
    period_key VARCHAR(10) NOT NULL,
    batch_id BIGINT NOT NULL,
    state ENUM('Planned', 'Reported') NOT NULL DEFAULT 'Planned',
    file_name VARCHAR(150) NULL,
    reported_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cpl_auto_reported (followup_id, report_kind, period_key),
    INDEX idx_cpl_auto_reported_batch (batch_id),
    INDEX idx_cpl_auto_reported_state (state, report_kind),
    CONSTRAINT fk_cpl_auto_reported_batch FOREIGN KEY (batch_id) REFERENCES cpl_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An automatic submission has no person behind it.
ALTER TABLE cpl_submissions MODIFY submitted_by INT NULL;

-- Settings. Automatic submission is OFF until a person turns it on from the CPL dashboard (with the completeness acknowledgement).
INSERT IGNORE INTO cpl_settings (setting_key, setting_value) VALUES
('cpl_auto_submit_enabled', '0'),
('cpl_auto_daily_since', ''),
('cpl_auto_excluded_names', ''),
('cpl_monthly_send_day', '3'),
('cpl_auto_alert_user_ids', ''),
('cpl_public_base_url', ''),
('cpl_auto_last_daily_run', ''),
('cpl_auto_last_monthly_run', ''),
('cpl_auto_last_success', ''),
('cpl_auto_last_failure', ''),
('cpl_auto_enabled_by', ''),
('cpl_auto_enabled_at', ''),
-- the STANDING APPROVAL (who turned the switch on, when, for which destination and which preview; JSON), why/when it was last revoked, and the
-- global hard stop after a refused SFTP login (JSON; cleared only by a person on the dashboard)
('cpl_auto_standing_approval', ''),
('cpl_auto_approval_revoked', ''),
('cpl_auto_halted_reason', ''),
('cpl_auto_switch_changed_at', '');
