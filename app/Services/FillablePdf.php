<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

/** Fpdi that can say its current font size, so a long entry can shrink to its line and the size can then be put back. */
class FillablePdf extends Fpdi
{
    public function currentFontSize(): float
    {
        return (float) $this->FontSizePt;
    }
}
