<?php

namespace App\Models;

use App\Core\Model;

class UploadRequirement extends Model
{
    /**
     * The two documents every borrower intake needs. A database whose requirements table is empty (production was cleared before launch) would
     * otherwise show no upload box for them at all, so they are added when missing. A type that exists is never touched or re-added, even when an
     * administrator switched it off.
     */
    private const DEFAULTS = [['National ID Copy', 'ID Copy'], ['Latest Payslip', 'Payslip']];

    private bool $defaultsChecked = false;

    public function ensureDefaults(): void
    {
        if ($this->defaultsChecked) {
            return;
        }
        $this->defaultsChecked = true;
        foreach (self::DEFAULTS as [$name, $type]) {
            if ((int) $this->scalar("SELECT COUNT(*) FROM application_upload_requirements WHERE document_type = ?", [$type]) === 0) {
                $this->query("INSERT INTO application_upload_requirements (requirement_name, document_type, is_required, applies_to, is_active) VALUES (?, ?, 1, 'All', 1)", [$name, $type]);
            }
        }
    }

    public function forBorrowers(): array
    {
        $this->ensureDefaults();

        return $this->all(
            "SELECT * FROM application_upload_requirements WHERE is_active = 1 AND applies_to IN ('All','Borrower') ORDER BY is_required DESC, requirement_name"
        );
    }
}
