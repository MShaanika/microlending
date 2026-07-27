<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AfsReportLine;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\RegulatoryReport;
use App\Models\StatutoryCharge;

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
        $monthlyDetail = self::monthlyDetail($fyStartYear);

        $lines = array_merge($quarterly, $bankAccounts, $fixedAssets, $monthlyDetail);

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
     * The 12 FY months, April through March, in order.
     *
     * @return array<int, array{month_key:string, start:string, end:string, label:string}>
     */
    private static function fyMonths(int $fyStartYear): array
    {
        $months = [];
        $cursor = new \DateTime("{$fyStartYear}-04-01");
        for ($i = 0; $i < 12; $i++) {
            $months[] = [
                'month_key' => $cursor->format('Y-m'),
                'start' => $cursor->format('Y-m-01'),
                'end' => $cursor->format('Y-m-t'),
                'label' => $cursor->format('F Y'),
            ];
            $cursor->modify('+1 month');
        }
        return $months;
    }

    /**
     * section=QUARTERLY_SUMMARY. label=quarter name (or 'Total'),
     * amount_1=Expenditure, amount_2=Interest Income, amount_3=Disbursed
     * Loans Capital, amount_4=NAMFISA Levies, amount_5=Total Bad Debt
     * Written Off, amount_6=Members Contribution. Returns 5 rows: the 4 FY
     * quarters plus a Total row.
     *
     * Interest Income and NAMFISA Levies are both flat percentages of
     * Disbursed Capital, per the client's written spec ("Annual Financial
     * Statement Analysis" formulas): Interest = Capital x 30%, Levy =
     * Capital x the NAMFISA rate in effect for that quarter -- unlike the
     * MLR report's levy figure, this sheet does NOT deduct bad debts from
     * capital first (confirmed explicitly in the spec).
     *
     * Expenditure only sums expenses whose category is tagged afs_group =
     * 'Core' -- the client's own workbook excludes "Other Expenses"
     * (capital deposits, tax, insurance, car payments, livestock, VAT --
     * afs_group 'Other') from this total, and Members Contribution
     * (Fringe Benefits, afs_group 'Excluded') is its own column entirely,
     * not an expense.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function quarterlySummary(int $fyStartYear): array
    {
        $db = Database::connection();
        $statutoryCharges = new StatutoryCharge();

        $lines = [];
        $totals = ['amount_1' => 0.0, 'amount_2' => 0.0, 'amount_3' => 0.0, 'amount_4' => 0.0, 'amount_5' => 0.0, 'amount_6' => 0.0];

        $expenseByGroupStmt = $db->prepare(
            "SELECT COALESCE(SUM(e.total_amount),0)
             FROM expenses e JOIN expense_categories c ON c.id = e.category_id
             WHERE e.status = 'Paid' AND c.afs_group = ? AND e.expense_date BETWEEN ? AND ?"
        );

        foreach (self::fyQuarters($fyStartYear) as $q) {
            $expenseByGroupStmt->execute(['Core', $q['start'], $q['end']]);
            $expenditure = (float) $expenseByGroupStmt->fetchColumn();

            $expenseByGroupStmt->execute(['Excluded', $q['start'], $q['end']]);
            $membersContribution = (float) $expenseByGroupStmt->fetchColumn();

            $disbursedStmt = $db->prepare(
                "SELECT COALESCE(SUM(l.principal_amount),0)
                 FROM loan_disbursements ld JOIN loans l ON l.id = ld.loan_id
                 WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?"
            );
            $disbursedStmt->execute([$q['start'], $q['end']]);
            $disbursedCapital = (float) $disbursedStmt->fetchColumn();

            $interestIncome = round($disbursedCapital * 0.30, 2);

            $levyRate = $statutoryCharges->namfisaLevyRateAsOf($q['end']);
            $namfisaLevies = round($disbursedCapital * ($levyRate / 100), 2);

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
                'amount_6' => round($membersContribution, 2),
            ];
            $lines[] = $row;

            $totals['amount_1'] += $row['amount_1'];
            $totals['amount_2'] += $row['amount_2'];
            $totals['amount_3'] += $row['amount_3'];
            $totals['amount_4'] += $row['amount_4'];
            $totals['amount_5'] += $row['amount_5'];
            $totals['amount_6'] += $row['amount_6'];
        }

        $lines[] = array_merge(['section' => 'QUARTERLY_SUMMARY', 'label' => 'Total', 'sub_label' => null], array_map(
            static fn ($v) => round($v, 2),
            $totals
        ));

        return $lines;
    }

    /**
     * section=MONTHLY_DETAIL. label=metric/category name, sub_label=month
     * label (e.g. "April 2025"), amount_1=value. One row per (month,
     * metric) pair -- the client's own workbook's monthly grid (its
     * "Total Disbursed Amount" / "Interest Income" columns, then one
     * column per expense category), reconstructed from the same category
     * mapping the quarterly summary's Expenditure/Members Contribution
     * split uses. Bad debts (client's own "BAD DEBTS" column) come from
     * the write-off workflow, not an expense category, so it's included
     * here as its own metric row alongside the two others.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function monthlyDetail(int $fyStartYear): array
    {
        $db = Database::connection();
        $categories = (new ExpenseCategory())->afsCategories();

        $disbursedStmt = $db->prepare(
            "SELECT COALESCE(SUM(l.principal_amount),0)
             FROM loan_disbursements ld JOIN loans l ON l.id = ld.loan_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?"
        );
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
        $categoryStmt = $db->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM expenses
             WHERE status = 'Paid' AND category_id = ? AND expense_date BETWEEN ? AND ?"
        );

        $lines = [];
        foreach (self::fyMonths($fyStartYear) as $m) {
            $disbursedStmt->execute([$m['start'], $m['end']]);
            $disbursedCapital = (float) $disbursedStmt->fetchColumn();

            $lines[] = [
                'section' => 'MONTHLY_DETAIL',
                'label' => 'Total Disbursed Amount',
                'sub_label' => $m['label'],
                'amount_1' => round($disbursedCapital, 2),
            ];
            $lines[] = [
                'section' => 'MONTHLY_DETAIL',
                'label' => 'Interest Income',
                'sub_label' => $m['label'],
                'amount_1' => round($disbursedCapital * 0.30, 2),
            ];

            $writeOffStmt->execute([$m['start'], $m['end']]);
            $lines[] = [
                'section' => 'MONTHLY_DETAIL',
                'label' => 'Bad Debts',
                'sub_label' => $m['label'],
                'amount_1' => round((float) $writeOffStmt->fetchColumn(), 2),
            ];

            foreach ($categories as $cat) {
                $categoryStmt->execute([$cat['id'], $m['start'], $m['end']]);
                $lines[] = [
                    'section' => 'MONTHLY_DETAIL',
                    'label' => $cat['category_name'],
                    'sub_label' => $m['label'],
                    'amount_1' => round((float) $categoryStmt->fetchColumn(), 2),
                ];
            }
        }

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
