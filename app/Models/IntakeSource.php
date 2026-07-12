<?php

namespace App\Models;

use App\Core\Model;

/**
 * One row per external client website/form that submits applications into
 * this system. New clients with differently-shaped forms are onboarded by
 * adding a row here plus intake_field_mappings rows -- not a code change.
 * See ApplicationIntakeController.
 */
class IntakeSource extends Model
{
    protected string $table = 'intake_sources';

    public function findActiveByCodeAndToken(string $code, string $token): ?array
    {
        return $this->one(
            "SELECT * FROM intake_sources WHERE source_code = ? AND api_token = ? AND is_active = 1",
            [$code, $token]
        );
    }

    public function fieldMappings(int $sourceId): array
    {
        return $this->all("SELECT * FROM intake_field_mappings WHERE intake_source_id = ?", [$sourceId]);
    }

    public function recentSubmissionCount(int $sourceId, string $ip, int $sinceMinutes = 60): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM intake_submission_log
             WHERE intake_source_id = ? AND ip_address = ? AND submitted_at >= (NOW() - INTERVAL ? MINUTE)",
            [$sourceId, $ip, $sinceMinutes]
        );
    }

    public function logSubmission(int $sourceId, string $ip): int
    {
        return $this->insert('intake_submission_log', [
            'intake_source_id' => $sourceId,
            'ip_address' => $ip,
        ]);
    }
}
