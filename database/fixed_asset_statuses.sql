-- Two more statuses for a fixed asset, for assets that are still owned but not in normal use:
--   Under Maintenance  the asset is being fixed
--   Damaged            the asset is broken but still owned (it has not been disposed of)
-- Both keep depreciating like an Active asset. The existing values keep their meaning; the new ones are added at the END of the list, so no
-- existing row is rewritten. Safe to re-run. Apply BEFORE deploying the code that offers them (the asset page's Change Status).
ALTER TABLE fixed_assets
    MODIFY COLUMN status ENUM('Active','Fully Depreciated','Disposed','Written Off','Under Maintenance','Damaged') NOT NULL DEFAULT 'Active';
