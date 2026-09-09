<?php

namespace App\Models;

use App\Core\Model;

/**
 * Permanent, audit-minimal record of one credit check attempt -- workflow/
 * request/subject/creditinfoId, outcome, status, decision_at (the
 * retention trigger), purged_at. Never holds substantive report content --
 * see CreditinfoReportContent, its purgeable 1:1 child.
 */
class CreditinfoReportCache extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_report_cache', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM creditinfo_report_cache WHERE id = ?", [$id]);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('creditinfo_report_cache', $data, 'id', $id);
    }

    public function latestForApplication(int $applicationId): ?array
    {
        return $this->one(
            "SELECT * FROM creditinfo_report_cache WHERE application_id = ? ORDER BY id DESC LIMIT 1",
            [$applicationId]
        );
    }

    /** Rows bin/poll_creditinfo_reports.php should check on -- report_status not yet in a terminal state. */
    public function pending(): array
    {
        return $this->all(
            "SELECT * FROM creditinfo_report_cache WHERE report_status IN ('New','InProgress') ORDER BY id ASC"
        );
    }

    /** Stamps decision_at on the parent and (if not yet purged) the content child -- called from ApplicationController::approve()/reject(). */
    public function stampDecision(int $applicationId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->query(
            "UPDATE creditinfo_report_cache SET decision_at = ? WHERE application_id = ? AND decision_at IS NULL",
            [$now, $applicationId]
        );
        $this->query(
            "UPDATE creditinfo_report_content c
             JOIN creditinfo_report_cache r ON r.id = c.report_cache_id
             SET c.decision_at = ?
             WHERE r.application_id = ? AND c.decision_at IS NULL",
            [$now, $applicationId]
        );
    }
}
