<?php

namespace App\Services;

/**
 * Anything that can hand LegacyCplExtract a list of loans in the shape LegacyCplMapper understands: the old loan system's database
 * (LegacyLoanBookReader) or the approved applications of the new system (ApplicationLoanBook).
 */
interface LegacyLoanSource
{
    /**
     * @return array<int, array{loan: array<string,mixed>, payments: array<int,array{amount:string,date:string}>, first_due: ?string}>
     */
    public function loans(): array;
}
