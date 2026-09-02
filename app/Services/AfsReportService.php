<?php

namespace App\Services;

use App\Core\Database;

/**
 * Computes the numbers for the client's Annual Financial Statement export
 * (Profit & Loss, Balance Sheet, Cash Flow) from posted general ledger
 * activity. Every line is driven by one or more accounting_accounts rows
 * tagged with an afs_line_code (see database/schema.sql) -- adding a new
 * line to the template means tagging an account, not touching this class.
 *
 * P&L / Cash Flow figures are the account's net movement within
 * [startDate, endDate]. Balance Sheet figures are the account's cumulative
 * balance as of endDate (from inception).
 */
class AfsReportService
{
    public static function profitLossLines(): array
    {
        return [
            ['code' => 'pl_interest_income', 'label' => 'Interest Income'],
            ['code' => 'pl_interest_investment', 'label' => 'Interest Received from Investments'],
        ];
    }

    public static function costOfSaleLines(): array
    {
        return [
            ['code' => 'pl_cos_document_storage', 'label' => 'Document Storage Fees'],
            ['code' => 'pl_cos_subscriptions', 'label' => 'Subscriptions & Service Provider Fees'],
            ['code' => 'pl_cos_annuality', 'label' => 'Annuality (BIPA Fees & SSC)'],
            ['code' => 'pl_cos_namfisa_levy', 'label' => 'Levies (NAMFISA)'],
            ['code' => 'pl_cos_license_fees', 'label' => 'License Fees (Renewal - NAMFISA)'],
            ['code' => 'pl_cos_rounding', 'label' => 'AFS rounding difference'],
        ];
    }

    public static function operatingExpenseLines(): array
    {
        return [
            ['code' => 'pl_opex_accounting_officer', 'label' => 'Accounting officer fees'],
            ['code' => 'pl_opex_admin', 'label' => 'Administration fees'],
            ['code' => 'pl_opex_advertising', 'label' => 'Advertising and Promotions'],
            ['code' => 'pl_opex_bad_debts', 'label' => 'Bad Debts'],
            ['code' => 'pl_opex_bank_charges', 'label' => 'Bank Charges'],
            ['code' => 'pl_opex_building_maintenance', 'label' => 'Building Maintenance'],
            ['code' => 'pl_opex_cleaning', 'label' => 'Cleaning'],
            ['code' => 'pl_opex_consulting', 'label' => 'Consulting fees'],
            ['code' => 'pl_opex_computer', 'label' => 'Computer expenses'],
            ['code' => 'pl_opex_courier', 'label' => 'Courier and postage'],
            ['code' => 'pl_opex_depreciation', 'label' => 'Depreciation'],
            ['code' => 'pl_opex_employee_welfare', 'label' => 'Employee Welfare'],
            ['code' => 'pl_opex_freight', 'label' => 'Freight on Goods Purchased'],
            ['code' => 'pl_opex_general', 'label' => 'General Expenses'],
            ['code' => 'pl_opex_insurance', 'label' => 'Insurance'],
            ['code' => 'pl_opex_interest_paid', 'label' => 'Interest paid'],
            ['code' => 'pl_opex_rent', 'label' => 'Rent Payment'],
            ['code' => 'pl_opex_legal', 'label' => 'Legal Fees'],
            ['code' => 'pl_opex_medical', 'label' => 'Medical Expenses'],
            ['code' => 'pl_opex_members_salaries', 'label' => 'Members salaries'],
            ['code' => 'pl_opex_vehicle_rental', 'label' => 'Motor vehicle Rental'],
            ['code' => 'pl_opex_municipal', 'label' => 'Municipal Expenses'],
            ['code' => 'pl_opex_office_supplies', 'label' => 'Office supplies'],
            ['code' => 'pl_opex_printing', 'label' => 'Printing and stationery'],
            ['code' => 'pl_opex_vehicle_maintenance', 'label' => 'Fuel, Repairs and maintenance of Vehicle'],
            ['code' => 'pl_opex_salaries_wages', 'label' => 'Salaries and wages'],
            ['code' => 'pl_opex_security', 'label' => 'Security services'],
            ['code' => 'pl_opex_telephone', 'label' => 'Telephone and fax'],
            ['code' => 'pl_opex_transport', 'label' => 'Transport on Goods Purchased'],
            ['code' => 'pl_opex_travel', 'label' => 'Travel, Entertainment and Accommodation'],
            ['code' => 'pl_opex_uniform', 'label' => 'Uniform (Staff)'],
        ];
    }

    public static function balanceSheetNonCurrentAssetLines(): array
    {
        return [
            ['code' => 'bs_movable_assets', 'label' => 'Movable Assets'],
            ['code' => 'bs_land_building', 'label' => 'Land & Building'],
        ];
    }

    public static function balanceSheetCurrentAssetLines(): array
    {
        return [
            ['code' => 'bs_inventory', 'label' => 'Inventory'],
            // Loan principal (bs_loan_to_members) is folded into
            // 'Receivables and prepayments' by the callers below, at
            // display time only -- it stays a separate code fetched
            // directly by each exporter (needed for the Cash Flow
            // statement's loan-movement line) and is never merged into
            // the raw bs_receivables_prepayments balance itself, since
            // that same code independently feeds the Tax Computation's
            // "Less: Receivables & Prepayment" deduction and must not
            // pick up the loan book.
            ['code' => 'bs_receivables_prepayments', 'label' => 'Receivables and prepayments'],
            // 'Cash and cash equivalents' is computed separately from all
            // is_cash_bank_account=1 accounts, not a single tagged line.
        ];
    }

    public static function balanceSheetCurrentLiabilityLines(): array
    {
        return [
            ['code' => 'bs_accounts_payable', 'label' => 'Account Payable'],
            ['code' => 'bs_tax_payable', 'label' => 'Tax Payable'],
            ['code' => 'bs_bank_overdrafts', 'label' => 'Bank Overdrafts'],
        ];
    }

    /**
     * Sum of the given account_type's normal-balance-signed movement between
     * two dates, grouped by afs_line_code, for accounts tagged with any of
     * the given codes. Returns [code => amount].
     */
    public static function movementByCode(array $codes, string $startDate, string $endDate): array
    {
        if (empty($codes)) {
            return [];
        }

        $db = Database::connection();
        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        $stmt = $db->prepare(
            "SELECT aa.afs_line_code,
                    aa.normal_balance,
                    COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date BETWEEN ? AND ? THEN jl.debit ELSE 0 END),0) AS total_debit,
                    COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date BETWEEN ? AND ? THEN jl.credit ELSE 0 END),0) AS total_credit
             FROM accounting_accounts aa
             LEFT JOIN accounting_journal_lines jl ON jl.account_id = aa.id
             LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE aa.afs_line_code IN ($placeholders)
             GROUP BY aa.id"
        );
        $stmt->execute(array_merge([$startDate, $endDate, $startDate, $endDate], $codes));

        $totals = array_fill_keys($codes, 0.0);
        foreach ($stmt->fetchAll() as $row) {
            $debit = (float) $row['total_debit'];
            $credit = (float) $row['total_credit'];
            $amount = $row['normal_balance'] === 'Credit' ? ($credit - $debit) : ($debit - $credit);
            $totals[$row['afs_line_code']] = round(($totals[$row['afs_line_code']] ?? 0) + $amount, 2);
        }

        return $totals;
    }

    /**
     * Raw (not normal-balance-signed, not netted) debit and credit totals
     * for a single afs_line_code within [startDate, endDate]. Needed where
     * the Cash Flow Statement must keep the two sides of one balance sheet
     * account's movement apart rather than netting them -- loan principal
     * disbursed and principal collected both post to the same Loans
     * Receivable account, but are opposite cash flows that belong on
     * different Cash Flow Statement lines (disbursed is cash paid out,
     * collected is cash received) -- see AfsExcelExporter::buildCashFlow().
     */
    public static function debitCreditByCode(string $code, string $startDate, string $endDate): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date BETWEEN ? AND ? THEN jl.debit ELSE 0 END),0) AS total_debit,
                COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date BETWEEN ? AND ? THEN jl.credit ELSE 0 END),0) AS total_credit
             FROM accounting_accounts aa
             LEFT JOIN accounting_journal_lines jl ON jl.account_id = aa.id
             LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE aa.afs_line_code = ?"
        );
        $stmt->execute([$startDate, $endDate, $startDate, $endDate, $code]);
        $row = $stmt->fetch();

        return [
            'debit' => round((float) ($row['total_debit'] ?? 0), 2),
            'credit' => round((float) ($row['total_credit'] ?? 0), 2),
        ];
    }

    /**
     * Cumulative balance as of a date (from inception) for the given codes.
     */
    public static function balanceByCode(array $codes, string $asOfDate): array
    {
        return self::movementByCode($codes, '1970-01-01', $asOfDate);
    }

    /**
     * Total cash: sum of every account flagged as a cash/bank account,
     * as-of a date.
     */
    public static function cashBalance(string $asOfDate): float
    {
        $db = Database::connection();
        $row = $db->prepare(
            "SELECT aa.id, aa.normal_balance,
                    COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date <= ? THEN jl.debit ELSE 0 END),0) AS total_debit,
                    COALESCE(SUM(CASE WHEN je.status = 'Posted' AND je.journal_date <= ? THEN jl.credit ELSE 0 END),0) AS total_credit
             FROM accounting_accounts aa
             LEFT JOIN accounting_journal_lines jl ON jl.account_id = aa.id
             LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE aa.is_cash_bank_account = 1
             GROUP BY aa.id"
        );
        $row->execute([$asOfDate, $asOfDate]);

        $total = 0.0;
        foreach ($row->fetchAll() as $r) {
            $debit = (float) $r['total_debit'];
            $credit = (float) $r['total_credit'];
            $total += $r['normal_balance'] === 'Credit' ? ($credit - $debit) : ($debit - $credit);
        }

        return round($total, 2);
    }

    public static function companyInfo(): ?array
    {
        $db = Database::connection();
        $stmt = $db->query("SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Net profit after taxation for a period, as a plain number -- the
     * same waterfall AfsExcelExporter::buildProfitLoss() renders as Excel
     * formulas, extracted here so other sheets (Statement of Changes in
     * Equity, Tax Computation) can share one source of truth instead of
     * re-deriving it.
     */
    public static function netProfitForPeriod(string $startDate, string $endDate, bool $beforeTax = false): float
    {
        $codes = array_merge(
            array_column(self::profitLossLines(), 'code'),
            array_column(self::costOfSaleLines(), 'code'),
            array_column(self::operatingExpenseLines(), 'code'),
            ['pl_finance_cost', 'pl_taxation']
        );
        $mv = self::movementByCode(array_values(array_unique($codes)), $startDate, $endDate);

        $income = array_sum(array_map(fn ($l) => $mv[$l['code']] ?? 0.0, self::profitLossLines()));
        $cos = array_sum(array_map(fn ($l) => $mv[$l['code']] ?? 0.0, self::costOfSaleLines()));
        $opex = array_sum(array_map(fn ($l) => $mv[$l['code']] ?? 0.0, self::operatingExpenseLines()));

        $netBeforeTax = ($income - $cos) - $opex - ($mv['pl_finance_cost'] ?? 0.0);
        if ($beforeTax) {
            return round($netBeforeTax, 2);
        }
        return round($netBeforeTax - ($mv['pl_taxation'] ?? 0.0), 2);
    }

    /**
     * Statement of Changes in Equity inputs: opening/movement/closing for
     * Members Contributions and Accumulated Profit. Opening accumulated
     * profit is the cumulative net profit from inception through the day
     * before startDate (there's no separate "Retained Earnings" ledger
     * balance maintained via a year-end closing entry in this system --
     * see AfsExcelExporter::buildBalanceSheet()'s "Retained profit" line,
     * which is likewise computed from the P&L rather than a GL balance).
     *
     * Uses the *before-tax* profit -- tax is no longer deducted anywhere on
     * the P&L or rolled into equity; it only ever appears as its own figure
     * on the separate Tax Computation note (per client instruction).
     */
    public static function equityRollForward(string $startDate, string $endDate): array
    {
        $openingAsOf = date('Y-m-d', strtotime($startDate . ' -1 day'));

        $contribOpening = self::balanceByCode(['bs_members_contributions'], $openingAsOf)['bs_members_contributions'] ?? 0.0;
        $contribMovement = self::movementByCode(['bs_members_contributions'], $startDate, $endDate)['bs_members_contributions'] ?? 0.0;

        $profitOpening = self::netProfitForPeriod('1970-01-01', $openingAsOf, true);
        $profitMovement = self::netProfitForPeriod($startDate, $endDate, true);

        return [
            'contributions_opening' => round($contribOpening, 2),
            'contributions_movement' => round($contribMovement, 2),
            'contributions_closing' => round($contribOpening + $contribMovement, 2),
            'profit_opening' => round($profitOpening, 2),
            'profit_movement' => round($profitMovement, 2),
            'profit_closing' => round($profitOpening + $profitMovement, 2),
        ];
    }

    /**
     * Prior fiscal year's accounting profit/(loss) before tax -- the
     * default for the Tax Computation's "Estimated assessable loss or
     * profit prior year" input. This is an accounting-profit proxy, not an
     * actual carried-forward tax assessment (this system doesn't track
     * historical tax assessments), so AfsManualFigure's prior_year_assessed
     * entry, when present, overrides it -- see AfsPdfExporter/AfsExcelExporter.
     */
    public static function priorYearProfit(string $currentFiscalYearStart): float
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT start_date, end_date FROM accounting_fiscal_years WHERE end_date < ? ORDER BY end_date DESC LIMIT 1"
        );
        $stmt->execute([$currentFiscalYearStart]);
        $prior = $stmt->fetch();
        if (!$prior) {
            return 0.0;
        }

        return self::netProfitForPeriod($prior['start_date'], $prior['end_date'], true);
    }

    /**
     * Capital allowances derived from the fixed asset register: a
     * wear-and-tear allowance per category, written off over 3 years
     * straight-line on the category's qualifying cost (cost_closing / 3) --
     * not the accounting depreciation charge, which follows the asset's own
     * useful life and can differ from the tax write-off period.
     */
    public static function capitalAllowancesFromAssetRegister(string $startDate, string $endDate): array
    {
        $fa = self::fixedAssetNote($startDate, $endDate);

        $rows = [];
        $total = 0.0;
        foreach ($fa['rows'] as $r) {
            if (abs($r['cost_closing']) < 0.005) {
                continue;
            }
            $amount = round($r['cost_closing'] / 3, 2);
            $rows[] = ['label' => $r['category'], 'amount' => $amount];
            $total += $amount;
        }

        return ['rows' => $rows, 'total' => round($total, 2)];
    }

    /**
     * Movable (non-land) assets, computed from the fixed asset register's
     * closing net book value rather than from the bs_movable_assets GL
     * account tag -- nothing in the fixed-asset purchase workflow actually
     * posts to that tagged account, so the Balance Sheet never reflected
     * real fixed-asset activity while the Notes' PPE table (driven by the
     * same register) did, leaving the two sections out of step with each
     * other.
     *
     * Land & Building is deliberately NOT derived here: it is recorded
     * manually (AfsManualFigure, section notes_land) and must never be
     * pulled from the register, since land is typically held personally by
     * a member rather than by the company/cc, and including it requires a
     * human to confirm actual ownership from title/supporting documents.
     */
    public static function nonCurrentAssetsFromRegister(string $startDate, string $endDate): array
    {
        $fa = self::fixedAssetNote($startDate, $endDate);

        $movable = 0.0;
        foreach ($fa['rows'] as $r) {
            $movable += $r['nbv_closing'];
        }

        return ['movable' => round($movable, 2)];
    }

    /**
     * Property/Plant/Equipment note: opening carrying amount, at-cost and
     * accumulated-depreciation splits, additions, disposals, the period's
     * depreciation charge, and closing carrying amount -- one row per
     * active asset_categories row that has at least one asset, plus a
     * TOTAL row. Driven by asset_depreciation_schedules (posted rows only)
     * for point-in-time opening/closing accumulated depreciation, not the
     * live fixed_assets.accumulated_depreciation column, since that only
     * ever holds today's value.
     */
    public static function fixedAssetNote(string $startDate, string $endDate): array
    {
        $db = Database::connection();

        $categories = $db->query("SELECT id, category_name FROM asset_categories ORDER BY category_name")->fetchAll();

        $rows = [];
        $totals = ['cost_opening' => 0.0, 'accum_opening' => 0.0, 'additions' => 0.0, 'disposals_cost' => 0.0, 'disposals_proceeds' => 0.0, 'depreciation' => 0.0, 'cost_closing' => 0.0, 'accum_closing' => 0.0, 'nbv_opening' => 0.0, 'nbv_closing' => 0.0];

        foreach ($categories as $cat) {
            $assetsStmt = $db->prepare(
                "SELECT fa.id, fa.capitalized_cost, fa.purchase_date, ad.disposal_date
                 FROM fixed_assets fa
                 LEFT JOIN asset_disposals ad ON ad.asset_id = fa.id
                 WHERE fa.category_id = ? AND fa.purchase_date <= ?"
            );
            $assetsStmt->execute([$cat['id'], $endDate]);
            $assetRows = $assetsStmt->fetchAll();

            $costOpening = 0.0;
            $additions = 0.0;
            $disposalsCost = 0.0;
            $assetIds = [];

            foreach ($assetRows as $a) {
                // Fully disposed before this period started -- irrelevant to it.
                if ($a['disposal_date'] && $a['disposal_date'] < $startDate) {
                    continue;
                }
                $assetIds[] = (int) $a['id'];
                $cost = (float) $a['capitalized_cost'];
                if ($a['purchase_date'] < $startDate) {
                    $costOpening += $cost;
                } else {
                    $additions += $cost;
                }
                if ($a['disposal_date'] && $a['disposal_date'] >= $startDate && $a['disposal_date'] <= $endDate) {
                    $disposalsCost += $cost;
                }
            }

            if (empty($assetIds)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));

            $accumOpeningStmt = $db->prepare(
                "SELECT COALESCE(SUM(depreciation_amount),0) FROM asset_depreciation_schedules
                 WHERE asset_id IN ($placeholders) AND status = 'Posted' AND period_date < ?"
            );
            $accumOpeningStmt->execute(array_merge($assetIds, [$startDate]));
            $accumOpening = (float) $accumOpeningStmt->fetchColumn();

            $depreciationStmt = $db->prepare(
                "SELECT COALESCE(SUM(depreciation_amount),0) FROM asset_depreciation_schedules
                 WHERE asset_id IN ($placeholders) AND status = 'Posted' AND period_date BETWEEN ? AND ?"
            );
            $depreciationStmt->execute(array_merge($assetIds, [$startDate, $endDate]));
            $depreciation = (float) $depreciationStmt->fetchColumn();

            // Accumulated depreciation removed from the books for whatever
            // was disposed this period: cost - NBV at disposal (algebraic
            // split, since asset_disposals only stores the NBV, not the
            // cost/accum-depreciation breakdown separately).
            $disposalsAccumStmt = $db->prepare(
                "SELECT COALESCE(SUM(fa.capitalized_cost - ad.net_book_value_at_disposal),0)
                 FROM asset_disposals ad JOIN fixed_assets fa ON fa.id = ad.asset_id
                 WHERE fa.category_id = ? AND ad.disposal_date BETWEEN ? AND ?"
            );
            $disposalsAccumStmt->execute([$cat['id'], $startDate, $endDate]);
            $disposalsAccum = (float) $disposalsAccumStmt->fetchColumn();

            // Actual cash received for what was disposed this period -- not
            // the same as disposals_cost (the cost removed from the books);
            // used by the Cash Flow Statement, not this note's own table.
            $disposalsProceedsStmt = $db->prepare(
                "SELECT COALESCE(SUM(ad.disposal_proceeds),0)
                 FROM asset_disposals ad JOIN fixed_assets fa ON fa.id = ad.asset_id
                 WHERE fa.category_id = ? AND ad.disposal_date BETWEEN ? AND ?"
            );
            $disposalsProceedsStmt->execute([$cat['id'], $startDate, $endDate]);
            $disposalsProceeds = (float) $disposalsProceedsStmt->fetchColumn();

            $costClosing = $costOpening + $additions - $disposalsCost;
            $accumClosing = $accumOpening + $depreciation - $disposalsAccum;

            $rows[] = [
                'category' => $cat['category_name'],
                'cost_opening' => round($costOpening, 2),
                'accum_opening' => round($accumOpening, 2),
                'nbv_opening' => round($costOpening - $accumOpening, 2),
                'additions' => round($additions, 2),
                'disposals_cost' => round($disposalsCost, 2),
                'disposals_proceeds' => round($disposalsProceeds, 2),
                'depreciation' => round($depreciation, 2),
                'cost_closing' => round($costClosing, 2),
                'accum_closing' => round($accumClosing, 2),
                'nbv_closing' => round($costClosing - $accumClosing, 2),
            ];

            $totals['cost_opening'] += $costOpening;
            $totals['accum_opening'] += $accumOpening;
            $totals['nbv_opening'] += $costOpening - $accumOpening;
            $totals['additions'] += $additions;
            $totals['disposals_cost'] += $disposalsCost;
            $totals['disposals_proceeds'] += $disposalsProceeds;
            $totals['depreciation'] += $depreciation;
            $totals['cost_closing'] += $costClosing;
            $totals['accum_closing'] += $accumClosing;
            $totals['nbv_closing'] += $costClosing - $accumClosing;
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
