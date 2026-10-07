<?php

namespace App\Services;

/**
 * Renders the Creditinfo Public Defaults submission file -- pipe-delimited,
 * 36 columns, one line per borrower/loan default -- per the vendor-supplied
 * legend and sample (storage/documentation/Creditinfo_Public_Defaults_
 * Legend_2026-09-30.txt / ..._Sample_2026-09-30.txt) and Creditinfo's
 * 2026-09-30 written confirmation of every code table below. This class
 * only builds bytes; it never opens a network connection -- see
 * CreditinfoPublicDefaultBatchService for how a rendered batch is stored,
 * reviewed and eventually handed to a human to upload themselves.
 *
 * Field-by-field mapping decisions not explicitly given by the legend/
 * sample are documented inline at the point each one is made -- this class
 * is the single place that encodes them, so a future correction only needs
 * to change one file.
 */
class CreditinfoPublicDefaultFileService
{
    /** Country of Origin is fixed for every row -- Creditinfo's own words: "Country of Origin will always be = NAM". Borrowers' own borrowers.nationality field is deliberately never read here. */
    public const COUNTRY_OF_ORIGIN = 'NAM';

    public const ID_TYPE_PASSPORT = 1;
    public const ID_TYPE_NATIONAL_ID = 2;

    /** Vendor-confirmed 2026-09-30. Keys match creditinfo_public_defaults.default_status_category exactly. */
    public const DEFAULT_STATUS_CODES = [
        'HandedOver' => 1,
        'Collection' => 2,
        'WriteOff' => 3,
        'Paid' => 4,
        'Delinquent' => 5,
        'SlowPayer' => 6,
        'Absconded' => 7,
        'NotContactable' => 8,
    ];

    public const ACTION_LISTING = 'A';
    public const ACTION_REMOVAL = 'R';

    /**
     * One 36-field pipe-delimited line for one public default record.
     * $pd is a creditinfo_public_defaults row (as returned by
     * CreditinfoPublicDefault::find()/baseSelect()). $borrower/$loan/
     * $branch are the corresponding full rows; $employment is
     * Borrower::employmentFor()'s result, or null if none on file.
     * $action is self::ACTION_LISTING or self::ACTION_REMOVAL;
     * $transactionDate is the date this specific transaction (this
     * listing or this removal) is being submitted -- the batch's
     * generation date, not necessarily $pd['default_date'].
     */
    public function renderLine(
        array $pd,
        array $borrower,
        array $loan,
        ?array $branch,
        ?array $employment,
        string $supplierReferenceNumber,
        string $action,
        \DateTimeImmutable $transactionDate
    ): string {
        if (empty($pd['default_status_category']) || !isset(self::DEFAULT_STATUS_CODES[$pd['default_status_category']])) {
            throw new \RuntimeException('Public default ' . ($pd['listing_reference'] ?? $pd['id']) . ' has no Default Status Category set -- cannot render a submission line.');
        }

        $identification = $this->resolveIdentification($borrower);

        $fields = [
            $supplierReferenceNumber,
            $identification['number'],
            self::COUNTRY_OF_ORIGIN,
            (string) $identification['type'],
            $this->formatDate($borrower['date_of_birth'] ?? null),
            '', // GUID -- no vendor spec given; the vendor's own sample leaves this blank too.
            $borrower['first_name'] ?? '',
            $borrower['middle_name'] ?? '', // SECOND NAME -- borrowers has no separate "second name" field; middle_name is the natural fit between FIRST NAME and SURNAME.
            $borrower['last_name'] ?? '',
            $branch['branch_code'] ?? '',
            $loan['loan_no'] ?? '', // ACCOUNT REFERENCE NUMBER -- the specific loan in default, not the borrower_no (a borrower could in principle have more than one loan).
            $this->formatAmount((float) $pd['outstanding_amount']),
            $this->formatDate($pd['default_date']),
            (string) self::DEFAULT_STATUS_CODES[$pd['default_status_category']],
            $employment['employer_name'] ?? '',
            $employment['job_title'] ?? '', // OCCUPATION -- borrower_employment has no separate "occupation" column; job_title is the closest existing field.
            $this->digitsOnly($borrower['phone'] ?? null), // CELLPHONE NUMBER -- borrowers has one phone field, used as the mobile number.
            '', // HOME TELEPHONE NUMBER -- not collected anywhere in DesertLedger's borrower schema.
            $this->digitsOnly($employment['employer_phone'] ?? null), // WORK TELEPHONE NUMBER
            $this->addressLine($borrower['postal_address'] ?? null), // POSTAL ADDRESS LINE 1
            '', '', '', // POSTAL ADDRESS LINE 2-4 -- borrowers.postal_address is one freeform field, not split into lines.
            '', // POSTAL CODE OF POSTAL ADDRESS -- no dedicated postal-code column exists.
            $this->addressLine($borrower['physical_address'] ?? null), // RESIDENTIAL ADDRESS LINE 1
            '', '', '', // RESIDENTIAL ADDRESS LINE 2-4
            '', // POSTAL CODE OF RESIDENTIAL ADDRESS
            $this->addressLine($employment['employer_address'] ?? null), // WORK ADDRESS LINE 1
            '', '', '', // WORK ADDRESS LINE 2-4
            '', // POSTAL CODE OF WORK ADDRESS
            $transactionDate->format('Ymd'),
            $action,
        ];

        if (count($fields) !== 36) {
            // Defensive only -- a mismatch here means this method's own
            // field list was miscounted, never a runtime/data condition.
            throw new \LogicException('Public Defaults line must have exactly 36 fields, built ' . count($fields) . '.');
        }

        return implode('|', array_map([$this, 'sanitizeField'], $fields));
    }

    /** {SupplierReferenceNumber}_{T|L}_CCYYMMDD_HHMMSS.txt -- the vendor's own literal naming template, confirmed 2026-09-30. */
    public function buildFilename(string $supplierReferenceNumber, string $environment, \DateTimeImmutable $when): string
    {
        $typeCode = $environment === 'live' ? 'L' : 'T';
        return $supplierReferenceNumber . '_' . $typeCode . '_' . $when->format('Ymd_His') . '.txt';
    }

    /**
     * borrowers has separate id_number and passport_no columns but no
     * explicit flag for which one is the borrower's primary identification.
     * id_number is this app's globally-unique, everywhere-else-used
     * identifier, so its presence takes priority (-> NationalID); a
     * borrower with only a passport_no (a foreign national with no
     * Namibian ID) falls back to Passport. A borrower with neither is a
     * KYC gap this file can never paper over -- refuse to render rather
     * than submit a blank identification number to a credit bureau.
     */
    private function resolveIdentification(array $borrower): array
    {
        $idNumber = trim((string) ($borrower['id_number'] ?? ''));
        if ($idNumber !== '') {
            return ['type' => self::ID_TYPE_NATIONAL_ID, 'number' => $idNumber];
        }
        $passport = trim((string) ($borrower['passport_no'] ?? ''));
        if ($passport !== '') {
            return ['type' => self::ID_TYPE_PASSPORT, 'number' => $passport];
        }
        throw new \RuntimeException('Borrower ' . ($borrower['borrower_no'] ?? $borrower['id'] ?? '?') . ' has neither an ID number nor a passport number on file -- cannot be submitted to Creditinfo.');
    }

    private function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('Ymd', $ts) : '';
    }

    /** Sample data shows whole numbers, no thousand separators, no decimal point -- N$ amounts rounded to the nearest dollar. */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }

    private function digitsOnly(?string $value): string
    {
        return $value ? preg_replace('/\D+/', '', $value) : '';
    }

    /**
     * borrowers.postal_address / .physical_address / borrower_employment.
     * employer_address are single freeform TEXT fields, not pre-split into
     * address lines -- collapsed to one line (whitespace/newlines squashed)
     * rather than guessing where line breaks should fall.
     */
    private function addressLine(?string $address): string
    {
        if (!$address) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', $address));
    }

    /** The format is pipe-delimited -- a stray "|" or newline in a name/address would silently corrupt column alignment for every field after it. */
    private function sanitizeField(string $value): string
    {
        return trim(str_replace(["\r", "\n", '|'], ' ', $value));
    }
}
