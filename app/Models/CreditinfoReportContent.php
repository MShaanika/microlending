<?php

namespace App\Models;

use App\Core\Encryption;
use App\Core\Model;

/**
 * The substantive, purgeable half of a credit check -- report JSON and PDF
 * bytes, both encrypted at rest via App\Core\Encryption (the same class
 * CreditinfoSetting uses for its own secrets). Deleted whole by
 * RetentionService once the linked retention policy is confirmed and
 * activated; the parent creditinfo_report_cache row (audit fields only)
 * survives that deletion untouched.
 */
class CreditinfoReportContent extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_report_content', $data);
    }

    public function findByReportCacheId(int $reportCacheId): ?array
    {
        return $this->one("SELECT * FROM creditinfo_report_content WHERE report_cache_id = ?", [$reportCacheId]);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('creditinfo_report_content', $data, 'id', $id);
    }

    public function storeReport(int $reportCacheId, array $reportData, ?string $reportToken): void
    {
        $existing = $this->findByReportCacheId($reportCacheId);
        $encrypted = Encryption::encrypt(json_encode($reportData));
        if ($existing) {
            $changes = ['report_content_encrypted' => $encrypted, 'report_token' => $reportToken];
            // An explanation describes the report it was made from. When the report is replaced it no longer applies. (Only touched when there is
            // one, so a server that has not had the explanation column added yet is not affected.)
            if (!empty($existing['explanation_encrypted'])) {
                $changes['explanation_encrypted'] = null;
            }
            $this->updateFields((int) $existing['id'], $changes);
            return;
        }
        $this->create([
            'report_cache_id' => $reportCacheId,
            'report_token' => $reportToken,
            'report_content_encrypted' => $encrypted,
        ]);
    }

    public function storePdf(int $reportCacheId, string $base64Pdf, ?string $pdfToken): void
    {
        $existing = $this->findByReportCacheId($reportCacheId);
        $encrypted = Encryption::encrypt($base64Pdf);
        if ($existing) {
            $this->updateFields((int) $existing['id'], ['pdf_content_encrypted' => $encrypted, 'pdf_token' => $pdfToken]);
            return;
        }
        $this->create([
            'report_cache_id' => $reportCacheId,
            'pdf_token' => $pdfToken,
            'pdf_content_encrypted' => $encrypted,
        ]);
    }

    public function decryptedReport(array $contentRow): ?array
    {
        if (empty($contentRow['report_content_encrypted'])) {
            return null;
        }
        $json = Encryption::decrypt($contentRow['report_content_encrypted']);
        return $json !== null ? json_decode($json, true) : null;
    }

    /**
     * Saves the plain-language explanation next to the report it explains (encrypted, like the report). Returns false when there is no stored
     * report to attach it to. It lives on the report's own row, so the retention purge that deletes that row removes it too.
     *
     * @param array<string,mixed> $explanation
     */
    public function storeExplanation(int $reportCacheId, array $explanation): bool
    {
        $existing = $this->findByReportCacheId($reportCacheId);
        if (!$existing) {
            return false;
        }
        $this->updateFields((int) $existing['id'], [
            'explanation_encrypted' => Encryption::encrypt((string) json_encode($explanation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function decryptedExplanation(array $contentRow): ?array
    {
        if (empty($contentRow['explanation_encrypted'])) {
            return null;
        }
        $json = Encryption::decrypt($contentRow['explanation_encrypted']);
        $data = $json !== null ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }

    public function decryptedPdf(array $contentRow): ?string
    {
        if (empty($contentRow['pdf_content_encrypted'])) {
            return null;
        }
        return Encryption::decrypt($contentRow['pdf_content_encrypted']);
    }
}
