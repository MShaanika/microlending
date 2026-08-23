-- Links a refund claim's payout to the accounting journal it posts,
-- instead of staff doing a separate manual journal entry. Same
-- journal_id-on-source-table convention already used by loan_recoveries,
-- fixed_assets, loan_write_offs, expenses, etc.

ALTER TABLE refund_claims
    ADD COLUMN journal_id BIGINT NULL AFTER paid_at;
