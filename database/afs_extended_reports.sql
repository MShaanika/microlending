-- Manual/judgment figures for the extended AFS export (Tax Computation,
-- Statement of Changes in Equity, Notes to the AFS). Everything that CAN
-- be derived from posted ledger/fixed-asset data is computed live by
-- AfsReportService -- this table only holds what genuinely can't be
-- (prior-year assessed loss carried forward, Section 17 investment
-- allowance, capital allowance amounts, member transaction narratives,
-- borrowing narratives, and the mostly-static accounting policy text),
-- scoped per fiscal year since real figures change annually.

CREATE TABLE afs_manual_figures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year_id BIGINT NOT NULL,
    section_key VARCHAR(60) NOT NULL,
    line_key VARCHAR(100) NOT NULL,
    label VARCHAR(255) NULL,
    value_text TEXT NULL,
    value_number DECIMAL(18,2) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fy_section_line (fiscal_year_id, section_key, line_key),
    FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
