<?php

namespace Tests\Unit;

use App\Services\CreditinfoBureauClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CreditinfoBureauClient's request-body shapes against the
 * vendor's own Postman collection (NAM_UAT_REST.postman_collection) --
 * not a guessed spec. Deliberately avoids touching CreditinfoSetting/the
 * database (broken on this dev machine, same constraint documented in
 * CollexiaClientTest): every method under test here is a pure static
 * function extracted from CreditinfoBureauClient specifically so this is
 * possible (see that class's docblock on buildSearchBody()).
 */
class CreditinfoBureauClientTest extends TestCase
{
    // --- Search body shape (POST /search/smart/individual) ---

    public function testNonInteractiveSearchBodyMatchesThePostmanCollectionShapeExactly(): void
    {
        $body = CreditinfoBureauClient::buildSearchBody(
            ['idNumber' => '74082851070', 'gender' => 1, 'firstName' => 'Spacey', 'presentSurname' => 'McSpaceface', 'dateOfBirth' => '1976-07-16T00:00:00', 'mobilePhone' => '840488810'],
            'CustomerInquiry',
            true,
            false,
            null
        );

        $this->assertSame(true, $body['consent'], 'consent must be present and true for a non-interactive search.');
        $this->assertSame('CustomerInquiry', $body['inquiryReason']);
        $this->assertNull($body['inquiryReasonText']);
        $this->assertSame(5, $body['timeOut']);
        $this->assertFalse($body['interactiveSearch']);
        $this->assertSame([['idNumberType' => 'NationalID', 'idNumber' => '74082851070']], $body['parameters']['idNumbersList']);
        $this->assertSame('Spacey', $body['parameters']['firstName']);
        $this->assertSame('McSpaceface', $body['parameters']['presentSurname']);
        $this->assertSame(1, $body['parameters']['gender']);
        $this->assertSame('1976-07-16T00:00:00', $body['parameters']['dateOfBirth']);
        $this->assertSame('840488810', $body['parameters']['mobilePhone']);
    }

    public function testInteractiveSearchBodyOmitsConsentEntirely(): void
    {
        $body = CreditinfoBureauClient::buildSearchBody(
            ['idNumber' => '74082851070', 'gender' => 1, 'firstName' => 'Spacey', 'presentSurname' => 'McSpaceface', 'dateOfBirth' => '1976-07-16T00:00:00', 'mobilePhone' => '840488810'],
            'CustomerInquiry',
            true, // even when the caller passes consent=true, it must not appear
            true
        );

        $this->assertArrayNotHasKey('consent', $body, 'The vendor Postman "Search with continue" flow omits consent entirely when interactiveSearch is true.');
        $this->assertTrue($body['interactiveSearch']);
    }

    public function testGenderIsAlwaysCastToInt(): void
    {
        $body = CreditinfoBureauClient::buildSearchBody(['idNumber' => '1', 'gender' => '2'], 'Other', true);
        $this->assertSame(2, $body['parameters']['gender']);
        $this->assertIsInt($body['parameters']['gender']);
    }

    public function testIdNumberTypeIsAlwaysNationalId(): void
    {
        $body = CreditinfoBureauClient::buildSearchBody(['idNumber' => '77082851070'], 'Other', true);
        $this->assertSame('NationalID', $body['parameters']['idNumbersList'][0]['idNumberType']);
    }

    // --- Report body shape (POST /reports/custom) ---

    /**
     * Regression guard: idNumber and inquiryReason must be NUMERIC on this
     * endpoint, not strings -- exactly the class of bug (JSON string vs.
     * number mismatch) that already caused a real live rejection on the
     * Collexia integration earlier this session (merchantGid/remoteGid
     * sent as JSON strings instead of integers, error 9406). The vendor's
     * own Postman example for reports/custom shows idNumber and
     * inquiryReason both as bare numbers, not quoted strings.
     */
    public function testReportBodySendsIdNumberAndInquiryReasonAsNumbersNotStrings(): void
    {
        $body = CreditinfoBureauClient::buildReportBody('subj-token-abc', 8364059, '36');

        $this->assertSame(8364059, $body['idNumber']);
        $this->assertIsInt($body['idNumber']);
        $this->assertSame(36, $body['inquiryReason']);
        $this->assertIsInt($body['inquiryReason']);

        $json = json_encode($body);
        $this->assertStringContainsString('"idNumber":8364059', $json, 'idNumber must be a JSON number, not a quoted string.');
        $this->assertStringContainsString('"inquiryReason":36', $json, 'inquiryReason must be a JSON number, not a quoted string.');
    }

    public function testReportBodyDefaultsToCreditinfoReportPlusSection(): void
    {
        $body = CreditinfoBureauClient::buildReportBody('token', 1, '36');
        $this->assertSame(['CreditinfoReportPlus'], $body['sectionsList']);
        $this->assertSame('CreditinfoId', $body['idNumberType']);
        $this->assertSame('individual', $body['subjectType']);
    }

    // --- Response normalization ---

    public function testNormalizeSearchResultExtractsExactlyThePostmanTestScriptFields(): void
    {
        $result = self::normalizeSearchResult([
            'data' => [
                'status' => 'SubjectFound',
                'workflowId' => 'wf-1',
                'requestId' => 'req-1',
                'individualRecords' => [['subjectToken' => 'tok-1', 'creditinfoId' => 8364059]],
            ],
        ]);

        $this->assertSame('SubjectFound', $result['outcome']);
        $this->assertSame('wf-1', $result['workflowId']);
        $this->assertSame('req-1', $result['requestId']);
        $this->assertSame('tok-1', $result['subjectToken']);
        $this->assertSame(8364059, $result['creditinfoId']);
    }

    /** A NIL report (SubjectNotFound) still carries a usable subjectToken per the manual -- must normalize cleanly, not be treated as missing data. */
    public function testNormalizeSearchResultHandlesNilReportShape(): void
    {
        $result = self::normalizeSearchResult([
            'data' => [
                'status' => 'SubjectNotFound',
                'workflowId' => 'wf-2',
                'requestId' => 'req-2',
                'individualRecords' => [['subjectToken' => 'tok-nil']],
            ],
        ]);

        $this->assertSame('SubjectNotFound', $result['outcome']);
        $this->assertSame('tok-nil', $result['subjectToken']);
        $this->assertNull($result['creditinfoId'], 'A NIL report example in the manual has no creditinfoId field.');
    }

    public function testNormalizeSearchResultDoesNotErrorOnAMissingIndividualRecordsArray(): void
    {
        $result = self::normalizeSearchResult(['data' => ['status' => 'NotSpecified', 'workflowId' => 'wf-3']]);
        $this->assertNull($result['subjectToken']);
        $this->assertNull($result['creditinfoId']);
    }

    private static function normalizeSearchResult(array $result): array
    {
        return CreditinfoBureauClient::normalizeSearchResult($result);
    }
}
