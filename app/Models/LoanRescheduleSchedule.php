<?php

namespace App\Models;

use App\Core\Model;

class LoanRescheduleSchedule extends Model
{
    public function forReschedule(int $rescheduleId): array
    {
        return $this->all("SELECT * FROM loan_reschedule_schedules WHERE reschedule_id = ? ORDER BY installment_no", [$rescheduleId]);
    }

    public function create(array $data): int
    {
        return $this->insert('loan_reschedule_schedules', $data);
    }

    public function activateForReschedule(int $rescheduleId): bool
    {
        return $this->update('loan_reschedule_schedules', ['status' => 'Active'], 'reschedule_id', $rescheduleId);
    }
}
