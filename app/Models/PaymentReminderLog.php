<?php

namespace App\Models;

use App\Core\Model;

class PaymentReminderLog extends Model
{
    public function find(int $loanScheduleId, string $reminderType): ?array
    {
        return $this->one(
            "SELECT * FROM payment_reminder_sends WHERE loan_schedule_id = ? AND reminder_type = ?",
            [$loanScheduleId, $reminderType]
        );
    }

    public function recordSend(int $loanScheduleId, string $reminderType): void
    {
        $this->query(
            "INSERT INTO payment_reminder_sends (loan_schedule_id, reminder_type, last_sent_at, send_count)
             VALUES (?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE last_sent_at = NOW(), send_count = send_count + 1",
            [$loanScheduleId, $reminderType]
        );
    }
}
