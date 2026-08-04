<?php

namespace App\Models;

use App\Core\Model;

class AiVoiceCall extends Model
{
    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM ai_voice_calls WHERE id = ?", [$id]);
    }

    public function findByProviderCallId(string $providerCallId): ?array
    {
        return $this->one("SELECT * FROM ai_voice_calls WHERE provider_call_id = ?", [$providerCallId]);
    }

    public function forLoan(int $loanId): array
    {
        return $this->all(
            "SELECT * FROM ai_voice_calls WHERE loan_id = ? ORDER BY triggered_at DESC",
            [$loanId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('ai_voice_calls', $data);
    }

    public function updateFromWebhook(int $id, array $data): bool
    {
        return $this->update('ai_voice_calls', $data, 'id', $id);
    }
}
