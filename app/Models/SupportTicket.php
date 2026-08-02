<?php

namespace App\Models;

use App\Core\Model;

class SupportTicket extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN branches br ON br.id = t.branch_id
        LEFT JOIN users raiser ON raiser.id = t.raised_by
        LEFT JOIN users dev ON dev.id = t.assigned_to
    ";
    private const LOOKUP_COLUMNS = "
        br.branch_name, raiser.name AS raised_by_name, dev.name AS assigned_to_name
    ";

    public function paginated(string $status = '', ?int $branchId = null, int $limit = 200): array
    {
        $sql = "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM support_tickets t " . self::LOOKUP_JOINS . " WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        if ($branchId !== null) {
            $sql .= " AND t.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY FIELD(t.status,'Open','In Progress','Resolved','Closed'), t.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM support_tickets t " . self::LOOKUP_JOINS . " WHERE t.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('support_tickets', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('support_tickets', $data, 'id', $id);
    }
}
