<?php

namespace App\Models;

use App\Core\Model;

/**
 * One row per actual Creditinfo submission EVENT -- staff manually
 * recording that they submitted a listing/removal via Creditinfo's own
 * User Interface (or, once implemented, SFTP). Creditinfo has confirmed
 * in writing there is no REST API for Public Defaults -- this table is
 * the evidence trail for the manual/SFTP boundary that replaces it, never
 * a record of an automated call DesertLedger itself made.
 */
class CreditinfoPublicDefaultSubmission extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_public_default_submissions', $data);
    }

    public function forPublicDefault(int $publicDefaultId): array
    {
        return $this->all(
            "SELECT s.*, u.name AS submitted_by_name
             FROM creditinfo_public_default_submissions s
             LEFT JOIN users u ON u.id = s.submitted_by
             WHERE s.public_default_id = ? ORDER BY s.submitted_at DESC",
            [$publicDefaultId]
        );
    }

    public function latestForPublicDefault(int $publicDefaultId): ?array
    {
        return $this->one(
            "SELECT * FROM creditinfo_public_default_submissions WHERE public_default_id = ? ORDER BY submitted_at DESC LIMIT 1",
            [$publicDefaultId]
        );
    }

    /** For the Compliance dashboard -- every manual (or, once built, SFTP) submission event, across every default. */
    public function recent(int $limit = 20): array
    {
        return $this->all(
            "SELECT s.*, u.name AS submitted_by_name, pd.listing_reference
             FROM creditinfo_public_default_submissions s
             LEFT JOIN users u ON u.id = s.submitted_by
             LEFT JOIN creditinfo_public_defaults pd ON pd.id = s.public_default_id
             ORDER BY s.submitted_at DESC LIMIT " . max(1, $limit)
        );
    }
}
