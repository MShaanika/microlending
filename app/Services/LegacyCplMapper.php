<?php

namespace App\Services;

/**
 * Turns one loan of the OLD loan system (SolidFin, the admin_area folder on the same server) into the field array that CplRecordBuilder and
 * CplValidationService already understand, so the bureau file for the loan book still being managed there is built by the same, already accepted code.
 *
 * Pure: no database, no clock. Everything it needs is passed in, so every rule can be tested.
 *
 * Rules taken from the old system and the analysis of its 8 November 2026 backup:
 *  - amounts: initial = amount + 5.00 fee + 1.03% levy; final (what the customer owes in total) = initial + the plan's interest; balance = final - repayments
 *    made on or before the as-of date. (Still to be confirmed with the owner: whether the fee and levy are financed, and Creditinfo's view of "current balance".)
 *  - the old system has no instalment schedule, only the first due month: arrears are WORKED OUT from that month, the borrower's payment day and the payments;
 *    they are an estimate and are labelled so in the notes.
 *  - a loan that is fully paid but still marked "Released" counts as closed, like one marked "Completed".
 *  - a closed loan belongs in this month's file only if its last payment was in the month; earlier closures are left out.
 *  - the account number is built from the loan's internal id (the typed loan reference is not unique): it must never change once sent.
 *  - a Namibian ID in this data has 11 digits and carries the date of birth in its first six.
 */
final class LegacyCplMapper
{
    public const FEE = 5.00;
    public const LEVY_RATE = 0.0103;

    private const STATUS_RELEASED = 2;
    private const STATUS_COMPLETED = 3;
    private const STATUS_WRITTEN_OFF = 5;

    public function __construct(private string $accountPrefix = 'SDL')
    {
    }

    public static function accountNumber(string $prefix, int $loanId): string
    {
        return $prefix . str_pad((string) $loanId, 7, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $loan loan_list row joined with its borrower and plan: id, amount, months, interest_percentage, status, date_released,
     *        date_written_off, firstname, middlename, lastname, id_number, identity, gender, contact_no, address, company_name, gross_salary, payment_day
     * @param array<int,array{amount:float|int|string,date:string}> $payments repayments of this loan
     * @param string|null $firstDue the loan's first due date as the old system stores it ("2026-12-00": only year and month are reliable)
     * @return array{fields: ?array<string,mixed>, excluded: ?string, notes: string[], facts?: array<string,float>}
     */
    public function map(array $loan, array $payments, ?string $firstDue, string $asOf): array
    {
        $notes = [];
        $status = (int) ($loan['status'] ?? -1);
        if (!in_array($status, [self::STATUS_RELEASED, self::STATUS_COMPLETED, self::STATUS_WRITTEN_OFF], true)) {
            return $this->excluded('status is not a reportable one');
        }
        $released = $this->date($loan['date_released'] ?? null);
        if ($released === null) {
            return $this->excluded('the loan has no release date');
        }
        if ($released > $asOf) {
            return $this->excluded('released after the as-of date');
        }
        $months = max(1, (int) ($loan['months'] ?? 1));

        $paid = 0.0;
        $lastPayment = null;
        foreach ($payments as $payment) {
            $date = $this->date($payment['date'] ?? null);
            if ($date === null || $date > $asOf) {
                continue;
            }
            $paid += (float) $payment['amount'];
            $lastPayment = $lastPayment === null || $date > $lastPayment ? $date : $lastPayment;
        }

        $amount = (float) ($loan['amount'] ?? 0);
        $initial = $amount + self::FEE + round($amount * self::LEVY_RATE, 2);
        $final = round($initial * (1 + (float) ($loan['interest_percentage'] ?? 0) / 100), 2);
        $balance = max(0.0, round($final - $paid, 2));
        $instalment = (int) ceil($final / $months);

        $writtenOffOn = $this->date($loan['date_written_off'] ?? null);
        $isWrittenOff = $status === self::STATUS_WRITTEN_OFF && ($writtenOffOn === null || $writtenOffOn <= $asOf);
        $isClosed = !$isWrittenOff && ($status === self::STATUS_COMPLETED || $balance <= 0.004);

        $monthStart = substr($asOf, 0, 8) . '01';
        if ($isClosed && ($lastPayment === null || $lastPayment < $monthStart)) {
            return $this->excluded('closed before this month');
        }

        [$overdue, $monthsInArrears] = $isWrittenOff || $isClosed ? [0, 0] : $this->arrears($final, $months, $paid, $instalment, $firstDue, (int) ($loan['payment_day'] ?? 25), $asOf, $notes);

        [$naId, $nonNaId, $dob, $idNote] = $this->identity((string) ($loan['id_number'] ?? ''), (string) ($loan['identity'] ?? ''));
        if ($idNote !== null) {
            $notes[] = $idNote;
        }

        [$line1, $line2, $line3] = $this->addressLines((string) ($loan['address'] ?? ''));

        $fields = [
            'na_id' => $naId,
            'non_na_id' => $nonNaId,
            'date_of_birth' => $dob,
            'gender' => ['Male' => 'M', 'Female' => 'F'][$loan['gender'] ?? ''] ?? '',
            'surname' => $this->clean($loan['lastname'] ?? ''),
            'forename1' => $this->clean($loan['firstname'] ?? ''),
            'forename2' => $this->clean($loan['middlename'] ?? ''),
            'residential_line1' => $line1,
            'residential_line2' => $line2,
            'residential_line3' => $line3,
            'cellular_telephone' => preg_replace('/\D/', '', (string) ($loan['contact_no'] ?? '')),
            'employer_detail' => $this->clean($loan['company_name'] ?? ''),
            'income' => (int) round((float) ($loan['gross_salary'] ?? 0)),
            'income_frequency' => 'M',
            'account_no' => self::accountNumber($this->accountPrefix, (int) $loan['id']),
            'type_of_account' => $months === 1 ? 'M' : 'P',
            'terms' => $months === 1 ? 0 : $months,
            'payment_type' => '00',
            'repayment_frequency' => 3,
            'loan_reason_code' => 'O',
            'date_account_opened' => str_replace('-', '', $released),
            'date_of_last_payment' => $lastPayment !== null ? str_replace('-', '', $lastPayment) : null,
            'opening_balance' => (int) round($final),
            'current_balance' => $isClosed ? 0 : (int) round($balance),
            'current_balance_indicator' => 'D',
            'amount_overdue' => $overdue,
            'instalment_amount' => $instalment,
            'months_in_arrears' => $monthsInArrears,
        ];

        if ($isWrittenOff) {
            $fields['status_code'] = 'W';
            if ($writtenOffOn === null) {
                $notes[] = 'written off but the write-off date is missing: the as-of date was used';
            }
            $fields['status_date'] = str_replace('-', '', $writtenOffOn ?? $asOf);
        } elseif ($isClosed) {
            $fields['status_code'] = 'C';
            $fields['status_date'] = str_replace('-', '', (string) $lastPayment);
        }

        return ['fields' => $fields, 'excluded' => null, 'notes' => $notes, 'facts' => ['paid' => round($paid, 2), 'total_owed_at_start' => $final]];
    }

    /** @return array{fields: null, excluded: string, notes: string[]} */
    private function excluded(string $reason): array
    {
        return ['fields' => null, 'excluded' => $reason, 'notes' => []];
    }

    /** "2026-10-31", "2026-10-31 12:00:00" -> "2026-10-31"; zero dates and rubbish -> null. */
    private function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private function clean(mixed $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $text));
    }

    /**
     * Instalments that should have been paid by the as-of date (first due month, the borrower's payment day) against what was paid.
     *
     * @param string[] $notes
     * @return array{0:int, 1:int} amount overdue, months in arrears
     */
    private function arrears(float $final, int $months, float $paid, int $instalment, ?string $firstDue, int $payDay, string $asOf, array &$notes): array
    {
        if ($firstDue === null || !preg_match('/^(\d{4})-(\d{2})/', $firstDue, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            $notes[] = 'no usable first due date: arrears could not be worked out and are reported as nil';
            return [0, 0];
        }
        $payDay = in_array($payDay, [20, 25, 30], true) ? min($payDay, 28) : 25;
        $dueCount = 0;
        for ($i = 0; $i < $months; $i++) {
            $due = date('Y-m-d', (int) strtotime(sprintf('%04d-%02d-%02d +%d month', $m[1], $m[2], $payDay, $i)));
            if ($due <= $asOf) {
                $dueCount++;
            }
        }
        $expected = min($final, $dueCount * ($final / $months));
        $overdue = (int) max(0, round($expected - $paid));
        if ($overdue === 0) {
            return [0, 0];
        }
        $notes[] = 'arrears are an estimate (the old system keeps no instalment schedule)';
        return [$overdue, (int) min($months, max(1, ceil($overdue / max(1, $instalment))))];
    }

    /**
     * @return array{0:string, 1:string, 2:?string, 3:?string} NA ID, non-NA ID, date of birth (YYYYMMDD), a note when the identity is a problem
     */
    private function identity(string $idNumber, string $identity): array
    {
        $raw = preg_replace('/\D/', '', $idNumber !== '' ? $idNumber : $identity);
        if ($raw === '' || $raw === null) {
            return ['', '', null, 'no ID number'];
        }
        if (preg_match('/^(\d{2})(\d{2})(\d{2})\d{5}$/', $raw, $m)) {
            $yy = (int) $m[1];
            $year = $yy <= (int) date('y') ? 2000 + $yy : 1900 + $yy;
            if (checkdate((int) $m[2], (int) $m[3], $year)) {
                return [$raw, '', sprintf('%04d%02d%02d', $year, $m[2], $m[3]), null];
            }
            // An ID whose own digits give no real date of birth cannot be reported as a Namibian ID: it is held back as a problem for a person to fix.
            return ['', $raw, null, 'the ID number does not give a real date of birth'];
        }
        return ['', $raw, null, 'the ID number is not a Namibian 11-digit ID and no date of birth is known'];
    }

    /** @return array{0:string,1:string,2:string} up to three 25-character lines, broken at word boundaries */
    private function addressLines(string $address): array
    {
        $words = explode(' ', $this->clean($address));
        $lines = ['', '', ''];
        $i = 0;
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if ($i < 2 && $lines[$i] !== '' && strlen($lines[$i] . ' ' . $word) > 25) {
                $i++;
            }
            $lines[$i] = $lines[$i] === '' ? $word : $lines[$i] . ' ' . $word;
        }
        return [substr($lines[0], 0, 25), substr($lines[1], 0, 25), substr($lines[2], 0, 25)];
    }
}
