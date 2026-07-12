<?php

namespace App\Models;

use App\Core\Model;

class LoanApplicationScreening extends Model
{
    protected string $table = 'loan_application_screening';

    public function forApplication(int $applicationId): ?array
    {
        return $this->one("SELECT * FROM loan_application_screening WHERE application_id = ? ORDER BY id DESC LIMIT 1", [$applicationId]);
    }

    public function create(array $data): int
    {
        return $this->insert('loan_application_screening', $data);
    }
}
