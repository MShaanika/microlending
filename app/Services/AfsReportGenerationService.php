<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AfsReportLine;
use App\Models\BankAccount;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\RegulatoryReport;

/**
 * Builds the "Annual Financial Statement Analysis" report -- mirrors the
 * client's own manually-compiled workbook of the same name: a quarterly
 * income/expense summary for the company's financial year, a bank accounts
 * listing, and a fixed asset register.
 *
 * Unlike the MLR Summarised Management Report (which follows NAMFISA's
 * fixed calendar-quarter filing calendar -- Jan-Mar, Apr-Jun, etc.), the
 * client's own financial year runs April-March, confirmed by reconciling
 * their sample workbook's per-quarter totals against its own monthly rows.
 * $fyStartYear is the calendar year the FY begins in (2025 = FY Apr 2025 -
 * Mar 2026).
 *
 * Persists into afs_report_lines, a single flexible-shape table shared by
 * all 3 sections -- see the column-meaning mapping in each section builder
 * below.
 */
class AfsReportGenerationService
{
    public static function generate(int $fyStartYear, int $userId): int
    {
        $periodStart = "{$fyStartYear}-04-01";
        $periodEnd = ($fyStartYear + 1) . '-03-31';

        $quarterly = self::quarterlySummary($fyStartYear);
        $bankAccounts = self::bankAccountsSection();
        $fixedAssets = self::fixedAssetsSection();

        $lines = array_merge($quarterly, $bankAccounts, $fixedAssets);

        $totalRow = end($quarterly);

        $db = Database::connection();
        $reports = new RegulatoryReport();
        $reportLines = new AfsReportLine();

        $db->beginTransaction();
        try {
            $reportId = $reports->create([
                'report_type_id' => self::reportTypeId(),
                'report_no' => generate_reference('REG'),
                'report_period' => 'FY ' . $fyStartYear . '-' . ($fyStartYear + 1),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_principal' => round((float) $totalRow['amount_3'], 2),
                'total_interest' => round((float) $totalRow['amount_2'], 2),
                'total_bad_debts' => round((float) $totalRow['amount_5'], 2),
                'total_namfisa_levy' => round((float) $totalRow['amount_4'], 2),
                'status' => 'Generated',
                'generated_by' => $userId,
                'generated_at' => date('Y-m-d H:i:s'),
            ]);

            $reportLines->insertLines($reportId, $lines);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $reportId;
    }

    private static function reportTypeId(): int
    {
        $db = Database::connection();
        $id = $db->query("SELECT id FROM regulatory_report_types WHERE report_code = 'AFS_ANNUAL' LIMIT 1")->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('AFS_ANNUAL report type is not seeded.');
        }
        return (int) $id;
    }

    /**
     * @return array<int, array{quarter_key:int, start:string, end:string, label:string}>
     */
    private static function fyQuarters(int $fyStartYear): array
    {
        return [
            ['label' => 'Quarter 1', 'start' => "{$fyStartYear}-04-01", 'end' => "{$fyStartYear}-06-30"],
            ['label' => 'Quarter 2', 'start' => "{$fyStartYear}-07-01", 'end' => "{$fyStartYear}-09-30"],
            ['label' => 'Quarter 3', 'start' => "{$fyStartYear}-10-01", 'end' => "{$fyStartYear}-12-31"],
            ['label' => 'Quarter 4', 'start' => ($fyStartYear + 1) . '-01-01', 'end' => ($fyStartYear + 1) . '-03-31'],
        ];
    }

    /**
     * section=QUARTERLY_SUMMARY. label=quarter name (or 'Total'),
     * amount_1=Expenditure, amount_2=Interest Income, amount_3=Disbursed
     * Loans Capital, amount_4=NAMFISA Levies, amount_5=Total Bad Debt
     * Written Off. Returns 5 rows: the 4 FY quarters plus a Total row.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function quarterlySummary(int $fyStartYear): array
    {
        $db = Database::connection();
        $accountId = (new AccountingAccount())->idByCode('4010');
        $journal = new JournalEntry();

        $lines = [];
        $totals = ['amount_1' => 0.0, 'amount_2' => 0.0, 'amount_3' => 0.0, 'amount_4' => 0.0, 'amount_5' => 0.0];

        foreach (self::fyQuarters($fyStartYear) as $q) {
            $expenditureStmt = $db->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM expenses WHERE status = 'Paid' AND expense_date BETWEEN ? AND ?"
            );
            $expenditureStmt->execute([$q['start'], $q['end']]);
            $expenditure = (float) $expenditureStmt->fetchColumn();

            $interestIncome = (float) array_sum(array_column(
                $journal->accountCreditsByMonth($accountId, $q['start'], $q['end']),
                'total_amount'
            ));

            $disbursedStmt = $db->prepare(
                "SELECT COALESCE(SUM(l.principal_amount),0)
                 FROM loan_disbursements ld JOIN loans l ON l.id = ld.loan_id
                 WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?"
            );
            $disbursedStmt->execute([$q['start'], $q['end']]);
            $disbursedCapital = (float) $disbursedStmt->fetchColumn();

            $namfisaLevies = (float) RegulatoryReportService::namfisaLevySummary($q['start'], $q['end'])['total_amount'];

            $writeOffStmt = $db->prepare(
                "SELECT COALESCE(SUM(sched.principal_outstanding + sched.interest_outstanding),0)
                 FROM loan_write_offs lw
                 JOIN (
                     SELECT loan_id,
                            SUM(principal_due - principal_paid) AS principal_outstanding,
                            SUM(interest_due - interest_paid) AS interest_outstanding
                     FROM loan_schedules GROUP BY loan_id
                 ) sched ON sched.loan_id = lw.loan_id
                 WHERE lw.status = 'Posted' AND lw.write_off_date BETWEEN ? AND ?"
            );
            $writeOffStmt->execute([$q['start'], $q['end']]);
            $badDebtWrittenOff = (float) $writeOffStmt->fetchColumn();

            $row = [
                'section' => 'QUARTERLY_SUMMARY',
                'label' => $q['label'],
                'sub_label' => null,
                'amount_1' => round($expenditure, 2),
                'amount_2' => round($interestIncome, 2),
                'amount_3' => round($disbursedCapital, 2),
                'amount_4' => round($namfisaLevies, 2),
                'amount_5' => round($badDebtWrittenOff, 2),
            ];
            $lines[] = $row;

            $totals['amount_1'] += $row['amount_1'];
            $totals['amount_2'] += $row['amount_2'];
            $totals['amount_3'] += $row['amount_3'];
            $totals['amount_4'] += $row['amount_4'];
            $totals['amount_5'] += $row['amount_5'];
        }

        $lines[] = array_merge(['section' => 'QUARTERLY_SUMMARY', 'label' => 'Total', 'sub_label' => null], array_map(
            static fn ($v) => round($v, 2),
            $totals
        ));

        return $lines;
    }

    /**
     * section=BANK_ACCOUNTS. label='bank - account name', sub_label=account
     * number, amount_1=current balance (via GL). Overdraft limit and
     * accrued account interest aren't tracked anywhere in the system, so
     * amount_2/amount_3 are always 0 rather than fabricated.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function bankAccountsSection(): array
    {
        $journal = new JournalEntry();
        $lines = [];

        foreach ((new BankAccount())->allBankAccounts(true) as $b) {
            $lines[] = [
                'section' => 'BANK_ACCOUNTS',
                'label' => $b['bank_name'] . ' - ' . $b['account_name'],
                'sub_label' => $b['account_number'],
                'amount_1' => $journal->accountBalance((int) $b['account_id'], 'Debit'),
                'amount_2' => 0.0,
                'amount_3' => 0.0,
                'amount_4' => 0.0,
                'amount_5' => 0.0,
            ];
        }

        return $lines;
    }

    /**
     * section=FIXED_ASSETS. label=asset name, sub_label=asset no.,
     * amount_1=quantity (always 1 -- each row in fixed_assets is a single
     * asset), amount_2=unit price (capitalized cost), amount_3=total (same
     * as unit price since quantity is always 1).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fixedAssetsSection(): array
    {
        $lines = [];

        foreach ((new FixedAsset())->paginated('', '', 500) as $a) {
            if ($a['status'] === 'Disposed') {
                continue;
            }
            $cost = round((float) $a['capitalized_cost'], 2);
            $lines[] = [
                'section' => 'FIXED_ASSETS',
                'label' => $a['asset_name'],
                'sub_label' => $a['asset_no'],
                'amount_1' => 1,
                'amount_2' => $cost,
                'amount_3' => $cost,
                'amount_4' => 0.0,
                'amount_5' => 0.0,
            ];
        }

        return $lines;
    }
}
