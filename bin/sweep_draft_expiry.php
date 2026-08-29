<?php

/**
 * Deletes expired form_drafts (and their staged draft_documents rows +
 * files under storage/uploads/_drafts/{uuid}/) once past the configurable
 * retention window (system_settings.draft_retention_days, default 14 --
 * admin-configurable, not hardcoded). Meant to run once a day, e.g.:
 *
 *   0 3 * * * /usr/bin/php /path/to/bin/sweep_draft_expiry.php >> storage/logs/draft_expiry_sweep.log 2>&1
 *
 * Safe to run more than once -- only ever deletes rows whose expires_at is
 * already in the past; a no-op run touches nothing.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$db = Database::connection();
$expiredUuids = $db->query("SELECT draft_uuid FROM form_drafts WHERE expires_at < NOW()")->fetchAll(PDO::FETCH_COLUMN);

$deletedDrafts = 0;
$deletedFiles = 0;

foreach ($expiredUuids as $uuid) {
    $dir = STORAGE_PATH . '/uploads/_drafts/' . $uuid;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . '/' . $file);
                $deletedFiles++;
            }
        }
        @rmdir($dir);
    }

    $db->prepare('DELETE FROM draft_documents WHERE draft_uuid = ?')->execute([$uuid]);
    $db->prepare('DELETE FROM form_drafts WHERE draft_uuid = ?')->execute([$uuid]);
    $deletedDrafts++;
}

$summary = sprintf(
    "[%s] Swept %d expired draft(s), removed %d staged file(s).\n",
    date('Y-m-d H:i:s'),
    $deletedDrafts,
    $deletedFiles
);
\App\Core\JobHeartbeat::ping('sweep_draft_expiry', $summary, 1440);
echo $summary;
