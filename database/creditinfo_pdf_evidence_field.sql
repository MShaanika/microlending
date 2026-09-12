-- Creditinfo sign-off evidence needs "PDF token/result present? YES/NO" as
-- its own distinct field from Report Generation's reportToken -- the PDF
-- endpoint's own response shape is different (data.report base64 if
-- immediate, or data.token if async) and was previously not captured at
-- all by report_token_present, which only ever checked the
-- reports/custom response shape. See App\Services\CreditinfoClient::logDiagnostic().
ALTER TABLE creditinfo_diagnostic_log
    ADD COLUMN pdf_result_present TINYINT(1) NOT NULL DEFAULT 0 AFTER report_token_present;
