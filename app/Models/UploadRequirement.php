<?php

namespace App\Models;

use App\Core\Model;

class UploadRequirement extends Model
{
    public function forBorrowers(): array
    {
        return $this->all(
            "SELECT * FROM application_upload_requirements WHERE is_active = 1 AND applies_to IN ('All','Borrower') ORDER BY is_required DESC, requirement_name"
        );
    }
}
