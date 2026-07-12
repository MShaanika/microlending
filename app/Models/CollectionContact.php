<?php

namespace App\Models;

use App\Core\Model;

class CollectionContact extends Model
{
    public function forLoan(int $loanId): array
    {
        return $this->all(
            "SELECT cc.*, u.name AS contacted_by_name
             FROM collection_contacts cc
             LEFT JOIN users u ON u.id = cc.contacted_by
             WHERE cc.loan_id = ? ORDER BY cc.contacted_at DESC",
            [$loanId]
        );
    }

    public function countForLoan(int $loanId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM collection_contacts WHERE loan_id = ?", [$loanId]);
    }

    public function latestForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT * FROM collection_contacts WHERE loan_id = ? ORDER BY contacted_at DESC LIMIT 1",
            [$loanId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('collection_contacts', $data);
    }
}
