<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export for the Loan Disbursement and Bad Debt Register -- brand
 * styled via ExcelBrandStyle (not pinned to a real regulatory filing like
 * the MLR/AFS exporters, since this report is a system-generated register
 * modelled on the client's historical Excel layout, not a submission to a
 * regulator). $sections is the section-keyed array
 * QuarterlyReportController already builds (groupLoanDisbursementSections()).
 */
class LoanDisbursementReportExcelExporter
{
    private const SECTIONS = [
        'PAY_10' => '10th Pay Date Clients',
        'PAY_15' => '15th Pay Date Clients',
        'PAY_20' => '20th Pay Date Clients',
        'PAY_25' => '25th Pay Date Clients',
        'PAY_EOM' => 'End of the Month Pay Date Clients',
    ];

    private const HEADERS = [
        'Date', 'Client No.', 'First Name', 'Surname', 'Identity No.', 'Contact No.',
        'Gross Salary', 'Male', 'Female', 'Borrowed (N$)', 'Interest @ 30% (N$)',
        'Total Repayment', 'Paid', 'Bad Debt Written Off',
    ];

    private array $report;
    private array $sections;

    public function __construct(array $report, array $sections)
    {
        $this->report = $report;
        $this->sections = $sections;
    }

    public function build(): Spreadsheet
    {
        $company = (new Company())->primary() ?: [];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Loan Disbursement Register');

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setWidth(15);
        }

        $periodLabel = date('F Y', strtotime($this->report['period_start']));

        $row = 1;
        $sheet->mergeCells("A{$row}:N{$row}");
        $sheet->setCellValue("A{$row}", $company['company_name'] ?? '');
        ExcelBrandStyle::title($sheet, "A{$row}:N{$row}");
        $row++;

        $sheet->mergeCells("A{$row}:N{$row}");
        $sheet->setCellValue("A{$row}", 'Loan Disbursement and Bad Debt Register as per Segmented -- ' . $periodLabel);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        $grand = ['male' => 0.0, 'female' => 0.0, 'borrowed' => 0.0, 'interest' => 0.0, 'repayment' => 0.0, 'paid' => 0.0, 'bad_debt' => 0.0, 'count' => 0];

        foreach (self::SECTIONS as $code => $label) {
            $rows = $this->sections[$code] ?? [];

            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;

            $sheet->fromArray(self::HEADERS, null, "A{$row}");
            ExcelBrandStyle::header($sheet, "A{$row}:N{$row}");
            $row++;

            $subtotal = ['male' => 0.0, 'female' => 0.0, 'borrowed' => 0.0, 'interest' => 0.0, 'repayment' => 0.0, 'paid' => 0.0, 'bad_debt' => 0.0];
            foreach ($rows as $r) {
                $male = $r['gender'] === 'Male' ? (float) $r['borrowed_amount'] : null;
                $female = $r['gender'] === 'Female' ? (float) $r['borrowed_amount'] : null;

                $sheet->setCellValue("A{$row}", date('Y-m-d', strtotime($r['disbursement_date'])));
                $sheet->setCellValue("B{$row}", $r['client_no']);
                $sheet->setCellValue("C{$row}", $r['first_name']);
                $sheet->setCellValue("D{$row}", $r['surname']);
                $sheet->setCellValue("E{$row}", $r['id_number']);
                $sheet->setCellValue("F{$row}", $r['contact_number']);
                $this->amount($sheet, "G{$row}", $r['gross_salary']);
                if ($male !== null) {
                    $this->amount($sheet, "H{$row}", $male);
                }
                if ($female !== null) {
                    $this->amount($sheet, "I{$row}", $female);
                }
                $this->amount($sheet, "J{$row}", $r['borrowed_amount']);
                $this->amount($sheet, "K{$row}", $r['interest_amount']);
                $this->amount($sheet, "L{$row}", $r['total_repayment']);
                $this->amount($sheet, "M{$row}", $r['paid_amount']);
                $this->amount($sheet, "N{$row}", $r['bad_debt_written_off']);
                ExcelBrandStyle::border($sheet, "A{$row}:N{$row}");

                $subtotal['male'] += $male ?? 0.0;
                $subtotal['female'] += $female ?? 0.0;
                $subtotal['borrowed'] += (float) $r['borrowed_amount'];
                $subtotal['interest'] += (float) $r['interest_amount'];
                $subtotal['repayment'] += (float) $r['total_repayment'];
                $subtotal['paid'] += (float) $r['paid_amount'];
                $subtotal['bad_debt'] += (float) $r['bad_debt_written_off'];
                $row++;
            }

            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'Subtotal (' . count($rows) . ')');
            $this->amount($sheet, "H{$row}", $subtotal['male']);
            $this->amount($sheet, "I{$row}", $subtotal['female']);
            $this->amount($sheet, "J{$row}", $subtotal['borrowed']);
            $this->amount($sheet, "K{$row}", $subtotal['interest']);
            $this->amount($sheet, "L{$row}", $subtotal['repayment']);
            $this->amount($sheet, "M{$row}", $subtotal['paid']);
            $this->amount($sheet, "N{$row}", $subtotal['bad_debt']);
            ExcelBrandStyle::totals($sheet, "A{$row}:N{$row}");
            $row += 2;

            foreach ($subtotal as $k => $v) {
                $grand[$k] += $v;
            }
            $grand['count'] += count($rows);
        }

        $sheet->setCellValue("A{$row}", 'GRAND TOTAL (' . $grand['count'] . ' loans)');
        $sheet->mergeCells("A{$row}:F{$row}");
        $this->amount($sheet, "H{$row}", $grand['male']);
        $this->amount($sheet, "I{$row}", $grand['female']);
        $this->amount($sheet, "J{$row}", $grand['borrowed']);
        $this->amount($sheet, "K{$row}", $grand['interest']);
        $this->amount($sheet, "L{$row}", $grand['repayment']);
        $this->amount($sheet, "M{$row}", $grand['paid']);
        $this->amount($sheet, "N{$row}", $grand['bad_debt']);
        ExcelBrandStyle::totals($sheet, "A{$row}:N{$row}");
        $row += 2;

        $levyRate = (new \App\Models\StatutoryCharge())->namfisaLevyRateAsOf($this->report['period_end']);
        $expenditure = (float) ($this->report['total_expenditure'] ?? 0);
        $levy = (float) ($this->report['total_namfisa_levy'] ?? 0);

        $sheet->setCellValue("A{$row}", 'EXPENDITURE FOR MONTH (N$):');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $this->amount($sheet, "C{$row}", $expenditure);
        $row++;
        $sheet->setCellValue("A{$row}", 'LEVY DUE TO NAMFISA ' . number_format($levyRate, 2) . '% OF DISBURSED LOANS (N$):');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $this->amount($sheet, "C{$row}", $levy);
        $row++;
        $sheet->setCellValue("A{$row}", 'TOTAL PAYABLE (INCL. LEVY) (N$):');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $this->amount($sheet, "C{$row}", $grand['repayment'] + $levy);

        return $spreadsheet;
    }

    private function amount($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, round((float) $value, 2));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
