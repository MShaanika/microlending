<?php

namespace App\Services;

use App\Models\CplBatch;
use App\Models\CreditinfoDiagnosticLog;
use App\Models\CreditinfoSetting;

/**
 * Computes the "CREDITINFO INTEGRATION SIGN-OFF" dashboard -- the three
 * mandatory streams Creditinfo has said they require before they will sign
 * off this integration: CBS API, CPL Daily, CPL Monthly.
 *
 * Deliberately distinct from CreditinfoUatReadinessService (that one is a
 * broader internal pre-flight checklist -- gender mapping, consent
 * controls, retention policy, etc. -- most of which Creditinfo never asked
 * about). This service reports ONLY what Creditinfo's own sign-off
 * criteria named, in the exact status vocabulary requested:
 *
 *   CBS Authentication:      NOT CONFIGURED / CONFIGURED / READY TO TEST / TESTED - FAILED / PASSED
 *   Smart Search / Report /
 *   PDF (each depends on
 *   Authentication):         WAITING FOR AUTHENTICATION / READY TO TEST / TESTED - FAILED / PASSED
 *   CPL Daily / CPL Monthly: NOT READY / READY / SUBMITTED / ACCEPTED / REJECTED
 *   Overall:                 NOT READY / READY FOR SIGN-OFF
 *
 * A missing credential is NEVER reported as FAILED -- Creditinfo's own
 * instruction is explicit that "not configured" and "failed" must stay
 * visually and semantically distinct. CPL Daily/Monthly can never reach
 * ACCEPTED from anything this service computes automatically -- that state
 * is only ever set by a human recording Creditinfo's own load/result
 * confirmation (see CplUatTestController::recordOutcome()).
 */
class CreditinfoSignOffService
{
    private CreditinfoSetting $creditinfoSettings;
    private CreditinfoDiagnosticLog $diagnostics;
    private CplBatch $batches;

    public function __construct()
    {
        $this->creditinfoSettings = new CreditinfoSetting();
        $this->diagnostics = new CreditinfoDiagnosticLog();
        $this->batches = new CplBatch();
    }

    public function authenticationStatus(): string
    {
        if (!$this->creditinfoSettings->isConfigured() || !$this->creditinfoSettings->isClientSecretSet()) {
            return 'NOT CONFIGURED';
        }
        if (!$this->creditinfoSettings->isEnabled()) {
            return 'CONFIGURED';
        }

        $latest = $this->latestAttempt('/connect/token');
        if ($latest === null) {
            return 'READY TO TEST';
        }
        return empty($latest['error_code']) ? 'PASSED' : 'TESTED - FAILED';
    }

    /** @param string $endpointContains substring identifying this stream's endpoint in the diagnostic log */
    public function dependentStreamStatus(string $endpointContains): string
    {
        if ($this->authenticationStatus() !== 'PASSED') {
            return 'WAITING FOR AUTHENTICATION';
        }

        $latest = $this->latestAttempt($endpointContains);
        if ($latest === null) {
            return 'READY TO TEST';
        }
        return empty($latest['error_code']) ? 'PASSED' : 'TESTED - FAILED';
    }

    public function smartSearchStatus(): string
    {
        return $this->dependentStreamStatus('/search/smart/individual');
    }

    public function reportPlusStatus(): string
    {
        return $this->dependentStreamStatus('/reports/custom');
    }

    public function pdfRetrievalStatus(): string
    {
        return $this->dependentStreamStatus('/reports/pdf');
    }

    public function cbsApiOverallPass(): bool
    {
        return $this->authenticationStatus() === 'PASSED'
            && $this->smartSearchStatus() === 'PASSED'
            && $this->reportPlusStatus() === 'PASSED'
            && $this->pdfRetrievalStatus() === 'PASSED';
    }

    /** NOT READY / READY / SUBMITTED / ACCEPTED / REJECTED for the latest UAT sign-off test batch of this type. */
    public function cplStatus(string $batchType): string
    {
        $batch = $this->batches->latestOfType($batchType, true);
        if ($batch === null) {
            return 'NOT READY';
        }
        if ($batch['blocking_error_count'] > 0) {
            return 'NOT READY';
        }
        return match ($batch['submission_outcome'] ?? null) {
            'Accepted' => 'ACCEPTED',
            'Rejected', 'Partially Rejected' => 'REJECTED',
            'Awaiting Load Report' => 'SUBMITTED',
            default => 'READY',
        };
    }

    public function overallSignOffStatus(): string
    {
        $ready = $this->cbsApiOverallPass()
            && $this->cplStatus('Daily') === 'ACCEPTED'
            && $this->cplStatus('Monthly') === 'ACCEPTED';
        return $ready ? 'READY FOR SIGN-OFF' : 'NOT READY';
    }

    private function latestAttempt(string $endpointContains): ?array
    {
        foreach ($this->diagnostics->recent(200) as $row) {
            if (str_contains((string) $row['endpoint'], $endpointContains)) {
                return $row;
            }
        }
        return null;
    }
}
