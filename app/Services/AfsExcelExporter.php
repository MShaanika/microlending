<?php

namespace App\Services;

use App\Models\AfsManualFigure;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the Annual Financial Statements workbook (Profit & Loss, Balance
 * Sheet, Cash Flow) in the layout the client requested, matching their
 * example template's section order, subtotal structure and styling.
 * Section input rows carry ledger-derived amounts; subtotal/total rows are
 * live Excel formulas so the workbook stays correct if opened and re-summed.
 */
class AfsExcelExporter
{
    private const NUMBER_FORMAT = '#,##0;[Red](#,##0);\-';
    private const TITLE_FILL = 'D9EAF7';
    private const TITLE_FONT = '1F497D';
    private const HEADER_FONT = '2B4575';

    private string $companyName;
    private string $startDate;
    private string $endDate;

    /** @var array<string,float> */
    private array $plMovement;
    /** @var array<string,float> */
    private array $bsBalance;
    /** @var array<string,float> */
    private array $bsOpeningBalance;

    private float $cashClosing;
    private float $cashOpening;
    /** @var array{movable: float, land_building: float} */
    private array $nonCurrentAssets;
    /** Row on the ProfitLoss sheet holding "Net profit before taxation" -- the P&L's final line; the Balance Sheet's "Retained profit" links to it. */
    private int $netProfitRow = 0;

    private ?int $fiscalYearId;
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $taxFigures = [];
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $policyFigures = [];
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $memberFigures = [];
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $borrowingFigures = [];
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $ownershipFigures = [];
    /** @var array<string, array{label: ?string, value_text: ?string, value_number: ?float}> */
    private array $landFigures = [];

    public function __construct(string $companyName, string $startDate, string $endDate, ?int $fiscalYearId = null)
    {
        $this->companyName = $companyName;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->fiscalYearId = $fiscalYearId;

        if ($fiscalYearId) {
            $figures = new AfsManualFigure();
            $this->taxFigures = $figures->forSection($fiscalYearId, 'tax_computation');
            $this->policyFigures = $figures->forSection($fiscalYearId, 'notes_policies');
            $this->memberFigures = $figures->forSection($fiscalYearId, 'notes_members_transactions');
            $this->borrowingFigures = $figures->forSection($fiscalYearId, 'notes_borrowings');
            $this->ownershipFigures = $figures->forSection($fiscalYearId, 'notes_ownership');
            $this->landFigures = $figures->forSection($fiscalYearId, 'notes_land');
        }

        $plCodes = array_merge(
            array_column(AfsReportService::profitLossLines(), 'code'),
            array_column(AfsReportService::costOfSaleLines(), 'code'),
            array_column(AfsReportService::operatingExpenseLines(), 'code'),
            ['pl_finance_cost', 'pl_taxation', 'pl_opex_interest_paid',
             'cf_distributions_members', 'bs_movable_assets', 'cf_investments_made',
             'bs_members_contributions', 'bs_loan_to_members', 'bs_interest_bearing_borrowings',
             'bs_longterm_borrowings']
        );
        $this->plMovement = AfsReportService::movementByCode(array_values(array_unique($plCodes)), $startDate, $endDate);

        $bsCodes = array_merge(
            array_column(AfsReportService::balanceSheetCurrentAssetLines(), 'code'),
            array_column(AfsReportService::balanceSheetCurrentLiabilityLines(), 'code'),
            ['bs_members_contributions', 'bs_interest_bearing_borrowings', 'bs_longterm_borrowings', 'bs_provision_doubtful_debts']
        );
        $bsCodes = array_values(array_unique($bsCodes));
        $this->bsBalance = AfsReportService::balanceByCode($bsCodes, $endDate);
        $openingAsOf = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $this->bsOpeningBalance = AfsReportService::balanceByCode($bsCodes, $openingAsOf);

        $this->cashClosing = AfsReportService::cashBalance($endDate);
        $this->cashOpening = AfsReportService::cashBalance($openingAsOf);
        $this->nonCurrentAssets = AfsReportService::nonCurrentAssetsFromRegister($startDate, $endDate);
        $this->nonCurrentAssets['land_building'] = (float) ($this->landFigures['land_building']['value_number'] ?? 0.0);
    }

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $spreadsheet->removeSheetByIndex(0);

        $this->buildProfitLoss($spreadsheet->createSheet());
        $this->buildBalanceSheet($spreadsheet->createSheet());
        $this->buildCashFlow($spreadsheet->createSheet());
        $this->buildStatementOfChangesInEquity($spreadsheet->createSheet());
        $this->buildTaxComputation($spreadsheet->createSheet());
        $this->buildNotesToAfs($spreadsheet->createSheet());

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }

    // ------------------------------------------------------------------
    // Profit & Loss
    // ------------------------------------------------------------------

    private function buildProfitLoss($sheet): void
    {
        $sheet->setTitle('ProfitLoss');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 42, 'C' => 16]);

        $this->title($sheet, 'PROFIT & LOSS / DETAILED INCOME STATEMENT', 'For the year ended ' . $this->formatDate($this->endDate));

        $row = 5;
        $row = $this->sectionHeader($sheet, $row, 'INCOME');
        $incomeStart = $row;
        foreach (AfsReportService::profitLossLines() as $line) {
            $this->dataRow($sheet, $row++, $line['label'], $this->plMovement[$line['code']] ?? 0);
        }
        $incomeEnd = $row - 1;
        $totalIncomeRow = $row;
        $this->totalRow($sheet, $row++, 'Total Income', "SUM(C{$incomeStart}:C{$incomeEnd})");
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'COST OF SALE');
        $cosStart = $row;
        foreach (AfsReportService::costOfSaleLines() as $line) {
            $this->dataRow($sheet, $row++, $line['label'], $this->plMovement[$line['code']] ?? 0);
        }
        $cosEnd = $row - 1;
        $totalCosRow = $row;
        $this->totalRow($sheet, $row++, 'Total Cost of Sale', "SUM(C{$cosStart}:C{$cosEnd})");
        $grossProfitRow = $row;
        $this->totalRow($sheet, $row++, 'Gross Profit', "C{$totalIncomeRow}-C{$totalCosRow}");
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'OPERATING EXPENSES');
        $opexStart = $row;
        foreach (AfsReportService::operatingExpenseLines() as $line) {
            $this->dataRow($sheet, $row++, $line['label'], $this->plMovement[$line['code']] ?? 0);
        }
        $opexEnd = $row - 1;
        $totalOpexRow = $row;
        $this->totalRow($sheet, $row++, 'Total Operating Expenses', "SUM(C{$opexStart}:C{$opexEnd})");
        $row++;

        $pbitRow = $row;
        $this->totalRow($sheet, $row++, 'Profit before interest and taxation', "C{$grossProfitRow}-C{$totalOpexRow}");
        $financeCostRow = $row;
        $this->dataRow($sheet, $row++, 'Finance Cost', $this->plMovement['pl_finance_cost'] ?? 0);
        // Ends here -- tax is not calculated/deducted on the P&L (per
        // client instruction). It only ever appears as its own figure on
        // the separate Tax Computation note.
        $netBeforeTaxRow = $row;
        $this->totalRow($sheet, $row++, 'Net profit before taxation', "C{$pbitRow}-C{$financeCostRow}", true);

        $this->netProfitRow = $netBeforeTaxRow;
    }

    // ------------------------------------------------------------------
    // Balance Sheet
    // ------------------------------------------------------------------

    private function buildBalanceSheet($sheet): void
    {
        $sheet->setTitle('BalanceSheet');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 42, 'C' => 16]);

        $this->title($sheet, 'BALANCE SHEET / STATEMENT OF FINANCIAL POSITION', 'As at ' . $this->formatDate($this->endDate));

        $row = 5;
        $row = $this->sectionHeader($sheet, $row, 'NON-CURRENT ASSETS');
        $ncaStart = $row;
        $this->dataRow($sheet, $row++, 'Movable Assets', $this->nonCurrentAssets['movable']);
        $this->dataRow($sheet, $row++, 'Land & Building', $this->nonCurrentAssets['land_building']);
        $ncaEnd = $row - 1;
        $totalNcaRow = $row;
        $this->totalRow($sheet, $row++, 'Total Non-current Assets', "SUM(C{$ncaStart}:C{$ncaEnd})");
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'CURRENT ASSETS');
        $caStart = $row;
        foreach (AfsReportService::balanceSheetCurrentAssetLines() as $line) {
            $amount = $this->bsBalance[$line['code']] ?? 0;
            if ($line['code'] === 'bs_loan_to_members') {
                // Present net of the doubtful-debts provision -- the
                // template has one "Loan to Members" line, not a separate
                // provision line.
                $amount -= $this->bsBalance['bs_provision_doubtful_debts'] ?? 0;
            }
            $this->dataRow($sheet, $row++, $line['label'], $amount);
        }
        $this->dataRow($sheet, $row++, 'Cash and cash equivalents', $this->cashClosing);
        $caEnd = $row - 1;
        $totalCaRow = $row;
        $this->totalRow($sheet, $row++, 'Total Current Assets', "SUM(C{$caStart}:C{$caEnd})");
        $row++;

        $totalAssetsRow = $row;
        $this->totalRow($sheet, $row++, 'TOTAL ASSETS', "C{$totalNcaRow}+C{$totalCaRow}", true);
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'CAPITAL AND RESERVES');
        $capStart = $row;
        $this->dataRow($sheet, $row++, 'Members contributions', $this->bsBalance['bs_members_contributions'] ?? 0);
        $retainedProfitRow = $row;
        $this->dataFormulaRow($sheet, $row++, 'Retained profit', "ProfitLoss!C{$this->netProfitRow}");
        $capEnd = $row - 1;
        $totalCapRow = $row;
        $this->totalRow($sheet, $row++, 'Total Capital and Reserves', "SUM(C{$capStart}:C{$capEnd})");
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'NON-CURRENT LIABILITIES');
        $nclRow = $row;
        $this->dataRow($sheet, $row++, 'Interest Bearing Borrowings', $this->bsBalance['bs_interest_bearing_borrowings'] ?? 0);
        $totalNclRow = $row;
        $this->totalRow($sheet, $row++, 'Total Non-current Liabilities', "C{$nclRow}");
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'CURRENT LIABILITIES');
        $clStart = $row;
        foreach (AfsReportService::balanceSheetCurrentLiabilityLines() as $line) {
            $this->dataRow($sheet, $row++, $line['label'], $this->bsBalance[$line['code']] ?? 0);
        }
        $clEnd = $row - 1;
        $totalClRow = $row;
        $this->totalRow($sheet, $row++, 'Total Current Liabilities', "SUM(C{$clStart}:C{$clEnd})");
        $row++;

        $totalLiabRow = $row;
        $this->totalRow($sheet, $row++, 'Total Liabilities', "C{$totalNclRow}+C{$totalClRow}");
        $row++;

        $totalEqLiabRow = $row;
        $this->totalRow($sheet, $row++, 'TOTAL EQUITY AND LIABILITIES', "C{$totalCapRow}+C{$totalLiabRow}", true);
        $row++;

        $this->dataFormulaRow($sheet, $row++, 'Balance Check (must be zero)', "C{$totalAssetsRow}-C{$totalEqLiabRow}");

        $row += 2;
        $row = $this->sectionHeader($sheet, $row, 'COMMON RATIOS');
        $this->dataFormulaRow($sheet, $row++, 'Debt Ratio', "IFERROR(C{$totalLiabRow}/C{$totalAssetsRow},0)", '0.00');
        $this->dataFormulaRow($sheet, $row++, 'Current Ratio', "IFERROR(C{$totalCaRow}/C{$totalClRow},0)", '0.00');
        $this->dataFormulaRow($sheet, $row++, 'Working Capital', "C{$totalCaRow}-C{$totalClRow}");
        $this->dataFormulaRow($sheet, $row++, 'Assets-to-Equity', "IFERROR(C{$totalAssetsRow}/C{$totalCapRow},0)", '0.00');
        $this->dataFormulaRow($sheet, $row++, 'Debt-to-Equity', "IFERROR(C{$totalLiabRow}/C{$totalCapRow},0)", '0.00');
    }

    // ------------------------------------------------------------------
    // Cash Flow
    // ------------------------------------------------------------------

    private function buildCashFlow($sheet): void
    {
        $sheet->setTitle('CashFlow');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 46, 'C' => 16]);

        $this->title($sheet, 'CASH FLOW STATEMENT', 'For year ended ' . $this->formatDate($this->endDate));

        $mv = function (string $code): float {
            return $this->plMovement[$code] ?? 0.0;
        };
        $bsMv = function (string $code): float {
            return ($this->bsBalance[$code] ?? 0) - ($this->bsOpeningBalance[$code] ?? 0);
        };

        $cashFromCustomers = ($mv('pl_interest_income') ?: 0) + ($mv('pl_interest_investment') ?: 0);
        $cosTotal = array_sum(array_map(fn ($l) => $mv($l['code']), AfsReportService::costOfSaleLines()));
        $opexTotal = array_sum(array_map(
            fn ($l) => $l['code'] === 'pl_opex_depreciation' ? 0.0 : $mv($l['code']),
            AfsReportService::operatingExpenseLines()
        ));
        $cashToSuppliers = -($cosTotal + $opexTotal);

        $row = 5;
        $row = $this->sectionHeader($sheet, $row, 'CASH FLOWS FROM OPERATING ACTIVITIES');
        $recRow = $row;
        $this->dataRow($sheet, $row++, 'Cash receipts from customers', $cashFromCustomers);
        $paidRow = $row;
        $this->dataRow($sheet, $row++, 'Cash paid to suppliers and employees', $cashToSuppliers);
        $genRow = $row;
        $this->totalRow($sheet, $row++, 'Cash generated from operations', "C{$recRow}+C{$paidRow}");
        $intPaidRow = $row;
        $this->dataRow($sheet, $row++, 'Interest paid', -$mv('pl_opex_interest_paid'));
        $financeRow = $row;
        $this->dataRow($sheet, $row++, 'Finance charges', -$mv('pl_finance_cost'));
        $distRow = $row;
        $this->dataRow($sheet, $row++, 'Distributions to members', -$mv('cf_distributions_members'));
        $taxPaidRow = $row;
        $this->dataRow($sheet, $row++, 'Normal taxation paid', -$mv('pl_taxation'));
        $netOperatingRow = $row;
        $this->totalRow($sheet, $row++, 'Net cash inflow from operating activities', "SUM(C{$genRow}:C{$taxPaidRow})", true);
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'CASH FLOWS FROM INVESTING ACTIVITIES');
        $movableRow = $row;
        $this->dataRow($sheet, $row++, 'Sale/(Purchase) of Movable Assets', -$bsMv('bs_movable_assets'));
        $investRow = $row;
        $this->dataRow($sheet, $row++, 'Investments made', -$bsMv('cf_investments_made'));
        $netInvestingRow = $row;
        $this->totalRow($sheet, $row++, 'Net cash from investing activities', "SUM(C{$movableRow}:C{$investRow})", true);
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'CASH FLOWS FROM FINANCING ACTIVITIES');
        $membersContribRow = $row;
        $this->dataRow($sheet, $row++, 'Members contribution', $bsMv('bs_members_contributions'));
        // This is the actual cash principal advanced/recovered -- Account
        // Payable (e.g. NAMFISA levy / duty stamp accrued at disbursement)
        // is a separate non-cash liability and must never be netted against
        // it here, even though both can move on the same disbursement.
        $loansGrantedRow = $row;
        $this->dataRow($sheet, $row++, 'Loans (granted)/repaid', -$bsMv('bs_loan_to_members'));
        $loansMemberRow = $row;
        $this->dataRow($sheet, $row++, 'Decrease/(Increase) in loans from member', $bsMv('bs_interest_bearing_borrowings'));
        $ltbMovement = $bsMv('bs_longterm_borrowings');
        $proceedsRow = $row;
        $this->dataRow($sheet, $row++, 'Proceeds from long-term borrowings', max($ltbMovement, 0));
        $repaymentRow = $row;
        $this->dataRow($sheet, $row++, 'Payment of capital elements of long-term borrowings', min($ltbMovement, 0));
        $netFinancingRow = $row;
        $this->totalRow($sheet, $row++, 'Net cash from financing activities', "SUM(C{$membersContribRow}:C{$repaymentRow})", true);
        $row++;

        $netMovementRow = $row;
        $this->totalRow($sheet, $row++, 'Net increase/(decrease) in cash', "C{$netOperatingRow}+C{$netInvestingRow}+C{$netFinancingRow}", true);
        $openingRow = $row;
        $this->dataRow($sheet, $row++, 'Cash at the beginning of the period', $this->cashOpening);
        $closingRow = $row;
        $this->totalRow($sheet, $row++, 'Cash at the end of the period', "C{$netMovementRow}+C{$openingRow}", true);
        $row++;

        $this->dataFormulaRow($sheet, $row, 'Check to actual closing cash (must be zero)', "C{$closingRow}-" . $this->numericLiteral($this->cashClosing));
    }

    // ------------------------------------------------------------------
    // Statement of Changes in Equity
    // ------------------------------------------------------------------

    private function buildStatementOfChangesInEquity($sheet): void
    {
        $sheet->setTitle('ChangesInEquity');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 34, 'C' => 18, 'D' => 18, 'E' => 18]);

        $this->title($sheet, 'STATEMENT OF CHANGES IN EQUITY', 'For the year ended ' . $this->formatDate($this->endDate));

        $eq = AfsReportService::equityRollForward($this->startDate, $this->endDate);

        $row = 5;
        $sheet->setCellValue("C{$row}", 'Members Contributions');
        $sheet->setCellValue("D{$row}", 'Accumulated Profit/(Loss)');
        $sheet->setCellValue("E{$row}", 'Total');
        $sheet->getStyle("C{$row}:E{$row}")->getFont()->setBold(true);
        $row++;

        $this->equityRow($sheet, $row++, 'Balance at beginning of year', $eq['contributions_opening'], $eq['profit_opening']);
        $this->equityRow($sheet, $row++, 'Members contribution', $eq['contributions_movement'], 0.0);
        $this->equityRow($sheet, $row++, 'Net profit/(loss) for the year', 0.0, $eq['profit_movement']);

        $sheet->getStyle("B" . ($row) . ":E" . ($row))->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $this->equityRow($sheet, $row, 'Balance at end of year', $eq['contributions_closing'], $eq['profit_closing'], true);
    }

    private function equityRow($sheet, int $row, string $label, float $contributions, float $profit, bool $bold = false): void
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->setCellValue("C{$row}", round($contributions, 2));
        $sheet->setCellValue("D{$row}", round($profit, 2));
        $sheet->setCellValue("E{$row}", round($contributions + $profit, 2));
        $sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        if ($bold) {
            $sheet->getStyle("B{$row}:E{$row}")->getFont()->setBold(true);
        }
    }

    // ------------------------------------------------------------------
    // Tax Computation
    // ------------------------------------------------------------------

    private function buildTaxComputation($sheet): void
    {
        $sheet->setTitle('TaxComputation');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 42, 'C' => 16]);

        $this->title($sheet, 'TAX COMPUTATION', 'For the year ended ' . $this->formatDate($this->endDate));

        $num = fn (string $key): float => (float) ($this->taxFigures[$key]['value_number'] ?? 0);

        $profitBeforeTax = AfsReportService::netProfitForPeriod($this->startDate, $this->endDate, true);
        $depreciation = $this->plMovement['pl_opex_depreciation'] ?? 0.0;

        $row = 5;
        $profitRow = $row;
        $this->dataRow($sheet, $row++, 'Profit as per Income Statement', $profitBeforeTax);
        $deprecRow = $row;
        $this->dataRow($sheet, $row++, 'Add: Back Depreciation', $depreciation);
        $investRow = $row;
        $this->dataRow($sheet, $row++, 'Investment made as per Section 17(1)(a)', -$num('section17_investment'));
        $row++;

        $row = $this->sectionHeader($sheet, $row, 'Less: Capital Allowances');
        $caStart = $row;
        $capAllow = AfsReportService::capitalAllowancesFromAssetRegister($this->startDate, $this->endDate);
        foreach ($capAllow['rows'] as $r) {
            $this->dataRow($sheet, $row++, $r['label'], -$r['amount']);
        }
        $caEnd = max($caStart, $row - 1);
        $caTotalRow = $row;
        if ($row > $caStart) {
            $this->totalRow($sheet, $row++, 'Total Capital Allowances', "SUM(C{$caStart}:C{$caEnd})");
        } else {
            $this->dataRow($sheet, $row++, 'Total Capital Allowances', 0);
        }
        $row++;

        // Receivables/Prepayment and prior-year-assessed auto-compute from
        // the ledger/prior fiscal year; a manual figure, when present,
        // overrides the auto value.
        $receivablesAuto = $this->bsBalance['bs_receivables_prepayments'] ?? 0.0;
        $receivablesPrepayment = ($this->taxFigures['receivables_prepayment']['value_number'] ?? null) !== null
            ? (float) $this->taxFigures['receivables_prepayment']['value_number'] : $receivablesAuto;

        $recvRow = $row;
        $this->dataRow($sheet, $row++, 'Less: Receivables & Prepayment', -$receivablesPrepayment);
        $insRow = $row;
        $this->dataRow($sheet, $row++, 'Insurances and warranty', -$num('insurance_warranty'));
        $row++;

        $taxableIncomeRow = $row;
        $this->totalRow(
            $sheet,
            $row++,
            'Estimated taxable income for the year',
            "C{$profitRow}+C{$deprecRow}+C{$investRow}-C{$caTotalRow}+C{$recvRow}+C{$insRow}",
            true
        );

        $priorYearAuto = AfsReportService::priorYearProfit($this->startDate);
        $priorYear = ($this->taxFigures['prior_year_assessed']['value_number'] ?? null) !== null
            ? (float) $this->taxFigures['prior_year_assessed']['value_number'] : $priorYearAuto;

        $priorYearRow = $row;
        $this->dataRow($sheet, $row++, 'Estimated assessable loss or profit prior year', $priorYear);
        $currentAssessedRow = $row;
        $this->totalRow($sheet, $row++, 'Estimated assessable loss/profit for the year', "C{$taxableIncomeRow}+C{$priorYearRow}", true);
        $row++;

        // No flooring at zero -- a loss period genuinely produces a
        // negative "tax at X%" figure, not zero.
        $taxRate = $num('tax_rate') ?: 32.0;
        $this->dataFormulaRow($sheet, $row, "Tax at {$taxRate}%", "C{$currentAssessedRow}*{$taxRate}/100");
    }

    // ------------------------------------------------------------------
    // Notes to the AFS
    // ------------------------------------------------------------------

    private function buildNotesToAfs($sheet): void
    {
        $sheet->setTitle('NotesToAFS');
        $this->applyColumnWidths($sheet, ['A' => 3, 'B' => 34, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 16]);

        $this->title($sheet, 'NOTES TO THE ANNUAL FINANCIAL STATEMENTS', 'For the year ended ' . $this->formatDate($this->endDate));

        $row = 5;

        $row = $this->sectionHeader($sheet, $row, '1. ACCOUNTING POLICIES');
        $row = $this->wrappedTextRow($sheet, $row, '1.1 Property, plant and equipment', $this->policyFigures['ppe_policy']['value_text'] ?? '');
        $row = $this->wrappedTextRow($sheet, $row, '1.2 Revenue', $this->policyFigures['revenue_policy']['value_text'] ?? '');
        $row = $this->wrappedTextRow($sheet, $row, '1.3 Inventories', $this->policyFigures['inventory_policy']['value_text'] ?? '');
        $row++;

        $row = $this->sectionHeader($sheet, $row, "2. MEMBERS' NET INVESTMENT");
        $eq = AfsReportService::equityRollForward($this->startDate, $this->endDate);
        $this->dataRow($sheet, $row++, 'Balance at beginning of year', $eq['contributions_opening'] + $eq['profit_opening']);
        $this->dataRow($sheet, $row++, 'Contributions introduced', $eq['contributions_movement']);
        $this->dataRow($sheet, $row++, 'Net profit/(loss) for the year', $eq['profit_movement']);
        $this->totalRow($sheet, $row++, 'Balance at end of year', $this->numericLiteral($eq['contributions_closing'] + $eq['profit_closing']));
        $row++;

        $row = $this->sectionHeader($sheet, $row, '3. TRANSACTIONS WITH MEMBERS');
        $anyMember = false;
        for ($i = 1; $i <= 5; $i++) {
            $entry = $this->memberFigures['transaction_' . $i] ?? null;
            if (!$entry || empty($entry['label']) || $entry['value_number'] === null) {
                continue;
            }
            $this->dataRow($sheet, $row++, $entry['label'], (float) $entry['value_number']);
            $anyMember = true;
        }
        if (!$anyMember) {
            $sheet->setCellValue("B{$row}", 'None recorded.');
            $sheet->getStyle("B{$row}")->getFont()->setItalic(true);
            $row++;
        }
        $row++;

        $row = $this->sectionHeader($sheet, $row, '4. PROPERTY, VEHICLES, PLANT AND EQUIPMENT');
        $fa = AfsReportService::fixedAssetNote($this->startDate, $this->endDate);
        $headers = ['Category', 'Opening NBV', 'Additions', 'Disposals', 'Depreciation', 'Closing NBV'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow(2 + $i, $row, $h);
        }
        $sheet->getStyle("B{$row}:G{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($fa['rows'] as $r) {
            $sheet->setCellValue("B{$row}", $r['category']);
            $sheet->setCellValue("C{$row}", $r['nbv_opening']);
            $sheet->setCellValue("D{$row}", $r['additions']);
            $sheet->setCellValue("E{$row}", -$r['disposals_cost']);
            $sheet->setCellValue("F{$row}", -$r['depreciation']);
            $sheet->setCellValue("G{$row}", $r['nbv_closing']);
            $sheet->getStyle("C{$row}:G{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
        }
        $t = $fa['totals'];
        $sheet->setCellValue("B{$row}", 'TOTAL');
        $sheet->setCellValue("C{$row}", $t['nbv_opening']);
        $sheet->setCellValue("D{$row}", $t['additions']);
        $sheet->setCellValue("E{$row}", -$t['disposals_cost']);
        $sheet->setCellValue("F{$row}", -$t['depreciation']);
        $sheet->setCellValue("G{$row}", $t['nbv_closing']);
        $sheet->getStyle("B{$row}:G{$row}")->getFont()->setBold(true);
        $sheet->getStyle("C{$row}:G{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle("B{$row}:G{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $row += 2;

        $row = $this->sectionHeader($sheet, $row, '5. INTEREST BEARING BORROWINGS');
        $anyBorrowing = false;
        for ($i = 1; $i <= 3; $i++) {
            $entry = $this->borrowingFigures['borrowing_' . $i] ?? null;
            if (!$entry || empty($entry['label'])) {
                continue;
            }
            $sheet->setCellValue("B{$row}", $entry['label'] . ($entry['value_text'] ? ' - ' . $entry['value_text'] : ''));
            $sheet->setCellValue("C{$row}", $entry['value_number'] !== null ? round((float) $entry['value_number'], 2) : null);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
            $anyBorrowing = true;
        }
        $glBorrowings = $this->bsBalance['bs_interest_bearing_borrowings'] ?? 0.0;
        $this->totalRow($sheet, $row++, 'Interest Bearing Borrowings total (per ledger)', $this->numericLiteral($glBorrowings));
        if (!$anyBorrowing) {
            $sheet->setCellValue("B{$row}", 'No narrative recorded -- see AFS Manual Figures.');
            $sheet->getStyle("B{$row}")->getFont()->setItalic(true);
            $row++;
        }
        $row++;

        $row = $this->sectionHeader($sheet, $row, '6. MEMBERS CONTRIBUTIONS');
        $anyOwner = false;
        for ($i = 1; $i <= 3; $i++) {
            $entry = $this->ownershipFigures['owner_' . $i] ?? null;
            if (!$entry || empty($entry['label']) || $entry['value_number'] === null) {
                continue;
            }
            $sheet->setCellValue("B{$row}", $entry['label']);
            $sheet->setCellValue("C{$row}", round((float) $entry['value_number'], 2) . '%');
            $row++;
            $anyOwner = true;
        }
        if (!$anyOwner) {
            $sheet->setCellValue("B{$row}", 'Not recorded -- see AFS Manual Figures.');
            $sheet->getStyle("B{$row}")->getFont()->setItalic(true);
            $row++;
        }
        $row++;

        $row = $this->sectionHeader($sheet, $row, '7. CASH GENERATED FROM OPERATIONS');
        $profitBeforeTax = AfsReportService::netProfitForPeriod($this->startDate, $this->endDate, true);
        $depreciation = $this->plMovement['pl_opex_depreciation'] ?? 0.0;
        $financeCost = $this->plMovement['pl_finance_cost'] ?? 0.0;
        $this->dataRow($sheet, $row++, 'Profit/(loss) before tax', $profitBeforeTax);
        $this->dataRow($sheet, $row++, 'Depreciation', $depreciation);
        $this->dataRow($sheet, $row++, 'Finance charges', $financeCost);
        $this->totalRow($sheet, $row++, 'Cash generated from operations', $this->numericLiteral($profitBeforeTax + $depreciation + $financeCost), true);
        $row++;

        $row = $this->sectionHeader($sheet, $row, '8. CASH AND CASH EQUIVALENTS');
        $this->totalRow($sheet, $row++, 'Cash at bank and in hand', $this->numericLiteral($this->cashClosing), true);
    }

    /** A label row followed by a wrapped-text row spanning several columns, for policy paragraphs. */
    private function wrappedTextRow($sheet, int $row, string $label, string $text): int
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->mergeCells("B{$row}:H{$row}");
        $sheet->setCellValue("B{$row}", $text);
        $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(-1);
        return $row + 1;
    }

    // ------------------------------------------------------------------
    // Styling helpers
    // ------------------------------------------------------------------

    private function title($sheet, string $titleText, string $subtitle): void
    {
        $sheet->mergeCells('B1:F1');
        $sheet->setCellValue('B1', $titleText);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB(self::TITLE_FONT);
        $sheet->getStyle('B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TITLE_FILL);
        $sheet->getStyle('B1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('B2:F2');
        $sheet->setCellValue('B2', $this->companyName);
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(11);

        $sheet->mergeCells('B3:F3');
        $sheet->setCellValue('B3', $subtitle);
        $sheet->getStyle('B3')->getFont()->setItalic(true)->setSize(10);

    }

    private function sectionHeader($sheet, int $row, string $label): int
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setRGB(self::HEADER_FONT);
        return $row + 1;
    }

    private function dataRow($sheet, int $row, string $label, float $amount): void
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->setCellValue("C{$row}", round($amount, 2));
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
    }

    private function dataFormulaRow($sheet, int $row, string $label, string $formula, ?string $format = null): void
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->setCellValue("C{$row}", '=' . $formula);
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode($format ?? self::NUMBER_FORMAT);
    }

    private function totalRow($sheet, int $row, string $label, string $formula, bool $emphasize = false): void
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->setCellValue("C{$row}", '=' . $formula);
        $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true);
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle("B{$row}:C{$row}")->getBorders()->getBottom()->setBorderStyle(
            $emphasize ? Border::BORDER_DOUBLE : Border::BORDER_THIN
        );
    }

    private function applyColumnWidths($sheet, array $widths): void
    {
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function formatDate(string $date): string
    {
        return date('d F Y', strtotime($date));
    }

    private function numericLiteral(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
