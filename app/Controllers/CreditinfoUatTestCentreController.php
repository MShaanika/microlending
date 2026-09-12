<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CreditinfoDiagnosticLog;
use App\Models\CreditinfoSetting;
use App\Services\CreditinfoApiException;
use App\Services\CreditinfoBureauClient;
use App\Support\CreditinfoV3Codes;

/**
 * A UAT-only diagnostic screen, entirely separate from the real loan
 * application workflow -- lets an authorised admin/developer run each
 * step of the Creditinfo lifecycle (search -> report -> PDF) one at a
 * time, deliberately, against only the three vendor-supplied UAT test
 * National IDs. Never touches loan_applications, credit_bureau_consents,
 * or creditinfo_report_cache -- this is diagnostics, not a real credit
 * check, so it doesn't participate in that audit/retention trail; its
 * own trail is CreditinfoDiagnosticLog (see runInquiry()/requestReport()/
 * requestPdf(), each tagged source='uat_test_centre').
 *
 * Hard-blocked in every action (not just hidden in the view) whenever
 * creditinfo_environment isn't 'uat' -- this must never be reachable in
 * Production regardless of what the UI shows.
 */
class CreditinfoUatTestCentreController extends Controller
{
    private const UAT_TEST_IDS = ['77082851070', '8005041272341', '74082851070'];
    private const SESSION_KEY = 'creditinfo_uat_test_centre_state';

    private CreditinfoSetting $settings;

    public function __construct()
    {
        $this->settings = new CreditinfoSetting();
    }

    private function assertUatEnvironment(): bool
    {
        if ($this->settings->get('creditinfo_environment', 'uat') !== 'uat') {
            Session::flash('error', 'The UAT Test Centre is only available while the Creditinfo environment is set to UAT.');
            $this->redirect('/creditinfo/settings');
            return false;
        }
        return true;
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');
        if (!$this->assertUatEnvironment()) {
            return;
        }

        $this->view('creditinfo/uat_test_centre', [
            'title' => 'Creditinfo UAT Test Centre',
            'testIds' => self::UAT_TEST_IDS,
            'state' => Session::get(self::SESSION_KEY, []),
            'recentDiagnostics' => (new CreditinfoDiagnosticLog())->recent(20),
        ]);
    }

    /** Step 1: Smart Search Individual -- never runs unless this exact action is deliberately clicked. */
    public function runInquiry(): void
    {
        Auth::authorize('admin.system_settings');
        if (!$this->assertUatEnvironment()) {
            return;
        }
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        $testId = trim($_POST['test_id'] ?? '');
        if (!in_array($testId, self::UAT_TEST_IDS, true)) {
            Session::flash('error', 'Select one of the approved UAT test National IDs.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        $genderCode = (int) ($this->settings->genderCode('Male') ?? $this->settings->genderCode('Female') ?? 0);
        $inquiryReason = (int) $this->settings->get('creditinfo_inquiry_reason_search', (string) CreditinfoV3Codes::DEFAULT_NEW_CREDIT_INQUIRY_REASON);

        try {
            $bureau = new CreditinfoBureauClient();
            $search = $bureau->searchIndividual(
                ['idNumber' => $testId, 'gender' => $genderCode, 'firstName' => 'UAT', 'presentSurname' => 'TestSubject', 'dateOfBirth' => '', 'mobilePhone' => ''],
                $inquiryReason,
                true,
                false,
                null,
                'uat_test_centre'
            );

            Session::put(self::SESSION_KEY, ['testId' => $testId, 'search' => $search]);
            Audit::log('Search', 'Creditinfo', 'UAT Test Centre: ran Smart Search for test ID ' . CreditinfoDiagnosticLog::maskNationalId($testId));
            Session::flash('success', 'Search completed -- outcome: ' . ($search['outcome'] ?? 'unknown'));
        } catch (CreditinfoApiException $e) {
            Session::flash('error', 'Search failed: ' . $e->getMessage());
        }

        $this->redirect('/creditinfo/uat-test-centre');
    }

    /** Step 2: CreditinfoReportPlus request -- only reachable after a SubjectFound search result is already in session, and only on deliberate click. */
    public function requestReport(): void
    {
        Auth::authorize('admin.system_settings');
        if (!$this->assertUatEnvironment()) {
            return;
        }
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        $state = Session::get(self::SESSION_KEY, []);
        $search = $state['search'] ?? null;
        if (!$search || ($search['outcome'] ?? null) !== 'SubjectFound' || empty($search['subjectToken']) || empty($search['creditinfoId'])) {
            Session::flash('error', 'Run a search with a SubjectFound outcome first.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        $inquiryReasonReport = $this->settings->get('creditinfo_inquiry_reason_report', (string) CreditinfoV3Codes::INQUIRY_REASON_CUSTOMER_INQUIRY);

        try {
            $bureau = new CreditinfoBureauClient();
            $report = $bureau->getCustomReport((string) $search['subjectToken'], (int) $search['creditinfoId'], $inquiryReasonReport, ['CreditinfoReportPlus'], 'uat_test_centre');
            $reportData = $report['data'] ?? [];

            $state['report'] = [
                'requestStatus' => $reportData['requestStatus'] ?? 'Unknown',
                'requestId' => $reportData['requestId'] ?? null,
                'reportToken' => $reportData['report']['reportInfo']['reportToken'] ?? $reportData['requestId'] ?? null,
                // Section presence only, never the content -- this is a
                // diagnostic screen, not a place to display real report data.
                'sectionsPresent' => is_array($reportData['report'] ?? null) ? array_keys(array_filter($reportData['report'], fn ($v) => is_array($v) && !empty($v))) : [],
            ];
            Session::put(self::SESSION_KEY, $state);

            Audit::log('Search', 'Creditinfo', 'UAT Test Centre: requested CreditinfoReportPlus (status: ' . $state['report']['requestStatus'] . ')');
            Session::flash('success', 'Report request completed -- status: ' . $state['report']['requestStatus']);
        } catch (CreditinfoApiException $e) {
            Session::flash('error', 'Report request failed: ' . $e->getMessage());
        }

        $this->redirect('/creditinfo/uat-test-centre');
    }

    /** Step 3: PDF -- only requested when explicitly clicked, per item 7 ("only requested when needed"). */
    public function requestPdf(): void
    {
        Auth::authorize('admin.system_settings');
        if (!$this->assertUatEnvironment()) {
            return;
        }
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        $state = Session::get(self::SESSION_KEY, []);
        $reportToken = $state['report']['reportToken'] ?? null;
        if (!$reportToken) {
            Session::flash('error', 'Request the report first.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }

        try {
            $bureau = new CreditinfoBureauClient();
            $pdf = $bureau->getReportPdf((string) $reportToken, 'en-GB', 'uat_test_centre');
            $status = $pdf['data']['requestStatus'] ?? ($pdf['data']['report'] ?? null ? 'Finished' : 'InProgress');

            $state['pdf'] = ['status' => $status, 'hasContent' => !empty($pdf['data']['report'])];
            Session::put(self::SESSION_KEY, $state);

            Audit::log('View', 'Creditinfo', 'UAT Test Centre: requested PDF (status: ' . $status . ')');
            Session::flash('success', 'PDF request completed -- status: ' . $status);
        } catch (CreditinfoApiException $e) {
            Session::flash('error', 'PDF request failed: ' . $e->getMessage());
        }

        $this->redirect('/creditinfo/uat-test-centre');
    }

    public function reset(): void
    {
        Auth::authorize('admin.system_settings');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/uat-test-centre');
            return;
        }
        Session::forget(self::SESSION_KEY);
        Session::flash('success', 'Test Centre state cleared.');
        $this->redirect('/creditinfo/uat-test-centre');
    }
}
