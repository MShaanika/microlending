<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\LoanApplication;
use App\Services\ApplicationPdfFillService;
use App\Services\DocumentGenerationService;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * The Affordability Assessment and the Loan Agreement carry every detail the applicant submitted -- the mapped columns and the extra_data
 * the intake keeps -- and the signature images saved with the application. Throw-away rows and files, removed afterwards.
 */
class ApplicationPdfFillTest extends TestCase
{
    private \PDO $db;
    private int $applicationId = 0;
    private array $files = [];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $extra = [
            'marital_status' => 'Married', 'postal_address' => 'PO Box 77 Oranjemund', 'job_title' => 'Site Supervisor',
            'company_tel' => '+264 63 123456', 'employer_address' => '12 Mine Road', 'bank_branch_name' => 'Oranjemund Branch',
            'account_type' => 'Cheque', 'account_no' => 'ACC-445566',
            'commission' => '250', 'pension' => '100', 'business_income' => '300',
            'groceries' => '1500', 'school_fees' => '800', 'transport' => '600',
            'home_loan' => '2000', 'home_rental' => '1000', 'credit_card' => '300', 'personal_loans' => '450', 'education_loan' => '200',
            'insurance' => '350', 'car_payments' => '1200', 'cell_phone' => '150', 'other_credit' => '75',
            'ref1_name' => 'Anna Reference', 'ref1_tel' => '0811111111', 'ref2_name' => 'Ben Reference', 'ref2_tel' => '0822222222',
            'finance_charges_amount' => '900', 'total_repayable' => '3900', 'installment_amount' => '1300',
            'first_due_date' => '2026-12-25', 'last_due_date' => '2027-02-25',
            'signing_place' => 'Oranjemund', 'signing_day' => '29', 'signing_month' => 'October', 'signing_year' => '2026',
        ];
        $this->db->prepare(
            "INSERT INTO loan_applications (application_no, application_source, application_type, requested_amount, requested_term_months, applicant_first_name, applicant_last_name,
                applicant_id_number, applicant_phone, applicant_email, applicant_address, employer_name, employee_no, gross_salary, net_salary, bank_name, bank_account_number,
                status, extra_data)
             VALUES (?, 'Online', 'New Loan', 3000, 3, 'Testa', 'Zambezi', '85010100123', '+264811234567', 'testa@example.test', '5 Desert Street', 'Rossing Mine', 'EMP-889', 12000, 9000,
                'FNB', '62012345678', 'Submitted', ?)"
        )->execute(['PHPUNIT-' . uniqid(), json_encode($extra)]);
        $this->applicationId = (int) $this->db->lastInsertId();

        $dir = STORAGE_PATH . '/uploads/loan_applications/phpunit_' . $this->applicationId;
        mkdir($dir, 0755, true);
        foreach (['Signature - Borrower' => 'borrower', 'Signature - Witness' => 'witness'] as $type => $name) {
            file_put_contents($dir . '/' . $name . '.png', base64_decode(self::PNG));
            $this->db->prepare("INSERT INTO loan_application_documents (application_id, document_type, document_name, file_path, status) VALUES (?, ?, ?, ?, 'Pending')")
                ->execute([$this->applicationId, $type, $type, 'uploads/loan_applications/phpunit_' . $this->applicationId . '/' . $name . '.png']);
        }
        $this->files[] = $dir;
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM loan_application_documents WHERE application_id = ?')->execute([$this->applicationId]);
        $this->db->prepare('DELETE FROM loan_applications WHERE id = ?')->execute([$this->applicationId]);
        foreach ($this->files as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function text(string $relativePath): string
    {
        $full = STORAGE_PATH . '/' . $relativePath;
        $this->files[] = $full;
        return (new Parser())->parseFile($full)->getText();
    }

    private function application(): array
    {
        return (new LoanApplication())->find($this->applicationId);
    }

    public function testTheAffordabilityAssessmentCarriesEverySubmittedFigure(): void
    {
        $text = $this->text(ApplicationPdfFillService::affordability($this->application(), 'DOC-PHPUNIT-A'));

        foreach (['Testa Zambezi', 'ACC-445566'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        // income: net salary 9,000 + 250 + 100 + 300 = 9,650
        foreach (['9,000.00', '250.00', '100.00', '300.00', '9,650.00'] as $expected) {
            $this->assertStringContainsString($expected, $text, "income figure $expected");
        }
        // living expenses 1,500 + 800 + 600 = 2,900; contractual 5,725 (2,000+1,000+300+450+200+350+1,200+150+75)
        foreach (['1,500.00', '800.00', '600.00', '2,900.00', '2,000.00', '1,000.00', '450.00', '200.00', '350.00', '1,200.00', '150.00', '75.00', '5,725.00'] as $expected) {
            $this->assertStringContainsString($expected, $text, "expense figure $expected");
        }
    }

    public function testTheLoanAgreementCarriesEveryPersonalAndLoanDetail(): void
    {
        $text = $this->text(ApplicationPdfFillService::loanAgreement($this->application(), 'DOC-PHPUNIT-L'));

        $expected = [
            'Testa Zambezi', 'PO Box 77 Oranjemund', '+264811234567', 'testa@example.test', '5 Desert Street', 'Married', '85010100123',
            'Site Supervisor', '+264 63 123456', 'Rossing Mine', '12 Mine Road', 'EMP-889', 'FNB', 'Oranjemund Branch', '62012345678', 'Cheque',
            'Anna Reference', '0811111111', 'Ben Reference', '0822222222',
            '3,000.00', '900.00', '3,900.00', '1,300.00', '2026-12-25', '2027-02-25', 'Monthly', 'Oranjemund', 'October', '2026',
        ];
        foreach ($expected as $value) {
            $this->assertStringContainsString($value, $text, "agreement detail $value");
        }
    }

    public function testTheEmployersNumberIsReadFromEitherKeyTheIntakeUses(): void
    {
        $extra = json_decode((string) $this->application()['extra_data'], true);
        unset($extra['company_tel']);
        $extra['employer_phone'] = '+264 64 998877';
        $this->db->prepare('UPDATE loan_applications SET extra_data = ? WHERE id = ?')->execute([json_encode($extra), $this->applicationId]);

        $this->assertSame('+264 64 998877', ApplicationPdfFillService::formData($this->application())['company_tel']);
        $this->assertStringContainsString('+264 64 998877', $this->text(ApplicationPdfFillService::loanAgreement($this->application(), 'DOC-PHPUNIT-E')));
    }

    public function testLoanTermsThatWereNeverStoredAreWorkedOutWithTheFormsOwnFormula(): void
    {
        $extra = json_decode((string) $this->application()['extra_data'], true);
        unset($extra['finance_charges_amount'], $extra['total_repayable'], $extra['installment_amount']);
        $this->db->prepare('UPDATE loan_applications SET extra_data = ? WHERE id = ?')->execute([json_encode($extra), $this->applicationId]);

        // 3,000 -> base 3,000 + 1.03% + 5 = 3,035.90; 30% interest = 910.77; total 3,946.67; over 3 months = 1,315.56
        $f = ApplicationPdfFillService::formData($this->application());
        $this->assertSame('910.77', $f['finance_charges_amount']);
        $this->assertSame('3946.67', $f['total_repayable']);
        $this->assertSame('1315.56', $f['installment_amount']);
        $this->assertSame('30', $f['finance_rate']);

        $text = $this->text(ApplicationPdfFillService::loanAgreement($this->application(), 'DOC-PHPUNIT-W'));
        foreach (['910.77', '3,946.67', '1,315.56'] as $value) {
            $this->assertStringContainsString($value, $text, "worked-out term $value");
        }
        $this->assertStringNotContainsString('N$ N$', $text, 'the form already prints N$, so it is not written twice');
    }

    public function testStoredLoanTermsAreNeverReplacedByTheCalculation(): void
    {
        $f = ApplicationPdfFillService::formData($this->application());
        $this->assertSame('900', $f['finance_charges_amount']);
        $this->assertSame('3900', $f['total_repayable']);
        $this->assertSame('1300', $f['installment_amount']);
    }

    public function testSignaturesAreFoundAndNothingIsInventedWhenTheyAreMissing(): void
    {
        $app = $this->application();
        $this->assertNotNull(ApplicationPdfFillService::signatureFile($app, 'Signature - Borrower'));
        $this->assertNotNull(ApplicationPdfFillService::signatureFile($app, 'Signature - Witness'));

        $this->db->prepare('DELETE FROM loan_application_documents WHERE application_id = ?')->execute([$this->applicationId]);
        $this->assertNull(ApplicationPdfFillService::signatureFile($app, 'Signature - Borrower'));
        // still generates, just without the images
        $this->assertStringContainsString('Testa Zambezi', $this->text(ApplicationPdfFillService::loanAgreement($app, 'DOC-PHPUNIT-N')));
    }

    public function testBlankDetailsStayBlankInsteadOfFailing(): void
    {
        $this->db->prepare('UPDATE loan_applications SET extra_data = NULL WHERE id = ?')->execute([$this->applicationId]);
        $text = $this->text(ApplicationPdfFillService::affordability($this->application(), 'DOC-PHPUNIT-B'));
        $this->assertStringContainsString('Testa Zambezi', $text);
        $this->assertStringContainsString('9,000.00', $text, 'salary still comes from the application itself');
    }

    public function testTheConsentFormGetsTheApplicantsSignatureImage(): void
    {
        $templateId = (int) $this->db->query("SELECT id FROM document_templates WHERE template_code = 'APPLICATION_CONSENT'")->fetchColumn();
        $this->assertGreaterThan(0, $templateId, 'the consent template must exist');

        $path = DocumentGenerationService::generate(['template_id' => $templateId, 'document_no' => 'DOC-PHPUNIT-C', 'borrower_id' => null, 'loan_id' => null, 'refund_claim_id' => null, 'application_id' => $this->applicationId]);
        $this->files[] = STORAGE_PATH . '/' . $path;

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(STORAGE_PATH . '/' . $path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $images = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'word/media/')) {
                $images++;
            }
        }
        $zip->close();

        $this->assertStringContainsString('Testa Zambezi', $xml, 'the applicant name is filled in');
        $this->assertStringNotContainsString('${signature}', $xml, 'the signature placeholder is replaced');
        $this->assertGreaterThanOrEqual(1, $images, 'with the signature image');
    }
}
