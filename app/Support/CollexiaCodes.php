<?php

namespace App\Support;

/**
 * Static lookup tables matching Collexia's EnDo Batch v1.0 spec (ValidValues
 * sheet of their EnDOREQ template) -- keep in sync with that template if
 * Collexia ever revises it.
 */
class CollexiaCodes
{
    public const BANKS = [
        'BW' => 'Bank Windhoek',
        'FN' => 'FNB Namibia',
        'TB' => 'TrustCo Bank',
        'AB' => 'Bank Atlantico',
        'BB' => 'BankBIC',
        'LB' => 'Letshego Bank Namibia',
        'NB' => 'Nedbank Namibia',
        'SB' => 'Standard Bank Namibia',
    ];

    public const ID_TYPES = [
        1 => 'ID Number',
        2 => 'Passport',
        3 => 'Temporary Resident ID',
        4 => 'Date of Birth',
        5 => 'Namibia ID',
    ];

    // Transmission (3) is deliberately excluded -- Collexia's own template
    // advises against selecting it.
    public const ACCOUNT_TYPES = [
        1 => 'Cheque / Current',
        2 => 'Savings',
    ];

    public const PAYMENT_FREQUENCY_MONTHLY = 4;

    /**
     * Collexia's "MonthlyQtrBiAn" CollectionDay codes are the day-of-month
     * as a zero-padded 2-digit string (01-30), with 99 standing in for
     * "Last Day" -- used here for any payment_day beyond 30 (e.g. 31).
     */
    public static function collectionDayCode(int $day): string
    {
        if ($day >= 1 && $day <= 30) {
            return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        }
        return '99';
    }
}
