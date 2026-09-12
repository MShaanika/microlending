<?php

namespace Tests\Unit;

use App\Services\CplReadinessService;
use PHPUnit\Framework\TestCase;

class CplReadinessServiceTest extends TestCase
{
    private CplReadinessService $service;

    protected function setUp(): void
    {
        $this->service = new CplReadinessService();
    }

    private function completeApplication(): array
    {
        return [
            'applicant_first_name' => 'Petrus',
            'applicant_last_name' => 'Nangolo',
            'applicant_id_number' => '7408285107012',
            'applicant_phone' => '+264811234567',
            'employer_name' => 'Solid Desert cc',
            'gross_salary' => 8500,
            'extra_data' => json_encode([
                'dob' => '1974-08-28',
                'residential_line1' => '12 Independence Ave',
                'residential_line3' => 'Windhoek',
                'residential_ownership' => 'T',
                'income_frequency' => 'M',
            ]),
        ];
    }

    public function testFullyCapturedApplicationIsReadyOnEveryDimension(): void
    {
        $result = $this->service->assess($this->completeApplication());

        $this->assertTrue($result['identity']);
        $this->assertTrue($result['date_of_birth']);
        $this->assertTrue($result['contact']);
        $this->assertTrue($result['address']);
        $this->assertTrue($result['employment']);
        $this->assertTrue($result['income']);
        $this->assertTrue($result['cpl_ready']);
        $this->assertSame([], $result['missing']);
    }

    public function testMissingDateOfBirthMakesCplNotReadyAndIsListed(): void
    {
        $application = $this->completeApplication();
        $extra = json_decode($application['extra_data'], true);
        unset($extra['dob']);
        $application['extra_data'] = json_encode($extra);

        $result = $this->service->assess($application);

        $this->assertFalse($result['date_of_birth']);
        $this->assertFalse($result['cpl_ready']);
        $this->assertContains('Date of Birth', $result['missing']);
        // Everything else must remain unaffected by the one missing field.
        $this->assertTrue($result['identity']);
        $this->assertTrue($result['income']);
    }

    public function testPassportNoSatisfiesIdentityWhenNoNamibianIdIsCaptured(): void
    {
        $application = $this->completeApplication();
        $application['applicant_id_number'] = null;
        $extra = json_decode($application['extra_data'], true);
        $extra['passport_no'] = 'ZW1234567';
        $application['extra_data'] = json_encode($extra);

        $this->assertTrue($this->service->assess($application)['identity']);
    }

    public function testIncomeRequiresBothGrossSalaryAndFrequency(): void
    {
        $application = $this->completeApplication();
        $extra = json_decode($application['extra_data'], true);
        unset($extra['income_frequency']);
        $application['extra_data'] = json_encode($extra);

        $this->assertFalse($this->service->assess($application)['income']);

        $application2 = $this->completeApplication();
        $application2['gross_salary'] = 0;
        $this->assertFalse($this->service->assess($application2)['income']);
    }

    public function testAddressRequiresStructuredLinesAndOwnershipNotJustAFreeTextAddress(): void
    {
        $application = $this->completeApplication();
        $extra = json_decode($application['extra_data'], true);
        unset($extra['residential_ownership']);
        $application['extra_data'] = json_encode($extra);

        $this->assertFalse($this->service->assess($application)['address']);
    }

    public function testEntirelyEmptyApplicationIsNotReadyOnAnyDimension(): void
    {
        $result = $this->service->assess(['applicant_first_name' => '', 'applicant_last_name' => '']);

        $this->assertFalse($result['cpl_ready']);
        $this->assertCount(6, $result['missing']);
    }
}
