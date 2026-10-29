-- The loan terms the applicant sees and accepts on the website form (finance charges, total repayable, instalment) were being dropped at the
-- intake because only fields with a mapping are kept. Keep them as extra:* so the Loan Agreement can be filled with them
-- (App\Services\ApplicationPdfFillService). Applies to every intake source that already maps loan_amount; safe to re-run.
INSERT IGNORE INTO intake_field_mappings (intake_source_id, incoming_field_name, target_field, is_required)
SELECT m.intake_source_id, t.incoming, CONCAT('extra:', t.incoming), 0
FROM intake_field_mappings m
JOIN (SELECT 'finance_charges_amount' AS incoming UNION ALL SELECT 'total_repayable' UNION ALL SELECT 'installment_amount') t
WHERE m.incoming_field_name = 'loan_amount';
