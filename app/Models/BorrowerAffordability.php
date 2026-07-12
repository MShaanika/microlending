<?php

namespace App\Models;

use App\Core\Model;

class BorrowerAffordability extends Model
{
    public function latestForBorrower(int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM borrower_affordability WHERE borrower_id = ? ORDER BY id DESC LIMIT 1", [$borrowerId]);
    }

    public function create(array $data): int
    {
        return $this->insert('borrower_affordability', $data);
    }
}
