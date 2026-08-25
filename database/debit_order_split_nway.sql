-- Generalizes the original fixed 2-leg (A/B) split design -- see
-- debit_order_split.sql -- into a configurable N-way split (1-10 parts,
-- chosen at registration, each amount entered individually rather than
-- forced to an equal half). No live split data exists yet anywhere (no
-- mandate has ever been placed for a split_enabled debit order -- checked
-- against production before writing this), so this is a clean rename/widen,
-- not a data migration.
--
-- 'leg' CHAR(1) ('A'/'B') becomes 'split_no' TINYINT (1-10): a stable,
-- never-reused position within this debit order's split set, used for both
-- Collexia contract-reference generation and the "2 of 4" style display.
-- total_splits records how many parts the CURRENT live set displays as
-- (stays fixed even after a merge reduces the number of live rows, so a
-- merged transaction still reads as part of the original N).
-- merged_into_id marks a row as folded into a newer merged row -- it is
-- never deleted, only ever cancelled and linked, so the full split/merge
-- history is always reconstructable.

ALTER TABLE debit_order_split_legs
    CHANGE COLUMN leg split_no TINYINT UNSIGNED NOT NULL,
    ADD COLUMN total_splits TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER split_no,
    ADD COLUMN merged_into_id BIGINT NULL AFTER collexia_api_synced_at,
    DROP INDEX unique_debit_order_leg,
    ADD UNIQUE KEY unique_debit_order_split (debit_order_id, split_no),
    ADD FOREIGN KEY (merged_into_id) REFERENCES debit_order_split_legs(id);

ALTER TABLE debit_order_collections
    CHANGE COLUMN leg split_no INT NULL;
