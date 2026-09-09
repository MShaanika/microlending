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
}
