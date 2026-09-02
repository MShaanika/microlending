<?php

namespace App\Services;

/**
 * Pure fixed-width formatting engine for CPLv1.1 (Credit Providers Layout)
 * records -- no DB access, deliberately kept separate from CplExporter (the
 * data-mapping layer) so the byte-position logic can be checked against the
 * spec (CPLv1-1.pdf, Monthly Layout table pp.17-18) in isolation, and unit
 * tested without a database.
 *
 * Every record -- header, data line, trailer -- is exactly 700 characters,
 * ASCII, no line terminator (the caller joins lines with CRLF per the
 * spec's "end of line marker followed by a carriage return line feed").
 */
class CplRecordBuilder
{
    public const RECORD_LENGTH = 700;

    /**
     * name => [start position (1-based), length, alignment].
     *
     * Alignment: AL = alpha/left-aligned/space-filled (the default for text
     * fields); AR = alpha-shaped but right-aligned/space-filled -- the
     * spec's explicit exceptions for branch/account/sub-account codes,
     * old-conversion fields, telephone numbers and postal codes, all of
     * which say "do not zero fill or use preceding zeros"; NL = numeric but
     * left-aligned/space-filled (NA ID number only -- the spec's own stated
     * exception); NR = numeric/right-aligned/zero-filled, the default for
     * every other numeric field (dates, balances, counts).
     */
    private const FIELDS = [
        'data' => [1, 1, 'AL'],
        'non_na_id' => [2, 13, 'NR'],
        'na_id' => [15, 16, 'NL'],
        'gender' => [31, 1, 'AL'],
        'date_of_birth' => [32, 8, 'NR'],
        'branch_code' => [40, 8, 'AR'],
        'account_no' => [48, 25, 'AR'],
        'sub_account_no' => [73, 4, 'AR'],
        'surname' => [77, 25, 'AL'],
        'title' => [102, 5, 'AL'],
        'forename1' => [107, 14, 'AL'],
        'forename2' => [121, 14, 'AL'],
        'forename3' => [135, 14, 'AL'],
        'residential_line1' => [149, 25, 'AL'],
        'residential_line2' => [174, 25, 'AL'],
        'residential_line3' => [199, 25, 'AL'],
        'residential_line4' => [224, 25, 'AL'],
        'residential_postal_code' => [249, 6, 'AR'],
        'owner_tenant' => [255, 1, 'AL'],
        'postal_line1' => [256, 25, 'AL'],
        'postal_line2' => [281, 25, 'AL'],
        'postal_line3' => [306, 25, 'AL'],
        'postal_line4' => [331, 25, 'AL'],
        'postal_postal_code' => [356, 6, 'AR'],
        'ownership_type' => [362, 2, 'AL'],
        'loan_reason_code' => [364, 2, 'AL'],
        'payment_type' => [366, 2, 'AL'],
        'type_of_account' => [368, 2, 'AL'],
        'date_account_opened' => [370, 8, 'NR'],
        'deferred_payment_date' => [378, 8, 'NR'],
        'date_of_last_payment' => [386, 8, 'NR'],
        'opening_balance' => [394, 9, 'NR'],
        'current_balance' => [403, 9, 'NR'],
        'current_balance_indicator' => [412, 1, 'AL'],
        'amount_overdue' => [413, 9, 'NR'],
        'instalment_amount' => [422, 9, 'NR'],
        'months_in_arrears' => [431, 2, 'NR'],
        'status_code' => [433, 2, 'AL'],
        'repayment_frequency' => [435, 2, 'NR'],
        'terms' => [437, 4, 'NR'],
        'status_date' => [441, 8, 'NR'],
        'old_supplier_branch_code' => [449, 8, 'AR'],
        'old_account_number' => [457, 25, 'AR'],
        'old_sub_account_number' => [482, 4, 'AR'],
        'old_supplier_reference_number' => [486, 10, 'AR'],
        'home_telephone' => [496, 16, 'AR'],
        'cellular_telephone' => [512, 16, 'AR'],
        'work_telephone' => [528, 16, 'AR'],
        'employer_detail' => [544, 60, 'AL'],
        'income' => [604, 9, 'NR'],
        'income_frequency' => [613, 1, 'AL'],
        'occupation' => [614, 20, 'AL'],
        'third_party_name' => [634, 60, 'AL'],
        'account_sold_to_third_party' => [694, 2, 'NR'],
        'no_of_participants' => [696, 3, 'NR'],
        'filler' => [699, 2, 'AL'],
    ];

    /**
     * Builds one 700-char data line from a [field_name => value] array
     * (see FIELDS above for the full field list -- matches CplExporter's
     * output keys exactly). Any field not supplied is left blank/zero per
     * its alignment rule. Values are truncated if too long for their slot
     * -- never allowed to shift later fields out of position.
     */
    public function record(array $values): string
    {
        $line = str_repeat(' ', self::RECORD_LENGTH);
        foreach (self::FIELDS as $name => [$start, $length, $align]) {
            $raw = (string) ($values[$name] ?? '');
            // Every date-type field's name contains "date" -- auto-convert
            // here so a caller can pass a plain 'Y-m-d' string (or null)
            // for any of them without remembering to call date() itself
            // first; a value already in CCYYMMDD/empty form passes through
            // date() unchanged in the former case, or becomes '00000000'
            // in the latter, which is exactly what's wanted either way.
            if (str_contains($name, 'date') && $raw !== '') {
                $raw = $this->date($raw);
            }
            $formatted = $this->pad($raw, $length, $align);
            $line = substr_replace($line, $formatted, $start - 1, $length);
        }
        return $line;
    }

    private function pad(string $value, int $length, string $align): string
    {
        $value = substr($value, 0, $length);
        $fillChar = $align === 'NR' ? '0' : ' ';
        $padLeft = in_array($align, ['AR', 'NR'], true);
        return str_pad($value, $length, $fillChar, $padLeft ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }

    /** CCYYMMDD, or all-zero -- the spec's "zero filled when not in use" rule. */
    public function date(?string $date): string
    {
        if (!$date) {
            return '00000000';
        }
        $timestamp = strtotime($date);
        return $timestamp === false ? '00000000' : date('Ymd', $timestamp);
    }

    /**
     * The "H" header line: position 1 "H", 2-11 supplier reference (right-
     * aligned per spec), 12-19 month end date, 20-21 version "06", 22-29
     * file creation date, 30-89 trading name, 90-700 spaces.
     */
    public function header(string $supplierRef, string $monthEndDate, string $tradingName): string
    {
        $line = 'H'
            . str_pad(substr($supplierRef, 0, 10), 10, ' ', STR_PAD_LEFT)
            . $this->date($monthEndDate)
            . '06'
            . $this->date(date('Y-m-d'))
            . str_pad(substr($tradingName, 0, 60), 60, ' ', STR_PAD_RIGHT);
        return str_pad($line, self::RECORD_LENGTH, ' ', STR_PAD_RIGHT);
    }

    /** The "T" trailer line -- count includes the header and trailer themselves. */
    public function trailer(int $recordCount): string
    {
        $line = 'T' . str_pad((string) $recordCount, 9, '0', STR_PAD_LEFT);
        return str_pad($line, self::RECORD_LENGTH, ' ', STR_PAD_RIGHT);
    }
}
