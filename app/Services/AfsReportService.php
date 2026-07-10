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
            ['code' => 'bs_loan_to_members', 'label' => 'Loan to Members'],
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
}
