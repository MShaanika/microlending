-- Additive columns for the new submission-safety audit events (idempotent
-- replay served, duplicate submission blocked, concurrent update rejected,
-- API outcome uncertain). metadata carries structured context; reference_key
-- carries whichever idempotency key / draft UUID the event relates to, for
-- fast cross-referencing without parsing the JSON. See App\Core\Audit::log().
ALTER TABLE audit_logs
    ADD COLUMN metadata JSON NULL AFTER description,
    ADD COLUMN reference_key VARCHAR(100) NULL AFTER metadata,
    ADD INDEX idx_audit_reference (reference_key);
