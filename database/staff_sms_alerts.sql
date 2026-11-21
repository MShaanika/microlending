-- Staff text-message alerts (new application / loan approved -> disburse). SAFE BY DEFAULT: the recipient lists start EMPTY, so nothing is sent
-- until somebody fills them in (comma-separated user ids from Users). Idempotent: running it again changes nothing.
--   SMS_ALERTS_ENABLED                      master switch ("1" on, anything else off)
--   SMS_ALERT_NEW_APPLICATION_USER_IDS      who is texted when an online application comes in
--   SMS_ALERT_APPROVED_USER_IDS             who is texted (to disburse) when SOMEONE ELSE approves a loan; the approver is never texted about their own approval
--   SMS_ALERT_NEW_APPLICATION_DAILY_LIMIT   most new-application SMS one person gets per day (the form is public: protects phones and the SMS bill)
--   SMS_ALERT_APPROVED_ON_APPLICATION      ...when the application is approved in the system        ("1" on)
--   SMS_ALERT_APPROVED_ON_LOAN              ...when the loan is approved in the system               ("1" on)
--   SMS_ALERT_APPROVED_ON_LOAN_QUESTION     ...when "Yes, I approved it" is answered in Loan Questions ("1" on)
-- A client produces only ONE approval SMS however many of those steps happen. The SMS go out through the normal notification dispatcher (every 5 min).
INSERT INTO notification_settings (setting_key, setting_value, channel) VALUES
    ('SMS_ALERTS_ENABLED', '1', 'SMS'),
    ('SMS_ALERT_NEW_APPLICATION_USER_IDS', '', 'SMS'),
    ('SMS_ALERT_APPROVED_USER_IDS', '', 'SMS'),
    ('SMS_ALERT_NEW_APPLICATION_DAILY_LIMIT', '30', 'SMS'),
    ('SMS_ALERT_APPROVED_ON_APPLICATION', '1', 'SMS'),
    ('SMS_ALERT_APPROVED_ON_LOAN', '1', 'SMS'),
    ('SMS_ALERT_APPROVED_ON_LOAN_QUESTION', '1', 'SMS')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
