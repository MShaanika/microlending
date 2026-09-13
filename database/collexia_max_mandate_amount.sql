-- Collexia rejects a single EnDO mandate above some maximum amount with
-- "10569 Mandate amount limit exceeded" -- confirmed by direct evidence on
-- debit order #36 (N$548.33 registered fine twice; N$1,096.66 was rejected
-- with this exact code) but never confirmed by Collexia in writing. This
-- seeds a deliberately conservative local safety limit, NOT a
-- Collexia-confirmed number -- see App\Models\CollexiaSetting::maxSingleMandateAmount().
-- Editable in Settings; blank it to disable the local check entirely once a
-- real confirmed value supersedes it.
INSERT INTO collexia_settings (setting_key, setting_value)
SELECT 'collexia_max_single_mandate_amount', '1000.00'
WHERE NOT EXISTS (SELECT 1 FROM collexia_settings WHERE setting_key = 'collexia_max_single_mandate_amount');
