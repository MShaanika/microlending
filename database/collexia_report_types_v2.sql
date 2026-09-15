-- Adds the 6 newly-supported Collexia report import types (see
-- CollexiaReportReader::detectReportType() and the matching parser
-- classes) to the existing report_type ENUM. Additive only -- the 3
-- original values plus 'CollexiaAPI' (added by collexia_v3_reconciliation.sql)
-- are untouched.

ALTER TABLE debit_order_collection_imports
    MODIFY report_type ENUM(
        'Successful', 'Unsuccessful', 'Scheduled', 'CollexiaAPI',
        'FailedValidation', 'SuccessfulSimplified', 'SuccessfulDetail',
        'ScheduledDetail', 'ScheduledForecast', 'MandateAudit'
    ) NOT NULL DEFAULT 'Successful';
