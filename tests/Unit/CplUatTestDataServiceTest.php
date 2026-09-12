<?php

namespace Tests\Unit;

use App\Services\CplUatTestDataService;
use App\Services\CplValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the synthetic UAT sign-off test records are actually
 * spec-valid (via the real CplValidationService, not a hand-picked
 * subset of checks) and use only Creditinfo's approved test IDs -- if
 * this service ever drifted into producing invalid data, a sign-off test
 * built from it would fail for the wrong reason.
 */
class CplUatTestDataServiceTest extends TestCase
{
    private CplUatTestDataService $service;
    private CplValidationService $validator;

    protected function setUp(): void
    {
        $this->service = new CplUatTestDataService();
        $this->validator = new CplValidationService();
    }

    public function testMonthlyTestRecordHasNoBlockingValidationErrors(): void
    {
        $record = $this->service->monthlyTestRecord('2026-09-30');
        $issues = $this->validator->validate($record);
        $blocking = array_filter($issues, fn ($i) => $i['severity'] === 'BLOCKING');
        $this->assertSame([], array_values($blocking));
    }

    public function testDailyTestRecordsHaveNoBlockingValidationErrors(): void
    {
        [$registration, $closure] = $this->service->dailyTestRecords('2026-09-15');

        $this->assertSame([], array_values(array_filter($this->validator->validate($registration), fn ($i) => $i['severity'] === 'BLOCKING')));
        $this->assertSame([], array_values(array_filter($this->validator->validate($closure), fn ($i) => $i['severity'] === 'BLOCKING')));
    }

    public function testDailyRecordsUseRAndCDataIndicatorsNotD(): void
    {
        [$registration, $closure] = $this->service->dailyTestRecords('2026-09-15');
        $this->assertSame('R', $registration['data']);
        $this->assertSame('C', $closure['data']);
    }

    public function testEveryRecordUsesOneOfTheThreeCreditinfoApprovedUatIds(): void
    {
        $monthly = $this->service->monthlyTestRecord('2026-09-30');
        [$registration, $closure] = $this->service->dailyTestRecords('2026-09-15');

        foreach ([$monthly, $registration, $closure] as $record) {
            $this->assertContains($record['na_id'], CplUatTestDataService::UAT_TEST_IDS);
        }
    }

    public function testSyntheticRecordsAreClearlyLabelledAsTestData(): void
    {
        $monthly = $this->service->monthlyTestRecord('2026-09-30');
        $this->assertStringContainsString('UATTEST', $monthly['account_no']);
        $this->assertStringContainsString('TEST', $monthly['surname']);
    }
}
