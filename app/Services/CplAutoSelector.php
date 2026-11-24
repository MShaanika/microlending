<?php

namespace App\Services;

/**
 * Decides WHAT goes into the automatic Creditinfo files, from the answers staff gave on the Loan Questions page. Pure: no database, no clock, no network -
 * the caller passes the loan_followups data, what was already reported and "today", so every rule can be unit tested.
 *
 * An entry is the same shape LoanFollowupCpl uses:
 *   ['followup' => loan_followups row, 'application' => loan_applications row, 'instalments' => [...], 'payments' => [instalment id => [{amount, paid_on}]]]
 *
 * Rules (each one is covered by tests/Unit/CplAutoSelectorTest.php):
 *  - Only a loan that staff said was approved AND paid out, whose follow-up is still Open, is ever reported. A loan staff closed ("not a real loan") never is.
 *  - A loan of an excluded applicant (test applicants: the cpl_auto_excluded_names setting), and anything that looks like the Creditinfo UAT test data, never is.
 *  - DAILY registration (R): a tracked loan paid out on or before today and not yet reported as R. Catch-up is automatic: whatever was missed is simply
 *    still "not yet reported" the next time. An optional "since" date leaves out loans paid out before it (loans the owner registered by other means).
 *  - DAILY closure (C): a tracked loan whose every instalment is paid in full and which has not yet been reported as C. A closure does NOT wait for the
 *    registration: a loan registered earlier (or by another route, or left out by the "since" date) is closed on its own; a loan that was never registered and is
 *    already settled gets its R and its C in the same file, R first. Each of R and C is selected at most once per loan, ever.
 *  - The daily account number is the application number, exactly as in the monthly file (the bureau matches daily to monthly on it, so it never changes).
 *  - MONTHLY: the month-end book of every tracked loan, built by the same LoanFollowupCpl + LegacyCplExtract code the manual candidate file uses. A loan with an
 *    instalment that fell due by the month end and has no answer yet is NOT guessed: it is listed as an exception (so the file is held back).
 *  - Every record is run through CplValidationService; a BLOCKING error makes the record an exception, and the caller must not send a file that has exceptions.
 */
final class CplAutoSelector
{
    /** period_key of the kinds that are reported only once per loan, ever. */
    public const ONCE = 'ONCE';

    private LegacyCplMapper $mapper;
    private CplValidationService $validator;

    public function __construct(?LegacyCplMapper $mapper = null, ?CplValidationService $validator = null)
    {
        $this->mapper = $mapper ?? new LegacyCplMapper();
        $this->validator = $validator ?? new CplValidationService();
    }

    /** "  John   SMITH " -> "john smith" */
    public static function normaliseName(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /** The setting holds one full name per line (commas also work): "First Last". @return string[] normalised names */
    public static function parseExcludedNames(string $text): array
    {
        $names = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $n = self::normaliseName($line);
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return array_values(array_unique($names));
    }

    /** Key used in the "already reported" set: "<followup id>:R" or "<followup id>:C". */
    public static function reportedKey(int $followupId, string $kind): string
    {
        return $followupId . ':' . $kind;
    }

    /** Anything that is, or looks like, the Creditinfo UAT sign-off test data. */
    public static function looksLikeTestData(array $application, ?string $accountNo = null): bool
    {
        $no = (string) ($accountNo ?? ($application['application_no'] ?? ''));
        if (str_starts_with(strtoupper($no), CplUatFileService::ACCOUNT_PREFIX)) {
            return true;
        }
        $digits = preg_replace('/\D/', '', (string) ($application['applicant_id_number'] ?? ''));
        if ($digits !== '' && in_array($digits, CplUatTestDataService::UAT_TEST_IDS, true)) {
            return true;
        }
        return strtoupper(trim((string) ($application['applicant_last_name'] ?? ''))) === 'CREDITINFOUATTEST';
    }

    /**
     * The entries that may ever be reported: approved + paid out + still open, not an excluded applicant, not test data.
     *
     * @param array<int, array<string,mixed>> $entries
     * @param string[] $excludedNames normalised names (see parseExcludedNames)
     * @return array<int, array<string,mixed>>
     */
    public function tracked(array $entries, array $excludedNames = []): array
    {
        $excluded = array_map([self::class, 'normaliseName'], $excludedNames);
        $out = [];
        foreach ($entries as $e) {
            $f = $e['followup'];
            $app = $e['application'];
            if (($f['status'] ?? '') !== 'Open' || ($f['approved'] ?? null) !== 'Yes' || ($f['disbursed'] ?? null) !== 'Yes'
                || empty($f['disbursed_on']) || ($f['approved_amount'] ?? null) === null || ($f['pay_day'] ?? null) === null) {
                continue;
            }
            $full = self::normaliseName(($app['applicant_first_name'] ?? '') . ' ' . ($app['applicant_last_name'] ?? ''));
            if ($full !== '' && in_array($full, $excluded, true)) {
                continue;
            }
            if (self::looksLikeTestData($app)) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * When did this loan become fully paid? Null when it is not (no instalments, or any instalment not paid in full).
     * The date is the latest payment date reported for it.
     *
     * @param array<string,mixed> $entry
     */
    public function settledOn(array $entry): ?string
    {
        $instalments = $entry['instalments'] ?? [];
        if ($instalments === []) {
            return null;
        }
        $latest = null;
        foreach ($instalments as $i) {
            if ($i['paid_amount'] === null || (float) $i['paid_amount'] < (float) $i['expected_amount'] - 0.004) {
                return null;
            }
            foreach ($entry['payments'][(int) $i['id']] ?? [] as $p) {
                $latest = $latest === null || $p['paid_on'] > $latest ? (string) $p['paid_on'] : $latest;
            }
            if (!empty($i['paid_on'])) {
                $latest = $latest === null || $i['paid_on'] > $latest ? (string) $i['paid_on'] : $latest;
            }
        }
        return $latest ?? (string) $entry['followup']['disbursed_on'];
    }

    /**
     * The new registrations and closures to report in a daily file.
     *
     * @param array<int, array<string,mixed>> $entries
     * @param array<string,bool> $reported keys from reportedKey(), for the R and C rows already confirmed as delivered
     * @param string $today Y-m-d: nothing dated after it is reported
     * @param string|null $since Y-m-d: loans paid out before it are not registered (blank/null = no limit)
     * @param string[] $excludedNames
     * @return array{records: array<int, array<string,mixed>>, exceptions: array<int, array<string,mixed>>, warnings: int, registrations: int, closures: int}
     */
    public function daily(array $entries, array $reported, string $today, ?string $since = null, array $excludedNames = []): array
    {
        $registrations = [];
        $closures = [];
        $exceptions = [];
        $warnings = 0;
        $registrationHeldBack = [];

        foreach ($this->tracked($entries, $excludedNames) as $e) {
            $f = $e['followup'];
            $fid = (int) $f['id'];
            $paidOut = (string) $f['disbursed_on'];

            if (!isset($reported[self::reportedKey($fid, 'R')]) && $paidOut <= $today && ($since === null || $since === '' || $paidOut >= $since)) {
                $built = $this->buildRecord($e, 'R', $paidOut, [], $paidOut);
                if (isset($built['exception'])) {
                    $exceptions[] = $built['exception'];
                    $registrationHeldBack[$fid] = true;
                } else {
                    $warnings += $built['warning'] ? 1 : 0;
                    $registrations[] = $built['record'];
                }
            }

            if (!isset($reported[self::reportedKey($fid, 'C')])) {
                $settled = $this->settledOn($e);
                if ($settled !== null && $settled <= $today && isset($registrationHeldBack[$fid])) {
                    // the registration of this loan is held back: its closure must never reach the bureau before its registration does
                    $exceptions[] = [
                        'loan_id' => (int) ($e['application']['id'] ?? 0), 'borrower_id' => 0, 'account_no' => (string) ($e['application']['application_no'] ?? ''),
                        'reasons' => ['closure: held back until the registration of this loan can be reported'], 'notes' => [],
                        'name' => trim(($e['application']['applicant_first_name'] ?? '') . ' ' . ($e['application']['applicant_last_name'] ?? '')),
                        'ref_no' => (string) ($e['application']['application_no'] ?? ''), 'phone' => (string) ($e['application']['applicant_phone'] ?? ''),
                    ];
                } elseif ($settled !== null && $settled <= $today) {
                    $payments = [];
                    foreach ($e['instalments'] as $i) {
                        foreach ($e['payments'][(int) $i['id']] ?? [] as $p) {
                            $payments[] = ['amount' => (string) $p['amount'], 'date' => (string) $p['paid_on']];
                        }
                    }
                    $built = $this->buildRecord($e, 'C', $settled, $payments, $settled);
                    if (isset($built['exception'])) {
                        $exceptions[] = $built['exception'];
                    } else {
                        $warnings += $built['warning'] ? 1 : 0;
                        $closures[] = $built['record'];
                    }
                }
            }
        }

        $byDate = static fn (array $a, array $b): int => [$a['event_date'], $a['followup_id']] <=> [$b['event_date'], $b['followup_id']];
        usort($registrations, $byDate);
        usort($closures, $byDate);

        return [
            'records' => array_merge($registrations, $closures), // every R before every C, so a loan's R can never come after its own C
            'exceptions' => $exceptions,
            'warnings' => $warnings,
            'registrations' => count($registrations),
            'closures' => count($closures),
        ];
    }

    /**
     * The month-end book as at $monthEnd, plus the loans that cannot be reported (and why) and which loan_followups ids are in the file.
     *
     * @param array<int, array<string,mixed>> $entries
     * @param string[] $excludedNames
     * @return array{result: array<string,mixed>, followup_ids: int[], application_numbers: array<int,string>, pending: array<int, array<string,mixed>>}
     */
    public function monthly(array $entries, string $monthEnd, string $now, string $supplierRef, string $tradingName, array $excludedNames = []): array
    {
        $tracked = $this->tracked($entries, $excludedNames);
        // a "not paid" answer older than the re-ask window is a known fact (unpaid), not a missing answer: only a never-answered instalment holds the file
        $built = (new LoanFollowupCpl())->build($tracked, $monthEnd, $now, false);
        $result = (new LegacyCplExtract($built['book'], $this->mapper, $this->validator, new CplRecordBuilder()))->monthly($monthEnd, $supplierRef, $tradingName);

        foreach ($built['pending'] as $p) {
            $result['exceptions'][] = [
                'loan_id' => 0, 'borrower_id' => 0, 'account_no' => $p['application_no'],
                'reasons' => ['instalment ' . $p['instalment_no'] . ' (due ' . $p['due_date'] . ') has no answer yet on the Loan Questions page'],
                'notes' => [], 'name' => $p['name'], 'ref_no' => $p['application_no'], 'phone' => '',
            ];
            $result['summary']['blocked']++;
        }

        $byApplication = [];
        $numbers = [];
        foreach ($tracked as $e) {
            $byApplication[(int) $e['application']['id']] = (int) $e['followup']['id'];
            $numbers[(int) $e['followup']['id']] = (string) $e['application']['application_no'];
        }
        $ids = [];
        foreach ($result['details'] as $d) {
            if (isset($byApplication[(int) $d['loan_id']])) {
                $ids[] = $byApplication[(int) $d['loan_id']];
            }
        }
        return ['result' => $result, 'followup_ids' => $ids, 'application_numbers' => $numbers, 'pending' => $built['pending']];
    }

    /**
     * One daily record, validated. Returns ['record' => ..., 'warning' => bool] or ['exception' => ...].
     *
     * @param array<string,mixed> $e
     * @param array<int, array{amount:string, date:string}> $payments
     * @return array<string,mixed>
     */
    private function buildRecord(array $e, string $kind, string $eventDate, array $payments, string $asOf): array
    {
        $f = $e['followup'];
        $app = $e['application'];
        $loans = (new ApplicationLoanBook([[
            'application' => $app,
            'amount' => (float) $f['approved_amount'],
            'paid_out' => (string) $f['disbursed_on'],
            'pay_day' => (int) $f['pay_day'],
            'months' => (int) (($f['term_months'] ?? null) ?: ($app['requested_term_months'] ?? 1)),
            'payments' => $payments,
        ]]))->loans();
        $l = $loans[0];
        $mapped = $this->mapper->map($l['loan'], $l['payments'], $l['first_due'], $asOf);

        $name = trim(($app['applicant_first_name'] ?? '') . ' ' . ($app['applicant_last_name'] ?? ''));
        $exception = function (array $reasons) use ($app, $name, $kind, $mapped): array {
            return [
                'loan_id' => (int) ($app['id'] ?? 0), 'borrower_id' => 0, 'account_no' => (string) ($app['application_no'] ?? ''),
                'reasons' => array_map(static fn (string $r): string => ($kind === 'R' ? 'registration: ' : 'closure: ') . $r, $reasons),
                'notes' => $mapped['notes'] ?? [], 'name' => $name, 'ref_no' => (string) ($app['application_no'] ?? ''), 'phone' => (string) ($app['applicant_phone'] ?? ''),
            ];
        };

        if ($mapped['excluded'] !== null) {
            return ['exception' => $exception([$mapped['excluded']])];
        }
        $fields = $mapped['fields'];
        $fields['data'] = $kind;
        if ($kind === 'R') {
            // a registration reports the loan as it is when it starts: nothing paid yet, nothing overdue
            unset($fields['status_code'], $fields['status_date']);
        } elseif (($fields['status_code'] ?? '') !== 'C' || (int) $fields['current_balance'] !== 0) {
            return ['exception' => $exception(['staff reported every instalment as paid, but the payments reported do not cover the total owed, so the loan cannot be reported as closed'])];
        } else {
            // CPL v1.1: a closure carries an instalment of zero, nothing overdue and no months in arrears
            $fields['instalment_amount'] = 0;
            $fields['amount_overdue'] = 0;
            $fields['months_in_arrears'] = 0;
        }
        if (self::looksLikeTestData($app, (string) $fields['account_no'])) {
            return ['exception' => $exception(['looks like Creditinfo test data and must never be in a live file'])];
        }

        $issues = $this->validator->validate($fields);
        $blocking = array_values(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'BLOCKING'));
        if ($blocking !== []) {
            return ['exception' => $exception(array_map(static fn (array $i): string => $i['field'] . ': ' . $i['message'], $blocking))];
        }

        return [
            'warning' => array_filter($issues, static fn (array $i): bool => $i['severity'] === 'WARNING') !== [],
            'record' => [
                'kind' => $kind,
                'followup_id' => (int) $f['id'],
                'application_id' => (int) ($app['id'] ?? 0),
                'application_no' => (string) ($app['application_no'] ?? ''),
                'name' => $name,
                'event_date' => $eventDate,
                'fields' => $fields,
            ],
        ];
    }
}
