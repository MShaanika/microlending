<?php

namespace Tests\Unit;

use App\Services\CplExporter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CplExporter::borrowerFields() -- the one public method that needs
 * no database access (it maps a flat row array onto CPL field names with no
 * DB calls), so it can be exercised the same DB-free way as
 * CplRecordBuilderTest. CplExporter's constructor is deliberately DB-free
 * too (CplSetting is instantiated lazily -- see the class docblock), so
 * `new CplExporter()` alone is safe here.
 *
 * Everything that needs loan_schedules/payments/loan_reschedules
 * (loanFields(), statusCode(), paymentType()) is not covered here --
 * exercising those requires a real database, same constraint documented on
 * CreditinfoBureauClientTest for the CBS module.
 */
class CplExporterTest extends TestCase
{
    private CplExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new CplExporter();
    }

    public function testNaIdIsUsedWhenIdNumberIsThirteenDigits(): void
    {
        $fields = $this->exporter->borrowerFields(['id_number' => '7408285107012', 'passport_no' => null]);
        $this->assertSame('7408285107012', $fields['na_id']);
        $this->assertSame('', $fields['non_na_id']);
    }

    public function testPassportNoIsUsedForNonNaIdentificationNotIdNumber(): void
    {
        // A non-NA applicant: id_number holds a non-13-digit value (or is
        // blank), the dedicated passport_no column holds the real
        // identification -- this is the bug fix: passport_no must be read,
        // not silently ignored in favour of whatever sits in id_number.
        $fields = $this->exporter->borrowerFields(['id_number' => '', 'passport_no' => 'ZW1234567']);
        $this->assertSame('ZW1234567', $fields['non_na_id']);
        $this->assertSame('', $fields['na_id']);
    }

    public function testIdNumberIsUsedAsLastResortWhenNoPassportNoIsCaptured(): void
    {
        $fields = $this->exporter->borrowerFields(['id_number' => 'A1B2C3', 'passport_no' => null]);
        $this->assertSame('A1B2C3', $fields['non_na_id']);
    }

    public function testIncomeUsesGrossSalaryNotNetSalary(): void
    {
        $fields = $this->exporter->borrowerFields(['gross_salary' => 8500.75, 'net_salary' => 6200.10]);
        // CPLv1-1.pdf p.34, field 50 INCOME: "Gross income of the consumer" --
        // net_salary must never be the source, even if both are present.
        $this->assertSame(8501, $fields['income']);
    }

    public function testGenderMapsToSingleLetterOrBlankForOther(): void
    {
        $this->assertSame('M', $this->exporter->borrowerFields(['gender' => 'Male'])['gender']);
        $this->assertSame('F', $this->exporter->borrowerFields(['gender' => 'Female'])['gender']);
        $this->assertSame('', $this->exporter->borrowerFields(['gender' => 'Other'])['gender']);
        $this->assertSame('', $this->exporter->borrowerFields(['gender' => null])['gender']);
    }

    public function testHomeTelephoneAndOccupationAreMappedFromTheirSourceColumns(): void
    {
        $fields = $this->exporter->borrowerFields(['home_telephone' => '061123456', 'job_title' => 'Teacher']);
        $this->assertSame('061123456', $fields['home_telephone']);
        $this->assertSame('Teacher', $fields['occupation']);
    }
}
