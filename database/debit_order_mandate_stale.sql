-- A placed Collexia mandate is fixed at the moment it is placed. When the LOAN is rescheduled afterwards, the mandate keeps
-- collecting the old dates and amounts. These columns record that the debit order is "out of step" with its loan, so a red
-- notice stays on the loan and the debit order until the mandate is replaced (the debit order is cancelled, or completes).
--
--   mandate_stale_since          when the loan was rescheduled underneath this live debit order
--   mandate_stale_reschedule_id  which reschedule did it
--   mandate_stale_note           the reason, in words
ALTER TABLE debit_orders
    ADD COLUMN mandate_stale_since DATETIME NULL,
    ADD COLUMN mandate_stale_reschedule_id BIGINT NULL,
    ADD COLUMN mandate_stale_note VARCHAR(255) NULL;

-- Reschedules implemented before this existed: flag the debit orders registered on an EARLIER DAY than the reschedule. Whole days
-- only, because the app and database clocks differ by hours and a same-day case cannot be told apart reliably. Harmless to re-run.
UPDATE debit_orders d
JOIN (SELECT loan_id, MAX(id) AS rid FROM loan_reschedules WHERE status = 'Implemented' GROUP BY loan_id) lr ON lr.loan_id = d.loan_id
JOIN loan_reschedules r ON r.id = lr.rid
SET d.mandate_stale_since = r.implemented_at,
    d.mandate_stale_reschedule_id = r.id,
    d.mandate_stale_note = 'The loan was rescheduled after this debit order was registered.'
WHERE d.status IN ('Active', 'Suspended')
  AND d.mandate_stale_since IS NULL
  AND DATE(r.implemented_at) > DATE(d.created_at);
