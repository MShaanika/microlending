<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF version of the Statement of Account, for downloading and for
 * emailing as an attachment. Renders its own minimal, inline-styled HTML
 * (not the Bootstrap-based loans/statement.php view) since Dompdf's CSS
 * support doesn't cover the full framework reliably -- same reasoning
 * every other exporter in this app avoids reusing a Bootstrap view for a
 * generated document.
 */
class LoanStatementPdfExporter
{
    public static function build(array $loan, array $borrower, array $schedule, array $ledger, array $company, ?array $summary = null): string
    {
        $html = self::html($loan, $borrower, $schedule, $ledger, $company, $summary);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** The statement as HTML (public so tests can check the figures without rendering a PDF). */
    public static function html(array $loan, array $borrower, array $schedule, array $ledger, array $company, ?array $summary = null): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $money = fn ($v) => number_format((float) $v, 2);
        $s = $summary ?? LoanStatementSummary::build($loan, $schedule, $ledger);
        $sumOf = fn (string $col) => array_sum(array_map('floatval', array_column($schedule, $col)));

        $scheduleRows = '';
        foreach ($schedule as $row) {
            $scheduleRows .= '<tr>'
                . '<td>' . (int) $row['installment_no'] . '</td>'
                . '<td>' . $e($row['due_date']) . '</td>'
                . '<td class="r">' . $money($row['opening_balance']) . '</td>'
                . '<td class="r">' . $money($row['principal_due']) . '</td>'
                . '<td class="r">' . $money($row['interest_due']) . '</td>'
                . '<td class="r">' . $money($row['penalty_due']) . '</td>'
                . '<td class="r">' . $money($row['fees_due']) . '</td>'
                . '<td class="r">' . $money($row['total_due']) . '</td>'
                . '<td class="r">' . $money($row['total_paid']) . '</td>'
                . '<td class="r">' . $money($row['closing_balance']) . '</td>'
                . '<td>' . $e(schedule_status_label($row)) . '</td>'
                . '</tr>';
        }

        $ledgerRows = '';
        foreach ($ledger['events'] as $event) {
            $ledgerRows .= '<tr>'
                . '<td>' . $e($event['date'] ?: '-') . '</td>'
                . '<td>' . $e($event['type']) . '</td>'
                . '<td>' . $e($event['description']) . '</td>'
                . '<td class="r">' . ($event['debit'] > 0 ? $money($event['debit']) : '') . '</td>'
                . '<td class="r">' . ($event['credit'] > 0 ? $money($event['credit']) : '') . '</td>'
                . '<td class="r">' . $money($event['balance']) . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #222; }
            h2, h3, h4 { margin: 0 0 4px 0; }
            .muted { color: #666; }
            .header { width: 100%; margin-bottom: 16px; }
            .header td { vertical-align: top; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
            table.data th, table.data td { border: 1px solid #ccc; padding: 4px 6px; font-size: 9px; }
            table.data th { background: #f0f0f0; text-align: left; }
            .r { text-align: right; }
            .totals td { font-weight: bold; }
            .section { margin-top: 16px; }
            .footer-note { margin-top: 16px; font-size: 8px; color: #666; }
        </style></head><body>
            <table class="header"><tr>
                <td style="width:60%">
                    <h2>' . $e($company['company_name'] ?? 'Micro Lending System') . '</h2>
                    <div class="muted">' . $e($company['address'] ?? '') . '</div>
                    <div class="muted">' . $e($company['email'] ?? '') . ($company['phone'] ? ' &middot; ' . $e($company['phone']) : '') . '</div>
                    <div class="muted">Reg No: ' . $e($company['registration_no'] ?? '') . '</div>
                </td>
                <td style="width:40%; text-align:right">
                    <h3>STATEMENT OF ACCOUNT</h3>
                    <div>Loan No: <strong>' . $e($loan['loan_no']) . '</strong></div>
                    <div>Date: ' . $e(date('d M Y')) . '</div>
                </td>
            </tr></table>

            <table class="header"><tr>
                <td style="width:50%">
                    <h4>Client</h4>
                    <div>' . $e($borrower['first_name'] . ' ' . $borrower['last_name']) . '</div>
                    <div>' . $e($borrower['borrower_no']) . '</div>
                    <div>' . $e($borrower['phone'] ?: '') . '</div>
                    <div>' . $e($borrower['physical_address'] ?: '') . '</div>
                </td>
                <td style="width:50%; text-align:right">
                    <h4>Loan Summary</h4>
                    <div>Product: ' . $e($loan['product_name']) . '</div>
                    <div>' . $e($s['principal_label']) . ': ' . $money($s['principal']) . '</div>
                    ' . ($s['levy'] > 0 ? '<div>' . $e($s['levy_label']) . ': ' . $money($s['levy']) . '</div>' : '') . '
                    ' . ($s['stamp'] > 0 ? '<div>' . $e($s['stamp_label']) . ': ' . $money($s['stamp']) . '</div>' : '') . '
                    <div>' . $e($s['opening_label']) . ': ' . $money($s['opening_balance']) . '</div>
                    <div>' . $e($s['interest_label']) . ': ' . $money($s['interest']) . '</div>
                    ' . ($s['admin_fee'] > 0 ? '<div>+ Admin fee: ' . $money($s['admin_fee']) . '</div>' : '') . '
                    <div>Total payable: ' . $money($s['total_payable']) . '</div>
                    ' . ($s['penalties'] > 0 ? '<div>Penalties charged: ' . $money($s['penalties']) . '</div>' : '') . '
                    <div>Paid: ' . $money($s['paid']) . '</div>
                    <div><strong>Still to pay: ' . $money($s['outstanding']) . '</strong>' . ($s['paid_out'] ? '' : ' (not paid out yet)') . '</div>
                    ' . ($s['disbursed_on'] ? '<div>Paid out on: ' . $e($s['disbursed_on']) . '</div>' : '') . '
                    <div>Status: ' . $e($loan['loan_status']) . '</div>
                </td>
            </tr></table>

            <div class="section">
                <h4>Amortization Schedule</h4>
                <table class="data">
                    <thead><tr><th>#</th><th>Due Date</th><th>Opening Principal</th><th>Principal</th><th>Interest</th><th>Penalty</th><th>Fees</th><th>Total Due</th><th>Paid</th><th>Closing Principal</th><th>Status</th></tr></thead>
                    <tbody>' . $scheduleRows . '</tbody>
                    <tfoot><tr class="totals">
                        <td colspan="3" class="r">Total</td>
                        <td class="r">' . $money($sumOf('principal_due')) . '</td>
                        <td class="r">' . $money($sumOf('interest_due')) . '</td>
                        <td class="r">' . $money($sumOf('penalty_due')) . '</td>
                        <td class="r">' . $money($sumOf('fees_due')) . '</td>
                        <td class="r">' . $money($s['schedule_due']) . '</td>
                        <td class="r">' . $money($s['paid']) . '</td>
                        <td></td>
                        <td></td>
                    </tr>
                    <tr class="totals">
                        <td colspan="11" class="r">' . $e(LoanStatementSummary::stillToPayLine($s)) . '</td>
                    </tr></tfoot>
                </table>
                <p class="footer-note">' . $e(LoanStatementSummary::scheduleNote($s)) . '</p>
            </div>

            <div class="section">
                <h4>Loan Statement (Transaction History)</h4>
                ' . (!empty($ledger['notice']) ? '<p class="muted">' . $e($ledger['notice']) . '</p>' : '') . '
                <table class="data">
                    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
                    <tbody>' . $ledgerRows . '</tbody>
                    <tfoot><tr class="totals">
                        <td colspan="5" class="r">Closing Balance</td>
                        <td class="r">' . $money($ledger['closing_balance']) . '</td>
                    </tr></tfoot>
                </table>
            </div>

            <p class="footer-note">This is a system-generated statement and does not require a signature.</p>
        </body></html>';
    }
}
