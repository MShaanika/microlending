<?php

namespace App\Services;

use App\Models\LoanApplication;
use setasign\Fpdi\Fpdi;

/**
 * Fills the Affordability Assessment and the Standard Loan Agreement for one loan application, exactly the way the old website
 * (apply.php) did: the official PDF forms are the background and every detail the applicant submitted is written onto them at fixed
 * positions, with the applicant's and witness's signatures placed on the signature lines.
 *
 * Everything comes from what the application stored: the mapped columns of loan_applications plus its extra_data (the fields the
 * intake keeps as extra:*), and the signature images saved with the application. Anything the applicant did not give is left blank.
 */
class ApplicationPdfFillService
{
    private const TEMPLATE_DIR = '/application_pdf_templates/';

    /** @return string storage-relative path of the filled PDF */
    public static function affordability(array $application, string $documentNo): string
    {
        $f = self::formData($application);
        $pdf = self::open('Customer_Affordability_Assesment.pdf', 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        self::write($pdf, 28, 21.5, date('Y-m-d'));
        self::write($pdf, 50, 32, $f['firstname'] . ' ' . $f['lastname']);
        self::write($pdf, 40, 42.5, $f['account_no'] !== '' ? $f['account_no'] : $f['id_number']);

        $salary = self::num($f['salary']) > 0 ? self::num($f['salary']) : self::num($f['gross_salary']);
        $commission = self::num($f['commission']);
        $pension = self::num($f['pension']);
        $business = self::num($f['business_income']);
        $totalIncome = $salary + $commission + $pension + $business;
        self::money($pdf, 100, 59, $salary);
        self::money($pdf, 100, 65, $commission);
        self::money($pdf, 100, 70, $pension);
        self::money($pdf, 100, 75, $business);
        self::money($pdf, 100, 86, $totalIncome);

        $groceries = self::num($f['groceries']);
        $school = self::num($f['school_fees']);
        $transport = self::num($f['transport']);
        self::money($pdf, 100, 107.5, $groceries);
        self::money($pdf, 100, 113.5, $school);
        self::money($pdf, 100, 119, $transport);
        self::money($pdf, 100, 130, $groceries + $school + $transport);

        $contractual = [
            157.5 => 'home_loan', 163 => 'home_rental', 168.5 => 'credit_card', 174 => 'personal_loans', 179 => 'education_loan',
            185 => 'insurance', 190 => 'car_payments', 196 => 'cell_phone', 201.5 => 'other_credit',
        ];
        $installments = 0.0;
        foreach ($contractual as $y => $key) {
            $amount = self::num($f[$key]);
            $installments += $amount;
            self::money($pdf, 100, $y, $amount);
        }
        self::money($pdf, 100, 212, $installments);

        self::signatures($pdf, $application, 20, 250, 160, 250);

        return self::save($pdf, $documentNo);
    }

    /** @return string storage-relative path of the filled PDF */
    public static function loanAgreement(array $application, string $documentNo): string
    {
        $f = self::formData($application);
        $path = STORAGE_PATH . self::TEMPLATE_DIR . 'Standard_Loan_Agreement.pdf';
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($path);

        for ($i = 1; $i <= $pageCount; $i++) {
            $pdf->AddPage();
            $pdf->useTemplate($pdf->importPage($i));
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(0, 0, 0);

            if ($i === 1) {
                self::loanAgreementPage1($pdf, $f);
            } elseif ($i === 2) {
                self::signingBlock($pdf, $f, 51.5);
                self::signatures($pdf, $application, 105, 52, 32, 52);
            } elseif ($i === 3) {
                self::signingBlock($pdf, $f, 228.5);
                self::signatures($pdf, $application, 115, 235, 30, 235);
            }
        }

        return self::save($pdf, $documentNo);
    }

    private static function loanAgreementPage1(Fpdi $pdf, array $f): void
    {
        self::write($pdf, 15, 130, 'Solid Desert Cash loan Express cc');
        self::write($pdf, 100, 130, $f['firstname'] . ' ' . $f['lastname']);

        // borrower's personal information
        self::write($pdf, 52, 143, $f['postal_address']);
        self::write($pdf, 143, 143, $f['contact_no']);
        self::write($pdf, 130, 149.5, $f['email']);
        self::write($pdf, 60, 156, $f['address']);
        self::write($pdf, 52, 162.5, $f['marital_status']);
        self::write($pdf, 120, 162.5, $f['id_number']);
        self::write($pdf, 52, 169, $f['occupation']);
        self::write($pdf, 135, 169, $f['company_tel']);
        self::write($pdf, 54, 175, $f['company_name']);
        self::write($pdf, 135, 175, $f['employer_address']);
        self::write($pdf, 60, 182, $f['employee_number']);
        self::write($pdf, 40, 189, $f['bank_name']);
        self::write($pdf, 135, 189, $f['bank_branch']);
        self::write($pdf, 60, 195, $f['account_number']);
        self::write($pdf, 143, 195, $f['account_type']);

        // references
        self::write($pdf, 54, 208, $f['ref1_name']);
        self::write($pdf, 130, 208, $f['ref1_tel']);
        self::write($pdf, 54, 214, $f['ref2_name']);
        self::write($pdf, 130, 214, $f['ref2_tel']);

        // loan details
        self::write($pdf, 35, 234, self::moneyText($f['loan_amount']));
        self::write($pdf, 67, 244, self::moneyText($f['finance_charges_amount']));
        self::write($pdf, 115, 234, self::moneyText($f['total_repayable']));
        self::write($pdf, 150, 228, self::moneyText($f['installment_amount']));
        self::write($pdf, 150, 237, $f['first_due_date']);
        self::write($pdf, 150, 245, $f['last_due_date']);
        self::write($pdf, 150, 253, $f['num_installments']);
        self::write($pdf, 150, 262, 'Monthly');
    }

    private static function signingBlock(Fpdi $pdf, array $f, float $y): void
    {
        self::write($pdf, 45, $y, $f['signing_place'] !== '' ? $f['signing_place'] : 'Windhoek');
        self::write($pdf, 77, $y, $f['signing_day'] !== '' ? $f['signing_day'] : date('j'));
        self::write($pdf, 95, $y, $f['signing_month'] !== '' ? $f['signing_month'] : date('F'));
        self::write($pdf, 118, $y, $f['signing_year'] !== '' ? $f['signing_year'] : date('Y'));
    }

    /**
     * Every submitted detail, under the field names the old form used. Mapped columns first, then the extra_data the intake kept.
     * @return array<string, string>
     */
    public static function formData(array $application): array
    {
        $extra = !empty($application['extra_data']) ? (json_decode((string) $application['extra_data'], true) ?: []) : [];
        $x = static fn (string ...$keys): string => self::firstFilled($extra, $keys);
        $col = static fn (string $key): string => trim((string) ($application[$key] ?? ''));

        return [
            'firstname' => $col('applicant_first_name'),
            'lastname' => $col('applicant_last_name'),
            'id_number' => $col('applicant_id_number'),
            'contact_no' => $col('applicant_phone'),
            'email' => $col('applicant_email'),
            'address' => $col('applicant_address'),
            'postal_address' => $x('postal_address'),
            'marital_status' => $x('marital_status'),
            'occupation' => $x('job_title', 'occupation'),
            'company_name' => $col('employer_name'),
            'company_tel' => $x('company_tel'),
            'employer_address' => $x('employer_address'),
            'employee_number' => $col('employee_no'),
            'bank_name' => $col('bank_name'),
            'bank_branch' => $x('bank_branch_name') !== '' ? $x('bank_branch_name') : $col('bank_branch_code'),
            'account_number' => $col('bank_account_number'),
            'account_type' => $x('account_type'),
            'account_no' => $x('account_no'),
            'ref1_name' => $x('ref1_name'),
            'ref1_tel' => $x('ref1_tel'),
            'ref2_name' => $x('ref2_name'),
            'ref2_tel' => $x('ref2_tel'),
            'salary' => $col('net_salary'),
            'gross_salary' => $col('gross_salary'),
            'commission' => $x('commission'),
            'pension' => $x('pension'),
            'business_income' => $x('business_income'),
            'groceries' => $x('groceries'),
            'school_fees' => $x('school_fees'),
            'transport' => $x('transport'),
            'home_loan' => $x('home_loan'),
            'home_rental' => $x('home_rental'),
            'credit_card' => $x('credit_card'),
            'personal_loans' => $x('personal_loans'),
            'education_loan' => $x('education_loan'),
            'insurance' => $x('insurance'),
            'car_payments' => $x('car_payments'),
            'cell_phone' => $x('cell_phone'),
            'other_credit' => $x('other_credit'),
            'loan_amount' => $col('requested_amount'),
            'finance_charges_amount' => $x('finance_charges_amount'),
            'total_repayable' => $x('total_repayable'),
            'installment_amount' => $x('installment_amount'),
            'first_due_date' => $x('first_due_date'),
            'last_due_date' => $x('last_due_date'),
            'num_installments' => $col('requested_term_months'),
            'signing_place' => $x('signing_place'),
            'signing_day' => $x('signing_day'),
            'signing_month' => $x('signing_month'),
            'signing_year' => $x('signing_year'),
        ];
    }

    private static function firstFilled(array $extra, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($extra[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function open(string $template, int $page): Fpdi
    {
        $pdf = new Fpdi();
        $pdf->setSourceFile(STORAGE_PATH . self::TEMPLATE_DIR . $template);
        $pdf->AddPage();
        $pdf->useTemplate($pdf->importPage($page));
        return $pdf;
    }

    private static function save(Fpdi $pdf, string $documentNo): string
    {
        $dir = STORAGE_PATH . '/generated_documents';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $documentNo) . '_' . date('YmdHis') . '.pdf';
        $pdf->Output('F', $dir . '/' . $filename);
        return 'generated_documents/' . $filename;
    }

    private static function write(Fpdi $pdf, float $x, float $y, string $text): void
    {
        if ($text === '') {
            return;
        }
        $pdf->SetXY($x, $y);
        // FPDF's built-in fonts are Windows-1252, not UTF-8
        $pdf->Write(0, (string) iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text));
    }

    private static function money(Fpdi $pdf, float $x, float $y, float $amount): void
    {
        self::write($pdf, $x, $y, 'N$ ' . number_format($amount, 2));
    }

    private static function moneyText(string $value): string
    {
        return self::num($value) > 0 ? 'N$ ' . number_format(self::num($value), 2) : '';
    }

    private static function num(string $value): float
    {
        return (float) str_replace([' ', ','], '', $value);
    }

    /** The applicant's and the witness's signature images saved with the application, placed on their lines. */
    private static function signatures(Fpdi $pdf, array $application, float $borrowerX, float $borrowerY, float $witnessX, float $witnessY): void
    {
        $borrower = self::signatureFile($application, 'Signature - Borrower');
        if ($borrower !== null) {
            $pdf->Image($borrower, $borrowerX, $borrowerY, 50, 15);
        }
        $witness = self::signatureFile($application, 'Signature - Witness');
        if ($witness !== null) {
            $pdf->Image($witness, $witnessX, $witnessY, 50, 15);
        }
    }

    /** Absolute path of the newest saved signature image of that type, or null. */
    public static function signatureFile(array $application, string $documentType): ?string
    {
        $newest = null;
        foreach ((new LoanApplication())->documents((int) $application['id']) as $doc) {
            if ($doc['document_type'] === $documentType) {
                $newest = $doc;
            }
        }
        if ($newest === null) {
            return null;
        }
        $relative = ltrim((string) $newest['file_path'], '/');
        if (str_contains($relative, '..')) {
            return null;
        }
        $path = STORAGE_PATH . '/' . $relative;
        return is_file($path) && filesize($path) > 0 ? $path : null;
    }
}
