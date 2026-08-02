<?php

namespace App\Models;

use App\Core\Model;

class SupportTicketComment extends Model
{
    public function forTicket(int $ticketId): array
    {
        return $this->all(
            "SELECT c.*, u.name AS user_name
             FROM support_ticket_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = ?
             ORDER BY c.id ASC",
            [$ticketId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('support_ticket_comments', $data);
    }
}
