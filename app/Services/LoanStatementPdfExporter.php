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
    public static function build(array $loan, array $borrower, array $schedule, array $ledger, array $company): string
    {
        $html = self::html($loan, $borrower, $schedule, $ledger, $company);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private static function html(array $loan, array $borrower, array $schedule, array $ledger, array $company): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $money = fn ($v) => number_format((float) $v, 2);

        $totalPaid = array_sum(array_column($schedule, 'total_paid'));
        $totalDue = array_sum(array_column($schedule, 'total_due'));
        $balance = $totalDue - $totalPaid;

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
                . '<td>' . $e($row['status']) . '</td>'
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
                    <h4>Borrower</h4>
                    <div>' . $e($borrower['first_name'] . ' ' . $borrower['last_name']) . '</div>
                    <div>' . $e($borrower['borrower_no']) . '</div>
                    <div>' . $e($borrower['phone'] ?: '') . '</div>
                    <div>' . $e($borrower['physical_address'] ?: '') . '</div>
                </td>
                <td style="width:50%; text-align:right">
                    <h4>Loan Summary</h4>
                    <div>Product: ' . $e($loan['product_name']) . '</div>
                    <div>Principal: ' . $money($loan['principal_amount']) . '</div>
                    <div>Opening Balance: ' . $money($loan['principal_amount']) . '</div>
                    <div>Total Payable: ' . $money($loan['total_payable']) . '</div>
                    <div>Status: ' . $e($loan['loan_status']) . '</div>
                </td>
            </tr></table>

            <div class="section">
                <h4>Amortization Schedule</h4>
                <table class="data">
                    <thead><tr><th>#</th><th>Due Date</th><th>Opening</th><th>Principal</th><th>Interest</th><th>Penalty</th><th>Fees</th><th>Total Due</th><th>Paid</th><th>Closing</th><th>Status</th></tr></thead>
                    <tbody>' . $scheduleRows . '</tbody>
                    <tfoot><tr class="totals">
                        <td colspan="7" class="r">Total</td>
                        <td class="r">' . $money($totalDue) . '</td>
                        <td class="r">' . $money($totalPaid) . '</td>
                        <td class="r">' . $money($balance) . '</td>
                        <td></td>
                    </tr></tfoot>
                </table>
                <p class="footer-note">NAMFISA Levy and Duty Stamp are statutory charges remitted to the relevant Namibian authorities and are included in your total repayable amount.</p>
            </div>

            <div class="section">
                <h4>Loan Statement (Transaction History)</h4>
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
