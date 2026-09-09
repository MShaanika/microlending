<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Idempotency;
use App\Core\IdempotencyBusyException;
use App\Core\IdempotencyReplayException;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\CreditBureauConsent;
use App\Models\CreditinfoReportCache;
use App\Models\CreditinfoReportContent;
use App\Models\CreditinfoSetting;
use App\Models\LoanApplication;
use App\Models\RetentionPolicy;
use App\Services\CreditinfoApiException;
use App\Services\CreditinfoBureauClient;

/**
 * Wires the Creditinfo credit bureau check into the loan application
 * assessment workflow -- consent capture, running a check, viewing the
 * result, downloading the PDF. Entirely additive to ApplicationController;
 * approve()/reject() there call CreditinfoReportCache::stampDecision(),
 * the only touch point in that controller.
 *
 * No auto-approve/reject anywhere here -- this only ever records and
 * displays data. The three UAT test National IDs Creditinfo supplied
 * (77082851070, 8005041272341, 74082851070) are the only ones a staff
 * member can select instead of the applicant's real ID, and only while
 * creditinfo_environment = uat -- runCheck() refuses a submitted test ID
 * outright when the environment is production, not just hiding the
 * control client-side.
 */
class CreditinfoAssessmentController extends Controller
{
    private const UAT_TEST_IDS = ['77082851070', '8005041272341', '74082851070'];

    private LoanApplication $applications;
    private CreditBureauConsent $consents;
    private CreditinfoReportCache $reportCache;
    private CreditinfoReportContent $reportContent;
    private CreditinfoSetting $settings;

    public function __construct()
    {
        $this->applications = new LoanApplication();
        $this->consents = new CreditBureauConsent();
        $this->reportCache = new CreditinfoReportCache();
        $this->reportContent = new CreditinfoReportContent();
        $this->settings = new CreditinfoSetting();
    }

    /**
     * loan_applications has no date_of_birth column of its own -- DOB
     * already lives elsewhere and is resolved here rather than
     * duplicated: (1) borrowers.date_of_birth once the application is
     * linked to a borrower record (authoritative -- editable directly on
     * the borrower profile, so it can be corrected there); (2)
     * loan_applications.extra_data['dob'], captured only by the Back
     * Office intake form (BorrowerController::store()) -- Agent
     * Self-Service and the public online intake (ApplicationIntakeController)
     * never collect it at all. Returns null, never a guess, when neither
     * source has a value -- per instruction, DOB is never derived from
     * the National ID.
     */
    private function resolveDateOfBirth(array $application): ?string
    {
        $raw = null;
        if (!empty($application['borrower_id'])) {
            $borrower = (new Borrower())->find((int) $application['borrower_id']);
            if (!empty($borrower['date_of_birth'])) {
                $raw = (string) $borrower['date_of_birth'];
            }
        }
        if ($raw === null) {
            $extra = $application['extra_data'] ? (json_decode((string) $application['extra_data'], true) ?: []) : [];
            $raw = !empty($extra['dob']) ? (string) $extra['dob'] : null;
        }
        if ($raw === null) {
            return null;
        }
        // The vendor's own Postman example formats dateOfBirth as
        // Y-m-d\TH:i:s ("1976-07-16T00:00:00") -- reformat whatever date
        // string we found (DATE column or a plain form-submitted value)
        // to match, rather than sending a format Creditinfo has never
        // been shown.
        $timestamp = strtotime($raw);
        return $timestamp !== false ? date('Y-m-d\TH:i:s', $timestamp) : null;
    }

    private function assertBranchAccess(?array $application): void
    {
        if (!$application || Auth::isSuperAdmin() || $application['branch_id'] === null) {
            return;
        }
        if ((int) $application['branch_id'] !== (int) Auth::branchId()) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            exit;
        }
    }

    public function recordConsent(string $id): void
    {
        Auth::authorize('applications.credit_check');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }
        $this->assertBranchAccess($application);

        $method = trim($_POST['consent_method'] ?? '');
        if ($method === '') {
            Session::flash('error', 'Select how consent was captured.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->consents->create([
            'application_id' => $id,
            'borrower_id' => $application['borrower_id'] ?: null,
            'consent_given' => 1,
            'consent_method' => $method,
            'consent_reference' => trim($_POST['consent_reference'] ?? '') ?: null,
            'recorded_by' => $userId,
            'recorded_at' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ]);

        Audit::log('Create', 'Creditinfo', 'Recorded credit bureau consent for application #' . $id, ['consent_method' => $method]);
        Session::flash('success', 'Consent recorded.');
        $this->redirect('/applications/' . $id);
    }

    public function runCheck(string $id): void
    {
        Auth::authorize('applications.credit_check');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }
        $this->assertBranchAccess($application);

        if (!$this->settings->isEnabled()) {
            Session::flash('error', 'Creditinfo is not enabled -- see Settings > Integrations > Creditinfo.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $consent = $this->consents->latestUnconsumedForApplication($id);
        if (!$consent) {
            Session::flash('error', 'Record consent before running a credit bureau check.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $environment = $this->settings->get('creditinfo_environment', 'uat');
        $uatTestId = trim($_POST['uat_test_id'] ?? '');
        if ($uatTestId !== '') {
            if ($environment !== 'uat') {
                Session::flash('error', 'UAT test data cannot be used while the Creditinfo environment is set to Production.');
                $this->redirect('/applications/' . $id);
                return;
            }
            if (!in_array($uatTestId, self::UAT_TEST_IDS, true)) {
                Session::flash('error', 'That is not one of the approved UAT test National IDs.');
                $this->redirect('/applications/' . $id);
                return;
            }
        }
        $isUatTestId = $uatTestId !== '';
        $nationalId = $isUatTestId ? $uatTestId : (string) ($application['applicant_id_number'] ?? '');
        if ($nationalId === '') {
            Session::flash('error', 'This application has no National ID on file to check.');
            $this->redirect('/applications/' . $id);
            return;
        }

        // Gender code: explicit, admin-configured mapping only -- see
        // CreditinfoBureauClient's class docblock for why 1/2 is never
        // guessed here. Blocked for 'Other' too, since neither code has a
        // confirmed meaning to fall back to.
        $genderCode = $application['applicant_gender'] ? $this->settings->genderCode((string) $application['applicant_gender']) : null;
        if ($genderCode === null) {
            Session::flash('error', 'Gender code mapping is not yet confirmed with Creditinfo (Settings > Integrations > Creditinfo) -- cannot run a search for this applicant.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $inquiryReasonSearch = $this->settings->get('creditinfo_inquiry_reason_search', 'ApplicationForCreditOrAmendmentOfCreditTerms');
        $inquiryReasonReport = $this->settings->get('creditinfo_inquiry_reason_report', '36');
        $userId = Auth::user()['id'] ?? null;
        $key = $this->idempotencyKey();
        // Resolved from borrowers.date_of_birth (once linked) or
        // loan_applications.extra_data['dob'] (Back Office intake only) --
        // never derived from the National ID. Null when genuinely
        // unavailable (Agent Self-Service / public online intake, not yet
        // converted to a borrower) -- the search proceeds without it
        // rather than blocking, since the supplied Postman collection
        // doesn't confirm dateOfBirth as mandatory for Smart Search.
        $dateOfBirth = $this->resolveDateOfBirth($application);

        try {
            $this->applications->transaction(function () use ($id, $application, $consent, $nationalId, $isUatTestId, $environment, $genderCode, $dateOfBirth, $inquiryReasonSearch, $inquiryReasonReport, $userId, $key) {
                Idempotency::begin($key, 'application.credit_check', $userId);

                $bureau = new CreditinfoBureauClient();
                $search = $bureau->searchIndividual(
                    [
                        'idNumber' => $nationalId,
                        'gender' => $genderCode,
                        'firstName' => (string) $application['applicant_first_name'],
                        'presentSurname' => (string) $application['applicant_last_name'],
                        'dateOfBirth' => $dateOfBirth ?? '',
                        'mobilePhone' => (string) $application['applicant_phone'],
                    ],
                    $inquiryReasonSearch,
                    true,
                    false
                );

                $cacheId = $this->reportCache->create([
                    'application_id' => $id,
                    'borrower_id' => $application['borrower_id'] ?: null,
                    'consent_id' => $consent['id'],
                    'requested_by' => $userId,
                    'national_id_used' => $nationalId,
                    'is_uat_test_id' => $isUatTestId ? 1 : 0,
                    'environment' => $environment,
                    'inquiry_reason_search' => $inquiryReasonSearch,
                    'inquiry_reason_report' => $inquiryReasonReport,
                    'workflow_id' => $search['workflowId'],
                    'request_id' => $search['requestId'],
                    'subject_token' => $search['subjectToken'],
                    'creditinfo_id' => $search['creditinfoId'],
                    'search_outcome' => $search['outcome'] ?? 'Pending',
                    'report_status' => 'Unknown',
                ]);

                $applicationUpdate = [
                    'credit_check_status' => 'Pending',
                    'credit_check_outcome' => $search['outcome'] ?? null,
                    'credit_checked_by' => $userId,
                    'credit_checked_at' => date('Y-m-d H:i:s'),
                    'credit_bureau_reference' => $search['requestId'],
                ];

                if (($search['outcome'] ?? null) === 'SubjectFound' && $search['subjectToken'] && $search['creditinfoId']) {
                    $report = $bureau->getCustomReport((string) $search['subjectToken'], (int) $search['creditinfoId'], $inquiryReasonReport);
                    $reportData = $report['data'] ?? [];
                    $status = $reportData['requestStatus'] ?? 'New';

                    $this->reportCache->updateFields($cacheId, ['report_status' => $status]);

                    if ($status === 'Finished') {
                        $this->reportContent->storeReport($cacheId, $reportData['report'] ?? [], $reportData['requestId'] ?? null);
                        $applicationUpdate['credit_check_status'] = 'Completed';
                    }
                    // New/InProgress: left for bin/poll_creditinfo_reports.php
                    // to pick up -- report_status already reflects this.
                } elseif (($search['outcome'] ?? null) === 'SubjectNotFound') {
                    // A NIL report is a legitimate, completed result per the
                    // manual -- not an error, and there's no substantive
                    // content to fetch.
                    $applicationUpdate['credit_check_status'] = 'Completed';
                } else {
                    $applicationUpdate['credit_check_status'] = 'Error';
                }

                $this->applications->updateRecord($id, $applicationUpdate);

                Audit::log('Search', 'Creditinfo', 'Ran credit bureau check for application #' . $id . ' (outcome: ' . ($search['outcome'] ?? 'unknown') . ')', [], $key);
                Idempotency::complete($key, 'application.credit_check', 'REDIRECT', [
                    'flash_type' => 'success',
                    'flash_message' => 'Credit bureau check completed.',
                    'redirect' => '/applications/' . $id,
                ]);
            });
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/applications/' . $id);
            return;
        } catch (CreditinfoApiException $e) {
            $this->applications->updateRecord($id, ['credit_check_status' => 'Error']);
            Audit::log('Uncertain', 'Creditinfo', 'Credit bureau check failed for application #' . $id, ['exception' => $e->getMessage()]);
            Session::flash('error', 'Credit bureau check failed: ' . $e->getMessage());
            $this->redirect('/applications/' . $id);
            return;
        }

        Session::flash('success', 'Credit bureau check completed.');
        $this->redirect('/applications/' . $id);
    }

    public function viewAssessment(string $id): void
    {
        Auth::authorize('applications.credit_check');
        $id = (int) $id;

        $application = $this->applications->find($id);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }
        $this->assertBranchAccess($application);

        $cache = $this->reportCache->latestForApplication($id);
        if (!$cache) {
            Session::flash('error', 'No credit bureau check has been run for this application yet.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $content = $this->reportContent->findByReportCacheId((int) $cache['id']);
        $reportData = $content ? $this->reportContent->decryptedReport($content) : null;

        // Same "Never silently select a number of days" rule the settings
        // readiness panel already follows -- surfaced here too, on the
        // one screen that actually shows purgeable report content, not
        // just in the admin readiness panel.
        $retentionPolicy = (new RetentionPolicy())->allPolicies();
        $retentionRow = null;
        foreach ($retentionPolicy as $p) {
            if ($p['resource_table'] === 'creditinfo_report_content') {
                $retentionRow = $p;
                break;
            }
        }
        $retentionActive = $retentionRow && (int) $retentionRow['is_active'] === 1;

        $this->view('creditinfo/assessment', [
            'title' => 'Credit Bureau Assessment',
            'application' => $application,
            'cache' => $cache,
            'hasPdf' => $content && !empty($content['pdf_content_encrypted']),
            'hasReportToken' => $content && !empty($content['report_token']),
            'reportData' => $reportData,
            'purged' => !$content && $cache['purged_at'],
            'retentionActive' => $retentionActive,
            'retentionDays' => $retentionActive ? (int) $retentionRow['retention_days'] : null,
        ]);
    }

    /**
     * On-demand PDF generation for the real assessment screen -- distinct
     * from runCheck()/the poller, which only fetch a PDF when one is
     * already sitting there to serve. This is item 7's "only requested
     * when needed": nothing calls /reports/pdf until a staff member
     * deliberately clicks Generate/View PDF here, and only once a
     * Finished report (and its report_token) already exists.
     */
    public function requestPdf(string $id): void
    {
        Auth::authorize('applications.credit_check');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id . '/creditinfo/assessment');
            return;
        }

        $application = $this->applications->find($id);
        $this->assertBranchAccess($application);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }

        $cache = $this->reportCache->latestForApplication($id);
        $content = $cache ? $this->reportContent->findByReportCacheId((int) $cache['id']) : null;
        if (!$cache || !$content || empty($content['report_token'])) {
            Session::flash('error', 'A completed report is required before a PDF can be generated.');
            $this->redirect('/applications/' . $id . '/creditinfo/assessment');
            return;
        }
        if (!empty($content['pdf_content_encrypted'])) {
            Session::flash('success', 'PDF already available below.');
            $this->redirect('/applications/' . $id . '/creditinfo/assessment');
            return;
        }

        try {
            $bureau = new CreditinfoBureauClient();
            $pdf = $bureau->getReportPdf((string) $content['report_token'], 'en-GB');
            $data = $pdf['data'] ?? [];

            if (!empty($data['report'])) {
                $this->reportContent->storePdf((int) $cache['id'], (string) $data['report'], $data['token'] ?? null);
                Audit::log('Create', 'Creditinfo', 'Generated credit bureau PDF for application #' . $id);
                Session::flash('success', 'PDF generated -- see Download PDF below.');
            } else {
                Session::flash('success', 'PDF request sent -- still processing at Creditinfo. Try again shortly.');
            }
        } catch (CreditinfoApiException $e) {
            Audit::log('Uncertain', 'Creditinfo', 'PDF generation failed for application #' . $id, ['exception' => $e->getMessage()]);
            Session::flash('error', 'PDF generation failed: ' . $e->getMessage());
        }

        $this->redirect('/applications/' . $id . '/creditinfo/assessment');
    }

    public function downloadPdf(string $id): void
    {
        Auth::authorize('applications.credit_check');
        $id = (int) $id;

        $application = $this->applications->find($id);
        $this->assertBranchAccess($application);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }

        $cache = $this->reportCache->latestForApplication($id);
        $content = $cache ? $this->reportContent->findByReportCacheId((int) $cache['id']) : null;
        $pdf = $content ? $this->reportContent->decryptedPdf($content) : null;

        if (!$pdf) {
            Session::flash('error', 'No PDF is available for this application (not yet generated, or purged under the retention policy).');
            $this->redirect('/applications/' . $id . '/creditinfo/assessment');
            return;
        }

        Audit::log('View', 'Creditinfo', 'Downloaded credit bureau PDF for application #' . $id);

        $bytes = base64_decode($pdf, true);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="credit-report-' . $id . '.pdf"');
        header('Content-Length: ' . strlen((string) $bytes));
        echo $bytes;
        exit;
    }
}
