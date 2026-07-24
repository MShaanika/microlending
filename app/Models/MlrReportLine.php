<?php

namespace App\Models;

use App\Core\Model;

class MlrReportLine extends Model
{
    public function forReport(int $reportId): array
    {
        return $this->all("SELECT * FROM mlr_report_lines WHERE regulatory_report_id = ? ORDER BY id", [$reportId]);
    }

    public function insertLines(int $reportId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->insert('mlr_report_lines', array_merge($line, ['regulatory_report_id' => $reportId]));
        }
    }
}
