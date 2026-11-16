-- CPL batches built from the old loan system (SolidFin) instead of this system's own loans.
-- Such a batch has no per-loan snapshots: its file is built by bin/cpl_legacy_extract.php --create-batch and frozen in file_content
-- at once; the accounts that could not be sent are kept in legacy_exceptions (JSON) for the review screen.
ALTER TABLE cpl_batches
    ADD COLUMN source ENUM('Local', 'SolidFin') NOT NULL DEFAULT 'Local' AFTER batch_type,
    ADD COLUMN legacy_exceptions LONGTEXT NULL AFTER file_content,
    ADD COLUMN legacy_summary TEXT NULL AFTER legacy_exceptions;
