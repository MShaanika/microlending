<?php

namespace App\Services;

use App\Models\CreditinfoDiagnosticLog;
use App\Models\CreditinfoSetting;

/**
 * Computes the "CREDITINFO UAT READINESS" checklist shown on Settings >
 * Integrations > Creditinfo. Every row reports a status, but only some
 * rows can actually BLOCK "READY FOR UAT" -- the two are deliberately
 * separate ($blocking), because an evidence item (Authentication, Smart
 * Individual Search, CreditinfoReportPlus, Async Report Handling, PDF
 * Retrieval) can only ever show real evidence AFTER the first authorized
 * UAT call -- treating "not yet tested" as a blocker before that call has
 * even happened would make readiness impossible to reach by construction.
 * Those rows are informational: expected to read Fail before the first
 * test, expected to flip to Pass once it runs.
 *
 * What DOES block:
 *  - Configuration items (API Credentials, Gender Mapping, Search/Report
 *    Inquiry Reason) block only when genuinely Not Configured -- a
 *    Provisional value (present but not vendor-confirmed) does NOT block,
 *    since UAT testing is exactly how a provisional value gets confirmed
 *    or corrected. Confirmed status requires an admin to have explicitly
 *    ticked the matching "Creditinfo has confirmed this" checkbox
 *    (CreditinfoSettingController::CONFIRMATION_FLAGS) -- a value simply
 *    being entered is Provisional, never silently treated as
 *    vendor-approved.
 *  - Consent Controls blocks only if the enforcement mechanism itself is
 *    missing (it isn't -- always Pass once this code is deployed).
 *  - Retention Policy and Public Defaults never block UAT -- retention is
 *    a post-decision purge concern (not a prerequisite for running a
 *    search), and Public Defaults is explicitly out of scope pending a
 *    vendor spec (item 10).
 */
class CreditinfoUatReadinessService
{
    private CreditinfoSetting $settings;
    private CreditinfoDiagnosticLog $diagnostics;

    public function __construct()
    {
        $this->settings = new CreditinfoSetting();
        $this->diagnostics = new CreditinfoDiagnosticLog();
    }

    /** @return array<int, array{key: string, label: string, status: string, detail: string, blocking: bool}> */
    public function checklist(): array
    {
        $all = $this->settings->allSettings();
        $hasEvidence = fn (string $endpointLike) => $this->hasSuccessfulEvidence($endpointLike);

        return [
            $this->configItem(
                'api_credentials',
                'API Credentials',
                $this->settings->isConfigured() && $this->settings->isClientSecretSet(),
                $this->settings->isConfigured() && $this->settings->isClientSecretSet()
                    ? 'All connection fields and Client Secret are set.'
                    : (implode(', ', $this->settings->missingForEnable()) ?: 'Incomplete.')
            ),
            $this->evidenceItem(
                'authentication',
                'Authentication',
                $hasEvidence('/connect/token'),
                'A live token was successfully issued and logged.',
                'Not yet tested -- expected before the first authorized UAT call.'
            ),
            $this->evidenceItem(
                'smart_individual_search',
                'Smart Individual Search',
                $hasEvidence('/search/smart/individual'),
                'At least one successful Smart Search call has been logged.',
                'Not yet tested -- expected before the first authorized UAT call.'
            ),
            $this->threeStateItem(
                'gender_mapping',
                'Gender Mapping',
                !empty($all['creditinfo_gender_code_male']) && !empty($all['creditinfo_gender_code_female']),
                ($all['creditinfo_gender_mapping_confirmed'] ?? 'off') === 'on',
                'Both codes set, marked Creditinfo-confirmed.',
                'Both codes set, but NOT yet confirmed by Creditinfo -- treated as a best-guess only, safe for UAT testing, not for production.',
                'Not configured -- every credit check is blocked at runtime until this is set (see CreditinfoAssessmentController::runCheck()).'
            ),
            $this->threeStateItem(
                'search_inquiry_reason',
                'Search Inquiry Reason',
                !empty($all['creditinfo_inquiry_reason_search']),
                ($all['creditinfo_inquiry_reason_search_confirmed'] ?? 'off') === 'on',
                'Value set, marked Creditinfo-confirmed.',
                'Value set (' . ($all['creditinfo_inquiry_reason_search'] ?? '') . '), but NOT yet confirmed by Creditinfo.',
                'Not configured.'
            ),
            $this->threeStateItem(
                'report_inquiry_reason',
                'Report Inquiry Reason',
                !empty($all['creditinfo_inquiry_reason_report']),
                ($all['creditinfo_inquiry_reason_report_confirmed'] ?? 'off') === 'on',
                'Value set, marked Creditinfo-confirmed.',
                'Value set (' . ($all['creditinfo_inquiry_reason_report'] ?? '') . ', taken from the vendor\'s own Postman example), but its meaning is NOT independently confirmed.',
                'Not configured.'
            ),
            $this->evidenceItem(
                'creditinfo_report_plus',
                'CreditinfoReportPlus',
                $hasEvidence('/reports/custom'),
                'A CreditinfoReportPlus request has been successfully logged.',
                'Not yet tested -- expected before the first authorized UAT call.'
            ),
            $this->evidenceItem(
                'async_report_handling',
                'Async Report Handling',
                $hasEvidence('/reports/custom/'),
                'At least one poll of an async report token has been logged.',
                'Built (bin/poll_creditinfo_reports.php), not yet exercised against a real async report -- never treated as a failure, only as untested.'
            ),
            $this->evidenceItem(
                'pdf_retrieval',
                'PDF Retrieval',
                $hasEvidence('/reports/pdf'),
                'A PDF request has been successfully logged.',
                'Built and wired into the assessment screen, not yet exercised.'
            ),
            [
                'key' => 'consent_controls',
                'label' => 'Consent Controls',
                'status' => 'pass',
                'detail' => 'Enforced entirely within DesertLedger (credit_bureau_consents, one-time-use per check) -- verifiable without a Creditinfo round-trip.',
                'blocking' => true,
            ],
            [
                'key' => 'retention_policy',
                'label' => 'Retention Policy',
                'status' => 'awaiting_compliance',
                'detail' => 'Creditinfo Report Retention: Awaiting Compliance Configuration -- mechanism is ready (creditinfo_report_content, RetentionService), but no retention period has been approved, so it stays inactive. Not a UAT blocker (a post-decision purge concern, not a search/report prerequisite).',
                'blocking' => false,
            ],
            [
                'key' => 'public_defaults',
                'label' => 'Public Defaults / Status',
                'status' => 'pending',
                'detail' => 'API Documentation Required -- absent from the supplied manual and Postman collection entirely, so nothing has been invented or built for it. Deliberately kept out of CreditinfoBureauClient/CreditinfoAssessmentController so a future Public Defaults integration can be added as its own module without touching or rewriting the CBS credit-check code above. Not required for UAT readiness of the CBS module.',
                'blocking' => false,
            ],
        ];
    }

    public function isUatReady(): bool
    {
        foreach ($this->checklist() as $item) {
            if ($item['blocking'] && !in_array($item['status'], ['pass', 'confirmed', 'provisional'], true)) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] Human-readable reasons isUatReady() is false -- only ever lists blocking items. */
    public function blockers(): array
    {
        $blockers = [];
        foreach ($this->checklist() as $item) {
            if ($item['blocking'] && !in_array($item['status'], ['pass', 'confirmed', 'provisional'], true)) {
                $blockers[] = $item['label'] . ': ' . $item['detail'];
            }
        }
        return $blockers;
    }

    private function configItem(string $key, string $label, bool $ok, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail, 'blocking' => true];
    }

    /** Evidence items are informational only -- never block readiness, since they can't have evidence before the first authorized call. */
    private function evidenceItem(string $key, string $label, bool $hasEvidence, string $passDetail, string $failDetail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $hasEvidence ? 'pass' : 'fail', 'detail' => $hasEvidence ? $passDetail : $failDetail, 'blocking' => false];
    }

    private function threeStateItem(string $key, string $label, bool $valueSet, bool $confirmed, string $confirmedDetail, string $provisionalDetail, string $notConfiguredDetail): array
    {
        if (!$valueSet) {
            $status = 'not_configured';
            $detail = $notConfiguredDetail;
        } elseif ($confirmed) {
            $status = 'confirmed';
            $detail = $confirmedDetail;
        } else {
            $status = 'provisional';
            $detail = $provisionalDetail;
        }
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'blocking' => true];
    }

    private function hasSuccessfulEvidence(string $endpointContains): bool
    {
        foreach ($this->diagnostics->recent(200) as $row) {
            if (str_contains((string) $row['endpoint'], $endpointContains) && empty($row['error_code'])) {
                return true;
            }
        }
        return false;
    }
}
