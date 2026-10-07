<?php

namespace App\Models;

use App\Core\Model;

/** One rendered line within a creditinfo_public_default_batches file -- see that table's header comment on why rendered_line is a stored snapshot, not regenerated on read. */
class CreditinfoPublicDefaultBatchItem extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_public_default_batch_items', $data);
    }

    public function forBatch(int $batchId): array
    {
        return $this->all(
            "SELECT i.*, pd.listing_reference, pd.status AS public_default_status,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name, l.loan_no
             FROM creditinfo_public_default_batch_items i
             JOIN creditinfo_public_defaults pd ON pd.id = i.public_default_id
             JOIN borrowers b ON b.id = pd.borrower_id
             JOIN loans l ON l.id = pd.loan_id
             WHERE i.batch_id = ? ORDER BY i.line_number",
            [$batchId]
        );
    }

    public function publicDefaultIdsForBatch(int $batchId): array
    {
        $rows = $this->all('SELECT public_default_id FROM creditinfo_public_default_batch_items WHERE batch_id = ?', [$batchId]);
        return array_map('intval', array_column($rows, 'public_default_id'));
    }
}
