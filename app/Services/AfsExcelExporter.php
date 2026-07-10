<?php

namespace App\Services;

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
    private int $netProfitAfterTaxRow = 0;

    public function __construct(string $companyName, string $startDate, string $endDate)
    {
        $this->companyName = $companyName;
        $this->startDate = $startDate;
        $this->endDate = $endDate;

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
            array_column(AfsReportService::balanceSheetNonCurrentAssetLines(), 'code'),
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
    }

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $spreadsheet->removeSheetByIndex(0);

        $this->buildProfitLoss($spreadsheet->createSheet());
        $this->buildBalanceSheet($spreadsheet->createSheet());
        $this->buildCashFlow($spreadsheet->createSheet());

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
        $netBeforeTaxRow = $row;
        $this->totalRow($sheet, $row++, 'Net profit before taxation', "C{$pbitRow}-C{$financeCostRow}");
        $taxRow = $row;
        $this->dataRow($sheet, $row++, 'Taxation', $this->plMovement['pl_taxation'] ?? 0);
        $netAfterTaxRow = $row;
        $this->totalRow($sheet, $row++, 'Net profit after taxation', "C{$netBeforeTaxRow}-C{$taxRow}", true);

        $this->netProfitAfterTaxRow = $netAfterTaxRow;
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
        foreach (AfsReportService::balanceSheetNonCurrentAssetLines() as $line) {
            $this->dataRow($sheet, $row++, $line['label'], $this->bsBalance[$line['code']] ?? 0);
        }
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
        $this->dataFormulaRow($sheet, $row++, 'Retained profit', "ProfitLoss!C{$this->netProfitAfterTaxRow}");
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
        // Part of any loan book increase can be funded by a non-cash accrual
        // (e.g. NAMFISA levy / duty stamp raised as a payable at disbursement
        // rather than paid from the bank) -- net that portion back out so
        // this line reflects the actual cash advanced/recovered.
        $loansGrantedRow = $row;
        $this->dataRow($sheet, $row++, 'Loans (granted)/repaid', -$bsMv('bs_loan_to_members') + $bsMv('bs_accounts_payable'));
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
