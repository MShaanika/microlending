-- Creditinfo Namibia CBS: vendor-confirmed gender and inquiry-reason codes.
--
-- Creditinfo has confirmed in writing:
--   Gender:          1 = Male, 2 = Female
--   Inquiry reasons: 0 = NotSpecified, 1 = ApplicationForCreditOrAmendmentOfCreditTerms,
--                     9 = AnotherReason, 17 = CreditRenewal, 36 = CustomerInquiry,
--                    41 = InsuranceApplication
-- (see App\Support\CreditinfoV3Codes for the permanent code table). This
-- seeds the corresponding creditinfo_settings rows so the UAT Readiness
-- screen reads them as actually configured, not just falling back to the
-- in-code default -- CreditinfoUatReadinessService::vendorConfirmedItem()
-- checks the stored row via allSettings(), not CreditinfoSetting::get()'s
-- fallback default.
--
-- Default new-credit inquiry reason (search): ApplicationForCreditOrAmendmentOfCreditTerms (1)
-- -- the best fit for a normal new loan application / credit affordability
-- and eligibility assessment. Report inquiry reason (36, CustomerInquiry)
-- is unchanged from the value already in use -- only its status moves from
-- "unconfirmed guess" to vendor-confirmed.
--
-- NOT YET APPLIED TO PRODUCTION. Prepared per explicit instruction, pending
-- the user's review of the corresponding code changes. Do not run until
-- told to deploy. Idempotent (ON DUPLICATE KEY UPDATE) -- safe to re-run.

INSERT INTO creditinfo_settings (setting_key, setting_value) VALUES
('creditinfo_gender_code_male', '1'),
('creditinfo_gender_code_female', '2'),
('creditinfo_inquiry_reason_search', '1'),
('creditinfo_inquiry_reason_report', '36')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
