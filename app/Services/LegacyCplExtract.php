<?php

namespace App\Services;

/**
 * Builds the monthly CPL file for the loan book that is still managed in the OLD loan system (SolidFin): reader -> mapper -> the same validator and record
 * builder as every other file. Nothing is written or sent here: the caller gets the candidate file, the accounts that cannot be sent (and why) and totals
 * to check against the old system's own screens.
 */
final class LegacyCplExtract
{
    public function __construct(
        private LegacyLoanBookReader $reader,
        private LegacyCplMapper $mapper,
        private CplValidationService $validator,
        private CplRecordBuilder $builder
    ) {
    }

    /**
     * @return array{
     *   file: string,
     *   records: int,
     *   exceptions: array<int, array{loan_id:int, borrower_id:int, account_no:string, reasons:string[], notes:string[]}>,
     *   excluded: array<string,int>,
     *   summary: array<string,mixed>
     * }
     */
    public function monthly(string $asOf, string $supplierRef, string $tradingName): array
    {
        $lines = [];
        $exceptions = [];
        $excluded = [];
        $warningRecords = 0;
        $byStatus = ['active' => 0, 'C' => 0, 'W' => 0];
        $totals = ['opening_balance' => 0, 'current_balance' => 0, 'amount_overdue' => 0, 'accounts_in_arrears' => 0];

        foreach ($this->reader->loans() as $entry) {
            $loan = $entry['loan'];
            $mapped = $this->mapper->map($loan, $entry['payments'], $entry['first_due'], $asOf);
            if ($mapped['excluded'] !== null) {
                $excluded[$mapped['excluded']] = ($excluded[$mapped['excluded']] ?? 0) + 1;
                continue;
            }
            $fields = $mapped['fields'];
            $fields['data'] = 'D';

            $issues = $this->validator->validate($fields);
            $blocking = array_values(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'BLOCKING'));
            if ($blocking !== []) {
                $exceptions[] = [
                    'loan_id' => (int) $loan['id'],
                    'borrower_id' => (int) ($loan['borrower_id'] ?? 0),
                    'account_no' => (string) $fields['account_no'],
                    'reasons' => array_map(static fn (array $i): string => $i['field'] . ': ' . $i['message'], $blocking),
                    'notes' => $mapped['notes'],
                ];
                continue;
            }
            if (array_filter($issues, static fn (array $i): bool => $i['severity'] === 'WARNING') !== []) {
                $warningRecords++;
            }

            $lines[] = $this->builder->record($fields);
            $byStatus[$fields['status_code'] ?? 'active']++;
            $totals['opening_balance'] += (int) $fields['opening_balance'];
            $totals['current_balance'] += (int) $fields['current_balance'];
            $totals['amount_overdue'] += (int) $fields['amount_overdue'];
            $totals['accounts_in_arrears'] += (int) $fields['months_in_arrears'] > 0 ? 1 : 0;
        }

        $file = implode("\r\n", array_merge(
            [$this->builder->header($supplierRef, $asOf, $tradingName)],
            $lines,
            [$this->builder->trailer(count($lines) + 2)]
        ));

        return [
            'file' => $file,
            'records' => count($lines),
            'exceptions' => $exceptions,
            'excluded' => $excluded,
            'summary' => ['as_of' => $asOf, 'sent' => count($lines), 'with_warnings' => $warningRecords, 'blocked' => count($exceptions), 'by_status' => $byStatus] + $totals,
        ];
    }
}
