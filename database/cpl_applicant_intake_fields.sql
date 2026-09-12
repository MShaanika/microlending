-- Wires apply-dg.php's new CPL-supporting fields (Date of Birth, Title,
-- structured Residential/Postal Address, Residential Ownership, Income
-- Frequency, optional Home Telephone, and a customer-friendly loan-purpose
-- question) into the existing intake_field_mappings/extra_data pipeline --
-- see App\Controllers\ApplicationIntakeController::normalize() and
-- App\Controllers\ApplicationController::approve() (which already reads
-- every one of these extra_data keys; only the mappings that produce them
-- were missing).
--
-- Also fixes a second live mismatch of the same kind already found for
-- 'occupation' (see cpl_settings_and_corrections.sql): 'company_tel' has
-- always mapped to extra:company_tel, but ApplicationController::approve()
-- reads extra_data.employer_phone for borrower_employment.employer_phone --
-- meaning the employer's phone number (CPL field 48, Work Telephone) has
-- never actually reached a borrower record from the online application.

UPDATE intake_field_mappings
SET target_field = 'extra:employer_phone'
WHERE incoming_field_name = 'company_tel'
  AND target_field = 'extra:company_tel'
  AND intake_source_id = (SELECT id FROM intake_sources WHERE source_code = 'solid-desert');

INSERT INTO intake_field_mappings (intake_source_id, incoming_field_name, target_field, is_required)
SELECT id, incoming_field_name, target_field, is_required FROM (
    SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert') AS id,
           'date_of_birth' AS incoming_field_name, 'extra:dob' AS target_field, 0 AS is_required
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'title', 'extra:title', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'residential_line1', 'extra:residential_line1', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'residential_line2', 'extra:residential_line2', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'residential_line3', 'extra:residential_line3', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'residential_postal_code', 'extra:residential_postal_code', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'residential_ownership', 'extra:residential_ownership', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'postal_line1', 'extra:postal_line1', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'postal_line2', 'extra:postal_line2', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'postal_line3', 'extra:postal_line3', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'postal_postal_code', 'extra:postal_postal_code', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'income_frequency', 'extra:income_frequency', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'home_telephone', 'extra:home_telephone', 0
    UNION ALL SELECT (SELECT id FROM intake_sources WHERE source_code = 'solid-desert'), 'loan_purpose_category', 'extra:loan_reason_code', 0
) AS new_mappings
WHERE NOT EXISTS (
    SELECT 1 FROM intake_field_mappings ifm
    WHERE ifm.intake_source_id = new_mappings.id
      AND ifm.incoming_field_name = new_mappings.incoming_field_name
);
