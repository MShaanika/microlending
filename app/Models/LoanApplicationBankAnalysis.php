<?php

namespace App\Models;

use App\Core\Model;

class LoanApplicationBankAnalysis extends Model
{
    protected string $table = 'loan_application_bank_analysis';

    public function forApplication(int $applicationId): ?array
    {
        return $this->one(
            "SELECT * FROM loan_application_bank_analysis WHERE application_id = ? ORDER BY id DESC LIMIT 1",
            [$applicationId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('loan_application_bank_analysis', $data);
    }
}
