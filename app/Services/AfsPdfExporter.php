<?php

namespace App\Services;

use App\Models\AfsManualFigure;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF version of the Annual Financial Statements export -- Profit & Loss,
 * Balance Sheet, Cash Flow, Statement of Changes in Equity, Tax
 * Computation, and Notes to the AFS, one section per page. Renders its own
 * minimal inline-styled HTML rather than reusing AfsExcelExporter's cell
 * layout, same reasoning as every other PDF exporter in this app
 * (LoanStatementPdfExporter, etc.) -- Dompdf's CSS support doesn't reliably
 * cover the full framework, and a spreadsheet's cell/formula model doesn't
 * map onto flowed HTML anyway.
 */
class AfsPdfExporter
{
    public static function build(string $companyName, string $startDate, string $endDate, ?int $fiscalYearId = null): string
    {
        $html = self::html($companyName, $startDate, $endDate, $fiscalYearId);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private static function html(string $companyName, string $startDate, string $endDate, ?int $fiscalYearId): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        // Negatives in accounting-style parentheses, matching the Excel
        // export's numFmt (which already parens-formats negatives) and
        // standard AFS presentation.
        $money = fn ($v) => ((float) $v) < 0 ? '(' . number_format(abs((float) $v), 2) . ')' : number_format((float) $v, 2);
        $period = 'For the year ended ' . date('d F Y', strtotime($endDate));

        $tax = $policies = $members = $borrowings = $ownership = [];
        if ($fiscalYearId) {
            $figures = new AfsManualFigure();
            $tax = $figures->forSection($fiscalYearId, 'tax_computation');
            $policies = $figures->forSection($fiscalYearId, 'notes_policies');
            $members = $figures->forSection($fiscalYearId, 'notes_members_transactions');
            $borrowings = $figures->forSection($fiscalYearId, 'notes_borrowings');
            $ownership = $figures->forSection($fiscalYearId, 'notes_ownership');
        }

        $plCodes = array_merge(
            array_column(AfsReportService::profitLossLines(), 'code'),
            array_column(AfsReportService::costOfSaleLines(), 'code'),
            array_column(AfsReportService::operatingExpenseLines(), 'code'),
            ['pl_finance_cost', 'pl_taxation']
        );
        $plMovement = AfsReportService::movementByCode(array_values(array_unique($plCodes)), $startDate, $endDate);

        $bsCodes = array_merge(
            array_column(AfsReportService::balanceSheetCurrentAssetLines(), 'code'),
            array_column(AfsReportService::balanceSheetCurrentLiabilityLines(), 'code'),
            ['bs_members_contributions', 'bs_interest_bearing_borrowings', 'bs_longterm_borrowings', 'bs_provision_doubtful_debts']
        );
        $bsBalance = AfsReportService::balanceByCode(array_values(array_unique($bsCodes)), $endDate);
        $cashClosing = AfsReportService::cashBalance($endDate);

        $income = array_sum(array_map(fn ($l) => $plMovement[$l['code']] ?? 0, AfsReportService::profitLossLines()));
        $cos = array_sum(array_map(fn ($l) => $plMovement[$l['code']] ?? 0, AfsReportService::costOfSaleLines()));
        $opex = array_sum(array_map(fn ($l) => $plMovement[$l['code']] ?? 0, AfsReportService::operatingExpenseLines()));
        $grossProfit = $income - $cos;
        $pbit = $grossProfit - $opex;
        $netBeforeTax = $pbit - ($plMovement['pl_finance_cost'] ?? 0);
        $netAfterTax = $netBeforeTax - ($plMovement['pl_taxation'] ?? 0);

        // ---- Profit & Loss ----
        $plRows = '';
        foreach (AfsReportService::profitLossLines() as $l) {
            $plRows .= self::row($l['label'], $plMovement[$l['code']] ?? 0, $e, $money);
        }
        $plRows .= self::totalRow('Total Income', $income, $money);
        $cosRows = '';
        foreach (AfsReportService::costOfSaleLines() as $l) {
            $cosRows .= self::row($l['label'], $plMovement[$l['code']] ?? 0, $e, $money);
        }
        $cosRows .= self::totalRow('Total Cost of Sale', $cos, $money);
        $cosRows .= self::totalRow('Gross Profit', $grossProfit, $money);
        $opexRows = '';
        foreach (AfsReportService::operatingExpenseLines() as $l) {
            $opexRows .= self::row($l['label'], $plMovement[$l['code']] ?? 0, $e, $money);
        }
        $opexRows .= self::totalRow('Total Operating Expenses', $opex, $money);

        // ---- Balance Sheet ----
        $nca = AfsReportService::nonCurrentAssetsFromRegister($startDate, $endDate);
        $ncaRows = self::row('Movable Assets', $nca['movable'], $e, $money)
            . self::row('Land & Building', $nca['land_building'], $e, $money);
        $totalNca = $nca['movable'] + $nca['land_building'];
        $caRows = '';
        foreach (AfsReportService::balanceSheetCurrentAssetLines() as $l) {
            $amount = $bsBalance[$l['code']] ?? 0;
            if ($l['code'] === 'bs_loan_to_members') {
                $amount -= $bsBalance['bs_provision_doubtful_debts'] ?? 0;
            }
            $caRows .= self::row($l['label'], $amount, $e, $money);
        }
        $caRows .= self::row('Cash and cash equivalents', $cashClosing, $e, $money);
        $totalCa = array_sum(array_map(fn ($l) => $bsBalance[$l['code']] ?? 0, AfsReportService::balanceSheetCurrentAssetLines()))
            - ($bsBalance['bs_provision_doubtful_debts'] ?? 0) + $cashClosing;
        $totalAssets = $totalNca + $totalCa;

        $clRows = '';
        foreach (AfsReportService::balanceSheetCurrentLiabilityLines() as $l) {
            $clRows .= self::row($l['label'], $bsBalance[$l['code']] ?? 0, $e, $money);
        }
        $totalCl = array_sum(array_map(fn ($l) => $bsBalance[$l['code']] ?? 0, AfsReportService::balanceSheetCurrentLiabilityLines()));
        $totalNcl = $bsBalance['bs_interest_bearing_borrowings'] ?? 0;
        $totalCap = ($bsBalance['bs_members_contributions'] ?? 0) + $netAfterTax;

        // ---- Statement of Changes in Equity ----
        $eq = AfsReportService::equityRollForward($startDate, $endDate);

        // ---- Tax Computation ----
        $num = fn (string $key) => (float) ($tax[$key]['value_number'] ?? 0);
        $profitBeforeTax = AfsReportService::netProfitForPeriod($startDate, $endDate, true);
        $depreciation = $plMovement['pl_opex_depreciation'] ?? 0;

        $capAllow = AfsReportService::capitalAllowancesFromAssetRegister($startDate, $endDate);
        $capitalAllowanceRows = '';
        foreach ($capAllow['rows'] as $r) {
            $capitalAllowanceRows .= self::row($r['label'], -$r['amount'], $e, $money);
        }
        $capitalAllowanceTotal = $capAllow['total'];

        // Receivables/Prepayment and prior-year-assessed auto-compute from
        // the ledger/prior fiscal year; a manual figure, when present,
        // overrides the auto value (e.g. once the actual assessed prior-year
        // tax position is known, which can differ from accounting profit).
        $receivablesAuto = $bsBalance['bs_receivables_prepayments'] ?? 0.0;
        $receivablesPrepayment = ($tax['receivables_prepayment']['value_number'] ?? null) !== null
            ? (float) $tax['receivables_prepayment']['value_number'] : $receivablesAuto;

        $priorYearAuto = AfsReportService::priorYearProfit($startDate);
        $priorYear = ($tax['prior_year_assessed']['value_number'] ?? null) !== null
            ? (float) $tax['prior_year_assessed']['value_number'] : $priorYearAuto;

        $taxableIncome = $profitBeforeTax + $depreciation - $num('section17_investment') - $capitalAllowanceTotal
            - $receivablesPrepayment - $num('insurance_warranty');
        $currentAssessed = $taxableIncome + $priorYear;
        $taxRate = $num('tax_rate') ?: 32.0;
        // No flooring at zero -- a loss period genuinely produces a
        // negative "tax at X%" figure (shown in parens), not zero.
        $taxDue = $currentAssessed * $taxRate / 100;

        // ---- Notes: PPE ----
        $fa = AfsReportService::fixedAssetNote($startDate, $endDate);
        $ppeRows = '';
        foreach ($fa['rows'] as $r) {
            $ppeRows .= '<tr><td>' . $e($r['category']) . '</td>'
                . '<td class="r">' . $money($r['nbv_opening']) . '</td>'
                . '<td class="r">' . $money($r['additions']) . '</td>'
                . '<td class="r">' . $money(-$r['disposals_cost']) . '</td>'
                . '<td class="r">' . $money(-$r['depreciation']) . '</td>'
                . '<td class="r">' . $money($r['nbv_closing']) . '</td></tr>';
        }
        $t = $fa['totals'];
        $ppeRows .= '<tr class="total"><td>TOTAL</td>'
            . '<td class="r">' . $money($t['nbv_opening']) . '</td>'
            . '<td class="r">' . $money($t['additions']) . '</td>'
            . '<td class="r">' . $money(-$t['disposals_cost']) . '</td>'
            . '<td class="r">' . $money(-$t['depreciation']) . '</td>'
            . '<td class="r">' . $money($t['nbv_closing']) . '</td></tr>';

        $memberRows = '';
        for ($i = 1; $i <= 5; $i++) {
            $entry = $members['transaction_' . $i] ?? null;
            if (!$entry || empty($entry['label']) || $entry['value_number'] === null) {
                continue;
            }
            $memberRows .= self::row($entry['label'], (float) $entry['value_number'], $e, $money);
        }
        if ($memberRows === '') {
            $memberRows = '<tr><td colspan="2"><em>None recorded.</em></td></tr>';
        }

        $borrowingRows = '';
        foreach ([1, 2, 3] as $i) {
            $entry = $borrowings['borrowing_' . $i] ?? null;
            if (!$entry || empty($entry['label'])) {
                continue;
            }
            $desc = $entry['label'] . ($entry['value_text'] ? ' - ' . $entry['value_text'] : '');
            $borrowingRows .= self::row($desc, (float) ($entry['value_number'] ?? 0), $e, $money);
        }
        if ($borrowingRows === '') {
            $borrowingRows = '<tr><td colspan="2"><em>No narrative recorded.</em></td></tr>';
        }
        $borrowingRows .= self::totalRow('Interest Bearing Borrowings total (per ledger)', $bsBalance['bs_interest_bearing_borrowings'] ?? 0, $money);

        $ownerRows = '';
        foreach ([1, 2, 3] as $i) {
            $entry = $ownership['owner_' . $i] ?? null;
            if (!$entry || empty($entry['label']) || $entry['value_number'] === null) {
                continue;
            }
            $ownerRows .= '<tr><td>' . $e($entry['label']) . '</td><td class="r">' . $money($entry['value_number']) . '%</td></tr>';
        }
        if ($ownerRows === '') {
            $ownerRows = '<tr><td colspan="2"><em>Not recorded.</em></td></tr>';
        }

        $cashGenerated = $profitBeforeTax + $depreciation + ($plMovement['pl_finance_cost'] ?? 0);

        // Plain <tr> without the section/total styling, for heredoc
        // interpolation below -- {$row2(...)} is valid (calling a Closure
        // held in a variable); {$this->row2(...)} would not be, since
        // there's no $this in a static method.
        $row2 = fn (string $label, float $amount) => self::row($label, $amount, $e, $money);

        $css = self::css();

        return <<<HTML
<html><head><meta charset="utf-8"><style>{$css}</style></head><body>

<div class="page">
  <h1>{$e($companyName)}</h1>
  <h2>PROFIT &amp; LOSS / DETAILED INCOME STATEMENT</h2>
  <p class="sub">{$e($period)}</p>
  <table>
    <tr class="section"><td colspan="2">INCOME</td></tr>
    {$plRows}
    <tr class="section"><td colspan="2">COST OF SALE</td></tr>
    {$cosRows}
    <tr class="section"><td colspan="2">OPERATING EXPENSES</td></tr>
    {$opexRows}
    {$row2('Profit before interest and taxation', $pbit)}
    {$row2('Finance Cost', $plMovement['pl_finance_cost'] ?? 0)}
    {$row2('Net profit before taxation', $netBeforeTax)}
    {$row2('Taxation', $plMovement['pl_taxation'] ?? 0)}
    <tr class="total"><td>Net profit after taxation</td><td class="r">{$money($netAfterTax)}</td></tr>
  </table>
</div>

<div class="page">
  <h1>{$e($companyName)}</h1>
  <h2>BALANCE SHEET / STATEMENT OF FINANCIAL POSITION</h2>
  <p class="sub">As at {$e(date('d F Y', strtotime($endDate)))}</p>
  <table>
    <tr class="section"><td colspan="2">NON-CURRENT ASSETS</td></tr>
    {$ncaRows}
    <tr class="total"><td>Total Non-current Assets</td><td class="r">{$money($totalNca)}</td></tr>
    <tr class="section"><td colspan="2">CURRENT ASSETS</td></tr>
    {$caRows}
    <tr class="total"><td>Total Current Assets</td><td class="r">{$money($totalCa)}</td></tr>
    <tr class="total"><td>TOTAL ASSETS</td><td class="r">{$money($totalAssets)}</td></tr>
    <tr class="section"><td colspan="2">CAPITAL AND RESERVES</td></tr>
    {$row2('Members contributions', $bsBalance['bs_members_contributions'] ?? 0)}
    {$row2('Retained profit', $netAfterTax)}
    <tr class="total"><td>Total Capital and Reserves</td><td class="r">{$money($totalCap)}</td></tr>
    <tr class="section"><td colspan="2">LIABILITIES</td></tr>
    {$row2('Interest Bearing Borrowings', $totalNcl)}
    {$clRows}
    <tr class="total"><td>TOTAL EQUITY AND LIABILITIES</td><td class="r">{$money($totalCap + $totalNcl + $totalCl)}</td></tr>
  </table>
</div>

<div class="page">
  <h1>{$e($companyName)}</h1>
  <h2>STATEMENT OF CHANGES IN EQUITY</h2>
  <p class="sub">{$e($period)}</p>
  <table>
    <tr class="section"><td>&nbsp;</td><td class="r">Contributions</td><td class="r">Accumulated Profit</td><td class="r">Total</td></tr>
    <tr><td>Balance at beginning of year</td><td class="r">{$money($eq['contributions_opening'])}</td><td class="r">{$money($eq['profit_opening'])}</td><td class="r">{$money($eq['contributions_opening'] + $eq['profit_opening'])}</td></tr>
    <tr><td>Members contribution</td><td class="r">{$money($eq['contributions_movement'])}</td><td class="r">-</td><td class="r">{$money($eq['contributions_movement'])}</td></tr>
    <tr><td>Net profit/(loss) for the year</td><td class="r">-</td><td class="r">{$money($eq['profit_movement'])}</td><td class="r">{$money($eq['profit_movement'])}</td></tr>
    <tr class="total"><td>Balance at end of year</td><td class="r">{$money($eq['contributions_closing'])}</td><td class="r">{$money($eq['profit_closing'])}</td><td class="r">{$money($eq['contributions_closing'] + $eq['profit_closing'])}</td></tr>
  </table>
</div>

<div class="page">
  <h1>{$e($companyName)}</h1>
  <h2>TAX COMPUTATION</h2>
  <p class="sub">{$e($period)}</p>
  <table>
    {$row2('Profit as per Income Statement', $profitBeforeTax)}
    {$row2('Add: Back Depreciation', $depreciation)}
    {$row2('Investment made as per Section 17(1)(a)', -$num('section17_investment'))}
    <tr class="section"><td colspan="2">Less: Capital Allowances</td></tr>
    {$capitalAllowanceRows}
    {$row2('Total Capital Allowances', -$capitalAllowanceTotal)}
    {$row2('Less: Receivables & Prepayment', -$receivablesPrepayment)}
    {$row2('Insurances and warranty', -$num('insurance_warranty'))}
    <tr class="total"><td>Estimated taxable income for the year</td><td class="r">{$money($taxableIncome)}</td></tr>
    {$row2('Estimated assessable loss or profit prior year', $priorYear)}
    <tr class="total"><td>Estimated assessable loss/profit for the year</td><td class="r">{$money($currentAssessed)}</td></tr>
    <tr class="total"><td>Tax at {$taxRate}%</td><td class="r">{$money($taxDue)}</td></tr>
  </table>
</div>

<div class="page">
  <h1>{$e($companyName)}</h1>
  <h2>NOTES TO THE ANNUAL FINANCIAL STATEMENTS</h2>
  <p class="sub">{$e($period)}</p>

  <h3>1. Accounting Policies</h3>
  <p><strong>1.1 Property, plant and equipment</strong><br>{$e($policies['ppe_policy']['value_text'] ?? '')}</p>
  <p><strong>1.2 Revenue</strong><br>{$e($policies['revenue_policy']['value_text'] ?? '')}</p>
  <p><strong>1.3 Inventories</strong><br>{$e($policies['inventory_policy']['value_text'] ?? '')}</p>

  <h3>2. Members' Net Investment</h3>
  <table>
    {$row2('Balance at beginning of year', $eq['contributions_opening'] + $eq['profit_opening'])}
    {$row2('Contributions introduced', $eq['contributions_movement'])}
    {$row2('Net profit/(loss) for the year', $eq['profit_movement'])}
    <tr class="total"><td>Balance at end of year</td><td class="r">{$money($eq['contributions_closing'] + $eq['profit_closing'])}</td></tr>
  </table>

  <h3>3. Transactions With Members</h3>
  <table>{$memberRows}</table>

  <h3>4. Property, Vehicles, Plant and Equipment</h3>
  <table>
    <tr class="section"><td>Category</td><td class="r">Opening NBV</td><td class="r">Additions</td><td class="r">Disposals</td><td class="r">Depreciation</td><td class="r">Closing NBV</td></tr>
    {$ppeRows}
  </table>

  <h3>5. Interest Bearing Borrowings</h3>
  <table>{$borrowingRows}</table>

  <h3>6. Members Contributions</h3>
  <table>{$ownerRows}</table>

  <h3>7. Cash Generated From Operations</h3>
  <table>
    {$row2('Profit/(loss) before tax', $profitBeforeTax)}
    {$row2('Depreciation', $depreciation)}
    {$row2('Finance charges', $plMovement['pl_finance_cost'] ?? 0)}
    <tr class="total"><td>Cash generated from operations</td><td class="r">{$money($cashGenerated)}</td></tr>
  </table>

  <h3>8. Cash and Cash Equivalents</h3>
  <table><tr class="total"><td>Cash at bank and in hand</td><td class="r">{$money($cashClosing)}</td></tr></table>
</div>

</body></html>
HTML;
    }

    private static function row(string $label, float $amount, callable $e, callable $money): string
    {
        return '<tr><td>' . $e($label) . '</td><td class="r">' . $money($amount) . '</td></tr>';
    }

    private static function totalRow(string $label, float $amount, callable $money): string
    {
        return '<tr class="total"><td>' . htmlspecialchars($label, ENT_QUOTES) . '</td><td class="r">' . $money($amount) . '</td></tr>';
    }

    private static function css(): string
    {
        return <<<CSS
body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #222; }
.page { page-break-after: always; padding: 20px 0; }
.page:last-child { page-break-after: auto; }
h1 { font-size: 16px; margin: 0 0 2px; }
h2 { font-size: 13px; margin: 0 0 2px; color: #1F497D; }
h3 { font-size: 11px; margin: 14px 0 4px; color: #2B4575; }
.sub { font-style: italic; color: #666; margin: 0 0 12px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
td { padding: 3px 6px; border-bottom: 1px solid #eee; }
td.r { text-align: right; }
tr.section td { font-weight: bold; color: #2B4575; border-bottom: none; padding-top: 8px; }
tr.total td { font-weight: bold; border-top: 1px solid #333; border-bottom: 3px double #333; }
CSS;
    }
}
