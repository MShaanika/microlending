<?php

namespace App\Services;

/**
 * Creditinfo Namibia CBS REST API business methods -- Smart Search
 * Individual, report retrieval (sync + async), and PDF retrieval (sync +
 * async), per the vendor's own Postman collection ("NAM_UAT_REST"), which
 * the user's explicit clarification confirms is authoritative over
 * NAM_CBS_WS_Report_Manual_JSON.pdf wherever they differ:
 *
 *   Deprecated/not used: search/individual (the manual's own documented
 *   endpoint -- Creditinfo has confirmed this is no longer in use).
 *   Current: search/smart/individual (the endpoint actually in the
 *   supplied Postman collection and confirmed correct by Creditinfo).
 *
 * Every method here just builds a JSON body and calls
 * CreditinfoClient::post()/get() -- HTTP transport, Bearer auth, and token
 * caching all live there, mirroring how CollexiaEndoApiClient relates to
 * CollexiaClient.
 *
 * Gender code and inquiry reason are ALWAYS passed in by the caller
 * (resolved from CreditinfoSetting), never computed or defaulted inside
 * this class:
 *   - "gender": 1 appears in the vendor's own Postman example with no
 *     definition anywhere in the 35-page manual of what 1 (or 2) means.
 *     Guessing Male=1/Female=2 (or the reverse) risks silently mismatching
 *     an applicant's actual gender against Creditinfo's records --
 *     CreditinfoSetting::genderCode() returns null until an admin
 *     explicitly configures both codes, and the caller must refuse to
 *     search rather than fall back to a guess.
 *   - inquiryReason on search/smart/individual is a STRING enum (5 valid
 *     values per the manual: CreditRenewal, CustomerInquiry,
 *     ApplicationForCreditOrAmendmentOfCreditTerms, InsuranceApplication,
 *     Other) -- the Postman sample uses CustomerInquiry, but that's
 *     generic sample data, not necessarily correct for a new loan
 *     application's credit check. inquiryReason on reports/custom is a
 *     completely different convention -- a bare NUMBER (36 in the
 *     Postman example), undefined anywhere in the manual. Both are
 *     configurable settings (creditinfo_inquiry_reason_search/_report),
 *     not hardcoded here, so a vendor-confirmed correction is a settings
 *     change, not a deploy.
 *
 * Company search (search/smart/company) exists in the vendor's Postman
 * collection but is deliberately NOT implemented here -- DesertLedger only
 * needs individual borrower checks.
 */
class CreditinfoBureauClient
{
    private CreditinfoClient $client;

    public function __construct()
    {
        $this->client = new CreditinfoClient();
    }

    public function lastDebug(): ?array
    {
        return $this->client->lastDebug();
    }

    /**
     * POST /search/smart/individual. $parameters keys: idNumber, gender
     * (int, from CreditinfoSetting::genderCode() -- see class docblock),
     * firstName, presentSurname, dateOfBirth (Y-m-d\TH:i:s), mobilePhone.
     * $interactive=true omits "consent" from the body entirely (per the
     * vendor's own Postman collection behaviour) and expects the caller to
     * follow up with continueSearch() once the applicant's consent is
     * captured through the interactive flow.
     *
     * Returns a normalized array: outcome ('SubjectFound'|'SubjectNotFound'),
     * workflowId, requestId, subjectToken, creditinfoId -- exactly the
     * fields the vendor's own Postman test script captures. SubjectFound
     * and SubjectNotFound (NIL report) are both legitimate results per the
     * manual (4.1) -- neither is an exception.
     */
    public function searchIndividual(array $parameters, string $inquiryReason, bool $consent, bool $interactive = false, ?string $inquiryReasonText = null, string $source = 'application_check'): array
    {
        $body = self::buildSearchBody($parameters, $inquiryReason, $consent, $interactive, $inquiryReasonText);
        $result = $this->client->post('/search/smart/individual', $body, $source);
        return self::normalizeSearchResult($result);
    }

    /**
     * Pure request-body builder, extracted from searchIndividual() so it's
     * unit-testable without a live DB connection or network call (this
     * codebase's Models -- including CreditinfoSetting, which
     * CreditinfoClient depends on -- require Database::connection() in
     * their constructor, so no instance method reachable through
     * CreditinfoClient can be exercised in a plain PHPUnit Unit test here;
     * see tests/Unit/CreditinfoBureauClientTest.php).
     */
    public static function buildSearchBody(array $parameters, string $inquiryReason, bool $consent, bool $interactive = false, ?string $inquiryReasonText = null): array
    {
        $body = [
            'inquiryReasonText' => $inquiryReasonText,
            'inquiryReason' => $inquiryReason,
            'parameters' => [
                'idNumbersList' => [[
                    'idNumberType' => 'NationalID',
                    'idNumber' => (string) ($parameters['idNumber'] ?? ''),
                ]],
                'firstName' => (string) ($parameters['firstName'] ?? ''),
                'presentSurname' => (string) ($parameters['presentSurname'] ?? ''),
                'gender' => (int) ($parameters['gender'] ?? 0),
                'dateOfBirth' => (string) ($parameters['dateOfBirth'] ?? ''),
                'mobilePhone' => (string) ($parameters['mobilePhone'] ?? ''),
            ],
            'timeOut' => 5,
            'interactiveSearch' => $interactive,
        ];
        if (!$interactive) {
            // Only present for the non-interactive case -- the vendor's own
            // Postman "Search with continue" flow omits it entirely when
            // interactiveSearch is true, matching the manual's note that
            // consent for an interactive search is captured via the
            // continue step instead.
            $body = ['consent' => $consent] + $body;
        }

        return $body;
    }

    /**
     * POST /search/smart/individual/continue -- the second phase of an
     * interactive search (manual 4.4, applied to the "smart" endpoint per
     * the vendor's own Postman collection). Built and unit-testable, but
     * deliberately NOT called from the default automated credit-check flow
     * -- interactiveSearch is not forced into the automated path unless a
     * caller explicitly needs it.
     */
    public function continueSearch(string $correlationId, bool $consentGiven = true, string $source = 'application_check'): array
    {
        $result = $this->client->post('/search/smart/individual/continue', [
            'action' => 'GiveConsent',
            'correlationId' => $correlationId,
            'data' => $consentGiven ? 'true' : 'false',
        ], $source);
        return self::normalizeSearchResult($result);
    }

    /** Pure response normalizer -- static for the same unit-testability reason as buildSearchBody()/buildReportBody(). */
    public static function normalizeSearchResult(array $result): array
    {
        $data = $result['data'] ?? [];
        $record = $data['individualRecords'][0] ?? [];
        return [
            'outcome' => $data['status'] ?? null,
            'workflowId' => $data['workflowId'] ?? null,
            'requestId' => $data['requestId'] ?? null,
            'subjectToken' => $record['subjectToken'] ?? null,
            'creditinfoId' => $record['creditinfoId'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * POST /reports/custom. $inquiryReason is the report-side value
     * (creditinfo_inquiry_reason_report, e.g. "36") -- cast to int only
     * here, at the JSON-encode boundary, matching the vendor's own Postman
     * example where it's a bare number, not a string.
     *
     * Returns the raw decoded response -- data.requestStatus is 'Finished'
     * (report ready immediately) or 'New'/'InProgress' (async -- see
     * pollCustomReport*()).
     */
    public function getCustomReport(string $subjectToken, int $creditinfoId, string $inquiryReason, array $sections = ['CreditinfoReportPlus'], string $source = 'application_check'): array
    {
        return $this->client->post('/reports/custom', self::buildReportBody($subjectToken, $creditinfoId, $inquiryReason, $sections), $source);
    }

    /** Pure request-body builder -- see buildSearchBody()'s docblock for why this is extracted and static. */
    public static function buildReportBody(string $subjectToken, int $creditinfoId, string $inquiryReason, array $sections = ['CreditinfoReportPlus']): array
    {
        return [
            'subjectToken' => $subjectToken,
            'timeout' => 5,
            'sectionsList' => $sections,
            'idNumber' => $creditinfoId,
            'idNumberType' => 'CreditinfoId',
            'inquiryReason' => (int) $inquiryReason,
            'subjectType' => 'individual',
        ];
    }

    /** GET /reports/custom/{token} -- full report once requestStatus is Finished. */
    public function pollCustomReport(string $token, string $source = 'poll'): array
    {
        return $this->client->get('/reports/custom/' . rawurlencode($token), $source);
    }

    /** GET /reports/custom/{token}/info -- status + chunkCount only, cheaper than the full poll. */
    public function pollCustomReportInfo(string $token, string $source = 'poll'): array
    {
        return $this->client->get('/reports/custom/' . rawurlencode($token) . '/info', $source);
    }

    /** GET /reports/custom/{token}/{chunkId} -- one base64 chunk of a large chunked report. */
    public function getCustomReportChunk(string $token, int $chunkId, string $source = 'poll'): array
    {
        return $this->client->get('/reports/custom/' . rawurlencode($token) . '/' . $chunkId, $source);
    }

    /** POST /reports/pdf -- data.report (base64) if immediate, or data.token + requestStatus 'InProgress' if async. */
    public function getReportPdf(string $reportToken, string $languageCode = 'en-GB', string $source = 'application_check'): array
    {
        return $this->client->post('/reports/pdf', [
            'languageCode' => $languageCode,
            'reportToken' => $reportToken,
        ], $source);
    }

    /** GET /reports/pdf/{token} -- the async follow-up once getReportPdf() returned InProgress. */
    public function getReportPdfStatus(string $token, string $source = 'application_check'): array
    {
        return $this->client->get('/reports/pdf/' . rawurlencode($token), $source);
    }

    /** GET /reports -- report types available for ReportsPdf. */
    public function listReportTypes(string $source = 'uat_test_centre'): array
    {
        return $this->client->get('/reports', $source);
    }

    /** GET /reports/custom/sections -- section names available for CustomReports (includes CreditinfoReportPlus). */
    public function listReportSections(string $source = 'uat_test_centre'): array
    {
        return $this->client->get('/reports/custom/sections', $source);
    }

    /** GET /reports/languages -- languages available for PdfReport. */
    public function listLanguages(string $source = 'uat_test_centre'): array
    {
        return $this->client->get('/reports/languages', $source);
    }
}
