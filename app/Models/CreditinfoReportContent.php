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
            $this->updateFields((int) $existing['id'], ['report_content_encrypted' => $encrypted, 'report_token' => $reportToken]);
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

    public function decryptedPdf(array $contentRow): ?string
    {
        if (empty($contentRow['pdf_content_encrypted'])) {
            return null;
        }
        return Encryption::decrypt($contentRow['pdf_content_encrypted']);
    }
}
