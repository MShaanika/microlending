-- Creditinfo sign-off support: distinguishes a synthetic UAT sign-off test
-- batch (built from the vendor's own approved test National IDs, never real
-- borrower/loan data) from a real production monthly/daily batch, so the
-- two never appear mixed together in the dashboard or get confused with
-- each other. See App\Services\CplUatTestDataService.
-- test_records holds the synthetic field-map + validation result for a
-- Creditinfo sign-off test file (built entirely from the vendor's own
-- approved UAT National IDs -- 77082851070, 8005041272341, 74082851070 --
-- never from a real loans/borrowers row), so it deliberately does NOT go
-- through cpl_monthly_snapshots (which is FK-bound to real loan_id/
-- borrower_id rows, correctly so, since a "snapshot of a loan" only makes
-- sense for a loan that actually exists). See App\Services\CplUatTestDataService
-- and App\Controllers\CplUatTestController.
-- submission_outcome is only ever set by a human, recording Creditinfo's
-- own load/result confirmation -- nothing in this codebase sets it to
-- 'Accepted' automatically. A file being generated, validated, or even
-- uploaded does NOT mean Creditinfo accepted it (explicit instruction).
ALTER TABLE cpl_batches
    ADD COLUMN is_uat_test TINYINT(1) NOT NULL DEFAULT 0 AFTER batch_type,
    ADD COLUMN test_records JSON NULL AFTER file_content,
    ADD COLUMN submission_outcome ENUM('Awaiting Load Report', 'Accepted', 'Rejected', 'Partially Rejected') NULL AFTER test_records,
    ADD COLUMN submission_notes TEXT NULL AFTER submission_outcome,
    ADD COLUMN submission_recorded_by INT NULL AFTER submission_notes,
    ADD COLUMN submission_recorded_at DATETIME NULL AFTER submission_recorded_by,
    ADD CONSTRAINT fk_cpl_batches_submission_recorded_by FOREIGN KEY (submission_recorded_by) REFERENCES users(id),
    DROP INDEX uniq_batch_type_month,
    ADD UNIQUE KEY uniq_batch_type_test_month (batch_type, is_uat_test, month_end);
