<?php

namespace App\Models;

use App\Core\Model;

/**
 * Safe diagnostic evidence for every Creditinfo API call while the
 * integration is in UAT -- timestamp, environment, endpoint, HTTP status,
 * Creditinfo's own requestId/workflowId, outcome/status, whether a
 * subjectToken/reportToken was present (booleans only, never the token
 * value itself), duration, and any error code/message. Deliberately never
 * stores: Client Secret, access token, Authorization header, a complete
 * National ID (masked to first 4 chars + a fixed number of bullets --
 * see record()), or any report content. This is separate from
 * CreditinfoClient::lastDebug() (a console-only, per-request debug aid,
 * not persisted) and separate from CreditinfoReportCache (the business
 * audit trail of an actual credit check) -- this table exists purely as
 * UAT evidence for troubleshooting with the vendor.
 */
class CreditinfoDiagnosticLog extends Model
{
    public function record(array $data): int
    {
        if (!empty($data['national_id_used'])) {
            $data['national_id_masked'] = self::maskNationalId((string) $data['national_id_used']);
            unset($data['national_id_used']);
        }
        unset($data['subject_token'], $data['report_token'], $data['access_token'], $data['client_secret'], $data['authorization']);

        return $this->insert('creditinfo_diagnostic_log', $data);
    }

    public static function maskNationalId(string $idNumber): string
    {
        return substr($idNumber, 0, 4) . str_repeat('•', max(0, strlen($idNumber) - 4));
    }

    public function recent(int $limit = 50): array
    {
        return $this->all(
            "SELECT d.*, u.name AS triggered_by_name FROM creditinfo_diagnostic_log d
             LEFT JOIN users u ON u.id = d.triggered_by
             ORDER BY d.id DESC LIMIT " . max(1, min(200, $limit))
        );
    }
}
