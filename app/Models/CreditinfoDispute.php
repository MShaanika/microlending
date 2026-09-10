<?php

namespace App\Models;

use App\Core\Model;

/**
 * Borrower-level Creditinfo bureau dispute record (item 6). The Subscriber
 * Agreement requires the subscriber not to penalise a consumer while a
 * dispute is under investigation -- CreditinfoPublicDefaultService checks
 * hasOpenDispute() before allowing a new listing, unconditionally.
 */
class CreditinfoDispute extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_disputes', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.borrower_no
             FROM creditinfo_disputes d
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public function hasOpenDispute(int $borrowerId): bool
    {
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM creditinfo_disputes WHERE borrower_id = ? AND status = 'Open'",
            [$borrowerId]
        );
    }

    public function countOpen(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM creditinfo_disputes WHERE status = 'Open'");
    }

    public function openForBorrower(int $borrowerId): ?array
    {
        return $this->one(
            "SELECT * FROM creditinfo_disputes WHERE borrower_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1",
            [$borrowerId]
        );
    }

    public function allDisputes(): array
    {
        return $this->all(
            "SELECT d.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.borrower_no, u.name AS recorded_by_name
             FROM creditinfo_disputes d
             JOIN borrowers b ON b.id = d.borrower_id
             LEFT JOIN users u ON u.id = d.recorded_by
             ORDER BY d.status = 'Open' DESC, d.opened_at DESC"
        );
    }

    public function resolve(int $id, int $resolvedBy): bool
    {
        return $this->update('creditinfo_disputes', [
            'status' => 'Resolved',
            'resolved_by' => $resolvedBy,
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }
}
