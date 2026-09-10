<?php

namespace App\Models;

use App\Core\Model;

/**
 * One row per real consent capture event -- who/when/how/which
 * application. A credit check can never run without an unconsumed row
 * here; never a hardcoded "consent": true anywhere in the codebase.
 */
class CreditBureauConsent extends Model
{
    public function create(array $data): int
    {
        return $this->insert('credit_bureau_consents', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM credit_bureau_consents WHERE id = ?", [$id]);
    }

    /** Most recent consent for this application that has not yet been consumed by a report_cache row. */
    public function latestUnconsumedForApplication(int $applicationId): ?array
    {
        return $this->one(
            "SELECT c.* FROM credit_bureau_consents c
             LEFT JOIN creditinfo_report_cache r ON r.consent_id = c.id
             WHERE c.application_id = ? AND r.id IS NULL
             ORDER BY c.id DESC LIMIT 1",
            [$applicationId]
        );
    }

    public function latestForApplication(int $applicationId): ?array
    {
        return $this->one(
            "SELECT * FROM credit_bureau_consents WHERE application_id = ? ORDER BY id DESC LIMIT 1",
            [$applicationId]
        );
    }

    /** Read-only register across every consent capture (CBS and Public Defaults share this same table) -- see CreditinfoConsentRegisterController. */
    public function paginated(int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM credit_bureau_consents");
        $rows = $this->all(
            "SELECT c.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.borrower_no,
                    u.name AS recorded_by_name,
                    (SELECT COUNT(*) FROM creditinfo_report_cache r WHERE r.consent_id = c.id) AS consumed_by_cbs_check
             FROM credit_bureau_consents c
             LEFT JOIN borrowers b ON b.id = c.borrower_id
             LEFT JOIN users u ON u.id = c.recorded_by
             ORDER BY c.id DESC LIMIT $perPage OFFSET $offset"
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }
}
