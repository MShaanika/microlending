<?php

namespace App\Support;

/**
 * Numeric code lookup tables for Collexia's JSON REST API (EnDO/EnCr),
 * "CO JSON REST API Interface Specification V3.0" (EnDO, 15 Apr 2025) and
 * "EnCr JSON REST API Interface Specification V3.00" (22 Jul 2025).
 *
 * Deliberately separate from CollexiaCodes.php, which covers the older
 * "EnDo Batch v1.0" Excel file exchange -- the two specs use different
 * code sets (e.g. bank is a 2-letter string there, a numeric BanId here)
 * and must not be mixed.
 */
class CollexiaV3Codes
{
    /** magId: product identifier sent on every mandate. */
    public const MAG_ID_ENDO = 46;

    /** debtorBanId / toBanId -- Appendix C of both V3 specs. */
    public const BANK_IDS = [
        64 => 'Bank Windhoek',
        65 => 'FNB Namibia',
        66 => 'TrustCo Bank',
        67 => 'Bank Atlántico',
        68 => 'BankBIC',
        69 => 'Bank of Namibia',
        70 => 'Letshego Bank Namibia',
        71 => 'Nedbank Namibia',
        72 => 'Standard Bank Namibia',
    ];

    /** debtorIdentificationType (EnDO mandate). */
    public const ID_TYPES = [
        1 => 'Namibian ID',
        2 => 'Passport',
        3 => 'Temp ID',
        4 => 'Business',
    ];

    /** debtorAccountType / fromAccountType / toAccountType. */
    public const ACCOUNT_TYPES = [
        1 => 'Current',
        2 => 'Savings',
        3 => 'Transmission',
    ];

    /** frequencyCode (EnDO mandate). */
    public const FREQUENCY_CODES = [
        1 => 'Weekly',
        3 => 'Fortnightly',
        4 => 'Monthly',
    ];

    /** mandate.status, as returned by Mandate Enquiry. */
    public const MANDATE_STATUSES = [
        1 => 'Data Error',
        2 => 'Active',
        3 => 'Completed',
        4 => 'Cancelled',
        5 => 'Suspended',
        6 => 'CancelInProgress',
        7 => 'SuspendedInProcess',
        8 => 'Manually Processed',
    ];

    /** installment.status, as returned by Mandate Enquiry / Installment Request. */
    public const INSTALLMENT_STATUSES = [
        1 => 'Active',
        2 => 'Cancelled',
        3 => 'Insufficient Funds',
        4 => 'Send For Payment',
        5 => 'In Tracking',
        6 => 'Completed',
        7 => 'Recalled',
        8 => 'Rejected',
        9 => 'Suspended',
        10 => 'Disputed',
        11 => 'Expired',
        12 => 'Migrated',
        13 => 'Manually Processed',
        14 => 'Validated',
        15 => 'Not Validated',
        16 => 'Submitted',
        17 => 'Blocked',
        18 => 'Partially Completed',
    ];

    /**
     * Day-of-month collection day, as a plain integer per the V3 spec
     * (1-30, or 31/99 for "last day of the month") -- unlike the v1.0
     * Excel spec's zero-padded string code, V3's collectionDay is a JSON
     * integer.
     */
    public static function collectionDay(int $day): int
    {
        return ($day >= 1 && $day <= 30) ? $day : 31;
    }
}
