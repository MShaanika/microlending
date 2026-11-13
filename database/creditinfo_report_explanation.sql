-- Plain-language explanation of a Creditinfo credit report, written by the AI analyser on request from an ANONYMISED copy of the figures
-- (never the person's name, ID number, address or contact details -- see App\Services\CreditinfoReportMinimizer).
--
-- Stored encrypted (App\Core\Encryption, like the report itself) on the SAME row as the report content. The retention purge deletes that
-- whole row (App\Services\RetentionService), so the explanation disappears together with the report it explains.
--
-- Nullable: every existing report simply has no explanation until someone asks for one.
ALTER TABLE creditinfo_report_content
    ADD COLUMN explanation_encrypted LONGTEXT NULL AFTER pdf_content_encrypted;
