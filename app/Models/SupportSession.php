<?php

namespace App\Models;

use App\Core\Model;

class SupportSession extends Model
{
    public function create(array $data): int
    {
        return $this->insert('support_sessions', $data);
    }

    public function find(int $id): ?array
    {
        return $this->findBy('support_sessions', 'id', $id);
    }

    public function activeForDeveloper(int $developerId): ?array
    {
        return $this->one(
            "SELECT * FROM support_sessions
             WHERE developer_id = ? AND ended_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1",
            [$developerId]
        );
    }

    public function activeForTicket(int $ticketId): ?array
    {
        return $this->one(
            "SELECT * FROM support_sessions
             WHERE ticket_id = ? AND ended_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1",
            [$ticketId]
        );
    }

    public function forTicket(int $ticketId): array
    {
        return $this->all(
            "SELECT s.*, u.name AS developer_name, br.branch_name
             FROM support_sessions s
             JOIN users u ON u.id = s.developer_id
             JOIN branches br ON br.id = s.branch_id
             WHERE s.ticket_id = ?
             ORDER BY s.id DESC",
            [$ticketId]
        );
    }

    public function end(int $id, string $reason): bool
    {
        return $this->update('support_sessions', [
            'ended_at' => date('Y-m-d H:i:s'),
            'ended_reason' => $reason,
        ], 'id', $id);
    }

    public function endActiveForTicket(int $ticketId, string $reason): void
    {
        $active = $this->activeForTicket($ticketId);
        if ($active) {
            $this->end((int) $active['id'], $reason);
        }
    }

    public function endActiveForDeveloper(int $developerId, string $reason): void
    {
        $active = $this->activeForDeveloper($developerId);
        if ($active) {
            $this->end((int) $active['id'], $reason);
        }
    }
}
