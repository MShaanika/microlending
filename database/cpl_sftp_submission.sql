-- CPL files are submitted through the same Creditinfo SFTP account
-- Public Defaults already uses (confirmed 2026-10-11 by Creditinfo's own
-- reply to a CPL file sent through that channel, plus their shared
-- example filename matching the pattern already built in
-- CplBatchController::download()). See App\Services\CplSftpService --
-- host/username/password are deliberately NOT duplicated here, they're
-- read from creditinfo_public_default_settings to avoid the two
-- drifting out of sync.
--
-- sftp_submission_enabled defaults 'off': an admin must explicitly turn
-- this on (same "no automated send until a human opts in" convention as
-- Public Defaults' own sftp_enabled), separately from Public Defaults
-- being enabled.
-- sftp_directory blank = reuse Public Defaults' own sftp_outbound_directory.

INSERT IGNORE INTO cpl_settings (setting_key, setting_value) VALUES
('sftp_submission_enabled', 'off'),
('sftp_directory', '');
