<?php

namespace Tests\Unit;

use App\Services\CreditinfoPublicDefaultsClient;
use App\Services\CreditinfoPublicDefaultsNotConfiguredException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Public Defaults gateway placeholder never proceeds past a
 * controlled exception -- no network call, no fake response, regardless of
 * what payload is passed in (item 13). Unlike the CBS exception classes,
 * this one has no constructor-promoted readonly properties, so it can be
 * instantiated and asserted against on local PHP 8.0 without the
 * PHP 8.1+ gap noted elsewhere in this test suite.
 */
class CreditinfoPublicDefaultsClientTest extends TestCase
{
    public function testListIndividualThrowsNotConfiguredException(): void
    {
        $client = new CreditinfoPublicDefaultsClient();
        $this->expectException(CreditinfoPublicDefaultsNotConfiguredException::class);
        $this->expectExceptionMessage('Public Defaults API is not yet configured. Creditinfo API documentation is required.');
        $client->listIndividual(['anything' => 'ignored']);
    }

    public function testRemoveIndividualThrowsNotConfiguredException(): void
    {
        $client = new CreditinfoPublicDefaultsClient();
        $this->expectException(CreditinfoPublicDefaultsNotConfiguredException::class);
        $client->removeIndividual([]);
    }

    public function testListIndividualNeverProceedsPastTheException(): void
    {
        $client = new CreditinfoPublicDefaultsClient();
        $calls = 0;
        try {
            $client->listIndividual([]);
            $calls++; // Would only increment if the exception failed to throw.
        } catch (CreditinfoPublicDefaultsNotConfiguredException $e) {
            // Expected -- no side effect, no partial state, nothing to undo.
        }
        $this->assertSame(0, $calls);
    }
}
