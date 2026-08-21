<?php

namespace App\Models;

use App\Core\Model;

/**
 * Manual/judgment figures for the extended AFS export -- see
 * database/afs_extended_reports.sql for what belongs here vs. what
 * AfsReportService computes live from the ledger.
 */
class AfsManualFigure extends Model
{
    /** @return array<string, array{label: ?string, value_text: ?string, value_number: ?float}> keyed by line_key */
    public function forSection(int $fiscalYearId, string $sectionKey): array
    {
        $rows = $this->query(
            "SELECT line_key, label, value_text, value_number FROM afs_manual_figures WHERE fiscal_year_id = ? AND section_key = ?",
            [$fiscalYearId, $sectionKey]
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['line_key']] = [
                'label' => $row['label'],
                'value_text' => $row['value_text'],
                'value_number' => $row['value_number'] !== null ? (float) $row['value_number'] : null,
            ];
        }
        return $map;
    }

    public function set(int $fiscalYearId, string $sectionKey, string $lineKey, ?string $label, ?string $valueText, ?float $valueNumber, ?int $userId): void
    {
        $this->query(
            "INSERT INTO afs_manual_figures (fiscal_year_id, section_key, line_key, label, value_text, value_number, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), value_text = VALUES(value_text), value_number = VALUES(value_number), updated_by = VALUES(updated_by)",
            [$fiscalYearId, $sectionKey, $lineKey, $label, $valueText, $valueNumber, $userId]
        );
    }
}
