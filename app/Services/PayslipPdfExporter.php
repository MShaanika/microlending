<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF version of the payslip, for downloading. Renders its own minimal,
 * inline-styled HTML (not the Bootstrap-based hrm/payrolls/payslip.php
 * view) -- same reasoning as LoanStatementPdfExporter: Dompdf's CSS
 * support doesn't cover the full framework reliably.
 */
class PayslipPdfExporter
{
    public static function build(array $payroll, array $entry, array $allowances, array $deductions, array $staffLoans, array $company): string
    {
        $html = self::html($payroll, $entry, $allowances, $deductions, $staffLoans, $company);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private static function html(array $payroll, array $entry, array $allowances, array $deductions, array $staffLoans, array $company): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $money = fn ($v) => number_format((float) $v, 2);

        $earningsRows = '<tr><td>Basic Salary</td><td class="amt">' . $money($entry['basic_salary']) . '</td></tr>';
        foreach ($allowances as $label => $amount) {
            $earningsRows .= '<tr><td>' . $e($label) . '</td><td class="amt">' . $money($amount) . '</td></tr>';
        }
        if ((float) $entry['overtime_amount'] > 0) {
            $earningsRows .= '<tr><td>Overtime (' . number_format((float) $entry['overtime_hours'], 2) . ' hrs)</td><td class="amt">' . $money($entry['overtime_amount']) . '</td></tr>';
        }

        $deductionRows = '';
        foreach ($deductions as $label => $amount) {
            $deductionRows .= '<tr><td>' . $e($label) . '</td><td class="amt">' . $money($amount) . '</td></tr>';
        }
        if ((float) $entry['half_day_deduction'] > 0) {
            $deductionRows .= '<tr><td>Half Day Deduction (' . number_format((float) $entry['half_days'], 1) . ')</td><td class="amt">' . $money($entry['half_day_deduction']) . '</td></tr>';
        }
        if ((float) $entry['absent_day_deduction'] > 0) {
            $deductionRows .= '<tr><td>Absent Day Deduction (' . number_format((float) $entry['absent_days'], 0) . ')</td><td class="amt">' . $money($entry['absent_day_deduction']) . '</td></tr>';
        }
        if ((float) $entry['unpaid_leave_deduction'] > 0) {
            $deductionRows .= '<tr><td>Unpaid Leave Deduction (' . number_format((float) $entry['unpaid_leave_days'], 0) . ')</td><td class="amt">' . $money($entry['unpaid_leave_deduction']) . '</td></tr>';
        }
        foreach ($staffLoans as $label => $amount) {
            $deductionRows .= '<tr><td>Staff Loan: ' . $e($label) . '</td><td class="amt">' . $money($amount) . '</td></tr>';
        }
        if ($deductionRows === '') {
            $deductionRows = '<tr><td colspan="2" class="muted">No deductions.</td></tr>';
        }

        $totalDeductions = (float) $entry['total_deductions'] + (float) $entry['half_day_deduction'] + (float) $entry['absent_day_deduction'] + (float) $entry['unpaid_leave_deduction'] + (float) $entry['total_staff_loans'];

        $paidTo = ($entry['bank_name'] || $entry['account_number'])
            ? '<p class="muted">Paid to: ' . $e($entry['bank_name'] ?? '') . ' ' . $e($entry['account_number'] ?? '') . '</p>'
            : '';

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; }
            h2, h3 { margin: 0; text-align: center; }
            .muted { color: #777; }
            .center { text-align: center; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            td, th { padding: 4px 6px; border: 1px solid #ccc; }
            .amt { text-align: right; }
            .no-border td { border: none; padding: 1px 0; }
            .totals td { font-weight: bold; }
            .netpay { font-size: 15px; font-weight: bold; margin-top: 10px; }
            .cols { width: 100%; }
            .cols td { border: none; vertical-align: top; padding: 0 8px; }
            .section-title { text-transform: uppercase; color: #777; font-size: 10px; margin: 10px 0 2px; }
            .sig { margin-top: 40px; }
        </style></head><body>
            <div class="center">
                <h2>' . $e($company['company_name'] ?? '') . '</h2>
                <p class="muted">' . $e($company['physical_address'] ?? '') . '</p>
                <h3>PAYSLIP</h3>
                <p class="muted">' . $e(date('d M Y', strtotime($payroll['pay_period_start']))) . ' &ndash; ' . $e(date('d M Y', strtotime($payroll['pay_period_end']))) . '</p>
            </div>

            <table class="no-border cols"><tr>
                <td style="width:50%">
                    <p><strong>Employee Name:</strong> ' . $e($entry['employee_name']) . '</p>
                    <p><strong>Employee No.:</strong> ' . $e($entry['employee_no']) . '</p>
                    <p><strong>Department:</strong> ' . $e($entry['department_name'] ?? '-') . '</p>
                </td>
                <td style="width:50%; text-align:right">
                    <p><strong>Payroll Run:</strong> ' . $e($payroll['title']) . '</p>
                    <p><strong>Pay Date:</strong> ' . ($payroll['pay_date'] ? $e(date('d M Y', strtotime($payroll['pay_date']))) : '-') . '</p>
                    <p><strong>Status:</strong> ' . $e($entry['status']) . '</p>
                </td>
            </tr></table>

            <table class="cols"><tr>
                <td style="width:50%">
                    <div class="section-title">Earnings</div>
                    <table>
                        ' . $earningsRows . '
                        <tr class="totals"><td>Gross Pay</td><td class="amt">' . $money($entry['gross_pay']) . '</td></tr>
                    </table>
                </td>
                <td style="width:50%">
                    <div class="section-title">Deductions</div>
                    <table>
                        ' . $deductionRows . '
                        <tr class="totals"><td>Total Deductions</td><td class="amt">' . $money($totalDeductions) . '</td></tr>
                    </table>
                </td>
            </tr></table>

            <table><tr class="totals"><td>Net Pay</td><td class="amt netpay">' . $money($entry['net_pay']) . '</td></tr></table>

            ' . $paidTo . '

            <table class="no-border cols sig"><tr>
                <td style="width:50%">Employee Signature: ________________________</td>
                <td style="width:50%; text-align:right">Authorised Signature: ________________________</td>
            </tr></table>
        </body></html>';
    }
}
