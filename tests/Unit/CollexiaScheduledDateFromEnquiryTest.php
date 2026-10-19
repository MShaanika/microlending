<?php

namespace Tests\Unit;

use App\Services\CollexiaMandateStatusSyncService;
use PHPUnit\Framework\TestCase;

class CollexiaScheduledDateFromEnquiryTest extends TestCase
{
    private function response(array $installments): array
    {
        return ['mandate' => [['status' => 2, 'installments' => $installments]]];
    }

    public function testReadsTheSingleInstallmentsScheduleDate(): void
    {
        $this->assertSame('2027-02-19', CollexiaMandateStatusSyncService::scheduledDateFromEnquiry($this->response([['scheduleDate' => '20270219']])));
    }

    public function testMoreThanOneInstallmentIsAmbiguousSoNull(): void
    {
        $this->assertNull(CollexiaMandateStatusSyncService::scheduledDateFromEnquiry($this->response([['scheduleDate' => '20270219'], ['scheduleDate' => '20270319']])));
    }

    public function testMissingBlankOrInvalidDatesAreNull(): void
    {
        $this->assertNull(CollexiaMandateStatusSyncService::scheduledDateFromEnquiry($this->response([])));
        $this->assertNull(CollexiaMandateStatusSyncService::scheduledDateFromEnquiry($this->response([['scheduleDate' => '']])));
        $this->assertNull(CollexiaMandateStatusSyncService::scheduledDateFromEnquiry($this->response([['scheduleDate' => '20271399']])));
        $this->assertNull(CollexiaMandateStatusSyncService::scheduledDateFromEnquiry(['responseCode' => '0']));
    }
}
