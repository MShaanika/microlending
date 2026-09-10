<?php

namespace App\Models;

use App\Core\Model;

/** Borrower notice/evidence before listing (item 8). One default can have more than one notice attempt recorded. */
class CreditinfoPublicDefaultNotice extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_public_default_notices', $data);
    }

    public function forPublicDefault(int $publicDefaultId): array
    {
        return $this->all(
            "SELECT n.*, u.name AS notice_sent_by_name
             FROM creditinfo_public_default_notices n
             LEFT JOIN users u ON u.id = n.notice_sent_by
             WHERE n.public_default_id = ? ORDER BY n.created_at DESC",
            [$publicDefaultId]
        );
    }

    public function latestForPublicDefault(int $publicDefaultId): ?array
    {
        return $this->one(
            "SELECT * FROM creditinfo_public_default_notices WHERE public_default_id = ? ORDER BY created_at DESC LIMIT 1",
            [$publicDefaultId]
        );
    }
}
