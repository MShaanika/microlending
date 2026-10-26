-- The website Apply form (apply-dg.php) now marks every field the monthly CPL submission needs as required. This makes the intake endpoint
-- enforce the same list server-side (ApplicationIntakeController::normalize() answers 400 "Missing required field(s)" for a blank one), so a
-- staff or applicant submission that skips them is refused instead of producing an application CplReadinessService calls Incomplete.
--
-- Already required before this: firstname, lastname, id_number, email, contact_no, gross_salary (plus loan amount and term).
-- Deploy the updated apply-dg.php FIRST: with this applied and the old form live, people could fill the whole form and then be refused.

UPDATE intake_field_mappings
SET is_required = 1
WHERE intake_source_id = (SELECT id FROM intake_sources WHERE source_code = 'solid-desert')
  AND incoming_field_name IN ('date_of_birth', 'residential_line1', 'residential_line3', 'residential_ownership', 'company_name', 'income_frequency');
