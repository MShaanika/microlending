-- Final Notice of Default and Demand for Payment -- a collections letter
-- for a loan actually in arrears, generated through the same "Generate
-- Letter" screen as Completion/Consolidation (App\Controllers\
-- LetterController). CREDIT_LISTING_YEARS is NOT a confirmed regulatory
-- figure (see App\Services\DocumentFieldResolver::computed(), 'credit_listing_years'
-- case) -- confirm the real credit bureau listing retention period with
-- the business before this letter is sent to a real client.

INSERT INTO document_templates (category_id, template_code, template_name, template_type, file_type, file_path, description, is_active) VALUES
(1, 'FINAL_NOTICE_OF_DEFAULT', 'Final Notice of Default and Demand for Payment', 'Notice', 'DOCX', 'document_templates/final_notice_of_default.docx', 'Sent to a client whose loan is in arrears, ahead of debt collector handover / legal action / credit bureau listing.', 1);

INSERT INTO document_template_fields (template_id, field_key, field_label, source_table, source_column, is_required)
SELECT id, 'CURRENT_DATE', 'Letter Date', 'computed', 'current_date', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'CLIENT_NAME', 'Client Name', 'computed', 'client_name', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'CLIENT_ADDRESS', 'Client Physical Address', 'borrowers', 'physical_address', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'ACCOUNT_NUMBER', 'Account Number', 'borrowers', 'borrower_no', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'LOAN_REFERENCE', 'Loan Reference', 'loans', 'loan_no', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'DAYS_OVERDUE', 'Days Overdue', 'computed', 'days_overdue', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'OVERDUE_AMOUNT', 'Overdue Amount', 'computed', 'overdue_amount', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'CURE_DEADLINE_DATE', 'Payment Deadline', 'computed', 'cure_deadline_date', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'CREDIT_LISTING_YEARS', 'Credit Bureau Listing Years', 'computed', 'credit_listing_years', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'SENDER_NAME', 'Sender Name', 'computed', 'sender_name', 0 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT'
UNION ALL SELECT id, 'COMPANY_NAME', 'Company Name', 'computed', 'lender_name', 1 FROM document_templates WHERE template_code = 'FINAL_NOTICE_OF_DEFAULT';
