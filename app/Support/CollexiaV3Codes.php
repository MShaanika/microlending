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

    /**
     * debtorBanId / toBanId -- per Collexia's own "ValidValues" sheet
     * (confirmed 2026-09-05), NOT the earlier PDF spec's Appendix C, which
     * listed a 69 = Bank of Namibia this sheet does not have and omitted
     * 81 = Nampost Ltd, which it does.
     */
    public const BANK_IDS = [
        64 => 'Bank Windhoek',
        65 => 'FNB Namibia',
        66 => 'TrustCo Bank',
        67 => 'Bank Atlántico',
        68 => 'BankBIC',
        70 => 'Letshego Bank Namibia',
        71 => 'Nedbank Namibia',
        72 => 'Standard Bank Namibia',
        81 => 'Nampost Ltd',
    ];

    /**
     * debtorIdentificationType (EnDO mandate) -- per Collexia's ValidValues
     * sheet (confirmed 2026-09-05). Note this differs from the earlier PDF
     * spec's own table (which had only 4 values: 1=Namibian ID, 2=Passport,
     * 3=Temp ID, 4=Business) -- that table is superseded by this one.
     *
     * OPEN QUESTION not yet resolved with Collexia: for this business's own
     * clients (all hold a Namibian national ID), is the correct code 1
     * ("IDNumber", a generic label) or 5 ("Namibia ID", the specific one)?
     * DebitOrderCollexiaController currently sends 1 -- every Load Mandate
     * call so far has been accepted with no field-level rejection on this
     * value, but that isn't the same as Collexia having confirmed it's the
     * intended code. Flag to Collexia before relying on this for a real
     * (non-UAT) mandate.
     */
    public const ID_TYPES = [
        1 => 'IDNumber',
        2 => 'Passport',
        3 => 'Temporary Resident ID',
        4 => 'Date of Birth',
        5 => 'Namibia ID',
    ];

    /**
     * debtorAccountType / fromAccountType / toAccountType -- per Collexia's
     * ValidValues sheet. Only 1 and 2 exist; the earlier PDF spec's
     * documented 3 = Transmission is not a real value. Not reachable from
     * this app's own debit order form anyway -- CollexiaCodes::ACCOUNT_TYPES
     * (the form's own dropdown) already only ever offers 1 or 2.
     */
    public const ACCOUNT_TYPES = [
        1 => 'Tjek (Cheque / Current)',
        2 => 'Savings',
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
     * responseCode on each Download Payments response entry -- Appendix A.
     * "0" means the collection succeeded; every other code is a rejection
     * reason. Keys are strings since the spec defines responseCode as a
     * string field (e.g. "0", "02"), not an integer.
     */
    public const RESPONSE_CODES = [
        '0' => 'Transaction Successful',
        '02' => 'Insufficient Funds (Rejected)',
        '03' => 'Debits not allowed to this Account',
        '06' => 'Account Frozen',
        '12' => 'Account Closed',
        '18' => 'Account holder deceased',
        '30' => 'No authority to debit',
        '40' => 'Item limit exceeded',
        '44' => 'Unable to Process',
        '48' => 'Account no fails CDV routine',
        '56' => 'Non Fica Compliant Account',
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

    /**
     * Maps CollexiaCodes::BANKS' 2-letter v1.0 codes to this spec's
     * numeric BanId, for debit orders originally captured against the
     * older code set. Returns null for a bank with no v1.0 equivalent
     * (Bank of Namibia, 69, is central-bank-only and was never a debtor
     * bank option in the old form).
     */
    public static function fromLegacyBankCode(string $legacyCode): ?int
    {
        return [
            'BW' => 64, 'FN' => 65, 'TB' => 66, 'AB' => 67,
            'BB' => 68, 'LB' => 70, 'NB' => 71, 'SB' => 72, 'NM' => 81,
        ][$legacyCode] ?? null;
    }
}
