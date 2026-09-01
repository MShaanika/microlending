-- Adds the flag that lets a loan recognize its full interest upfront at
-- disbursement (a flat, non-refundable fee) instead of progressively per
-- installment -- see app/Services/InterestAccrualService.php::recognizeUpfront().
-- Snapshotted once at loan creation from loan_products.interest_method
-- ('Fixed Fee' -> 'Upfront'), same convention as interest_rate/penalty_rate/
-- admin_fee/term_months already being copied onto the loans row rather than
-- joined live -- so editing a product later never changes an existing loan's
-- treatment.
ALTER TABLE loans
    ADD COLUMN interest_recognition_method ENUM('Progressive','Upfront') NOT NULL DEFAULT 'Progressive' AFTER interest_rate;

-- Records which of the two supported write-off accounting treatments was
-- actually used for this write-off -- see app/Controllers/LoanWriteOffController.php.
ALTER TABLE loan_write_offs
    ADD COLUMN write_off_method ENUM('Allowance','Direct') NULL AFTER net_write_off_amount;

-- Controls which method new write-off requests use by default (or whether
-- Finance must pick one per write-off). Read via App\Models\SystemSetting::get().
INSERT INTO system_settings (setting_key, setting_value, module_name)
VALUES ('LOAN_WRITE_OFF_METHOD', 'SELECT_AT_WRITE_OFF', 'Accounting')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
