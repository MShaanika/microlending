<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\Loan;
use App\Support\CollexiaV3Codes;

/**
 * Pays a released loan out through Collexia EnCr -- an OPTIONAL path, behind the
 * "Pay loans out through Collexia EnCr" setting (CollexiaSetting::encrEnabled()).
 * With that off, nothing in here is reachable from the Release form and a loan is
 * disbursed exactly as before.
 *
 * Money safety, in the order it matters:
 *   1. Nothing is posted until Collexia says the money has left. Releasing with EnCr
 *      only puts the loan in "Awaiting Payout" with a PENDING disbursement (see
 *      LoanReleaseService); the payout is sent after that commits. Only when
 *      transferInfo() says Completed is the release posted (journal + Cash Book) and
 *      the loan made Active -- so the books never show money that did not leave.
 *   2. The payout carries one fixed remoteReferenceNo for its whole life. A retry
 *      re-sends that same reference, so if the first attempt did reach Collexia the
 *      switch refuses it as a duplicate (spec Appendix B 10598/10626) instead of
 *      paying twice -- and that refusal is read as "already received", not an error.
 *   3. A transfer being accepted (200/204, no body) only means Collexia captured
 *      it. It is never reported as paid until transferInfo() says Completed.
 *   4. When a send gets no clear answer (no response, or a Collexia server error) the
 *      payout MAY have been captured: encr_maybe_received is set, and the loan cannot
 *      be paid another way or have its release cancelled until a retry or a status
 *      check gives a clear answer.
 *   5. The same reference is what the Cash Book and General Journal show for the
 *      disbursement once it is posted, so the books can be matched to Collexia's records.
 */
class CollexiaEncrDisbursementService
{
    private CollexiaSetting $settings;
    private CollexiaEncrApiClient $api;
    private Loan $loans;
    private ?Borrower $borrowers;
    private ?LoanReleaseService $release;

    public function __construct(?CollexiaSetting $settings = null, ?CollexiaEncrApiClient $api = null, ?Loan $loans = null, ?Borrower $borrowers = null, ?LoanReleaseService $release = null)
    {
        $this->settings = $settings ?? new CollexiaSetting();
        $this->api = $api ?? new CollexiaEncrApiClient();
        $this->loans = $loans ?? new Loan();
        $this->borrowers = $borrowers;
        $this->release = $release;
    }

    public function lastDebug(): ?array
    {
        return $this->api->lastDebug();
    }

    /**
     * True once BOTH EnCr migrations have been applied: database/collexia_encr_disbursement.sql (the
     * encr_* columns) and database/encr_awaiting_payout.sql (the "Awaiting Payout" loan status and the
     * encr_maybe_received flag). Until then EnCr is not offered and loans are released the usual way.
     */
    public static function schemaReady(): bool
    {
        try {
            $db = Database::connection();
            $stmt = $db->query("SHOW COLUMNS FROM loan_disbursements LIKE 'encr_maybe_received'");
            if ($stmt === false || $stmt->fetch() === false) {
                return false;
            }
            $stmt = $db->query("SHOW COLUMNS FROM loan_disbursements LIKE 'encr_reference_no'");
            if ($stmt === false || $stmt->fetch() === false) {
                return false;
            }
            $stmt = $db->query("SHOW COLUMNS FROM loans LIKE 'loan_status'");
            $column = $stmt !== false ? $stmt->fetch() : false;
            return $column !== false && str_contains((string) ($column['Type'] ?? $column['type'] ?? ''), "'" . Loan::STATUS_AWAITING_PAYOUT . "'");
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The switch, the shared Collexia credentials, the EnCr values and the database columns are all in place. */
    public function available(): bool
    {
        return $this->settings->encrEnabled() && self::schemaReady();
    }

    /**
     * Where the money would go: the borrower's primary bank record, translated into
     * Collexia's codes. Throws with a plain-language reason when it can't be done
     * safely -- better to refuse than to guess a bank or account type.
     *
     * @return array{account_no: string, ban_id: int, account_type: int, label: string}
     */
    public function resolvePayee(int $borrowerId): array
    {
        $bank = ($this->borrowers ??= new Borrower())->bankDetails($borrowerId);
        if (!$bank) {
            throw new \RuntimeException('The client has no bank details on file.');
        }

        $accountNo = preg_replace('/\s+/', '', (string) ($bank['account_number'] ?? ''));
        if ($accountNo === '' || !ctype_alnum($accountNo)) {
            throw new \RuntimeException('The client\'s bank account number is missing or not a plain account number.');
        }

        $banId = CollexiaV3Codes::banIdFromName((string) ($bank['bank_name'] ?? ''));
        if ($banId === null) {
            throw new \RuntimeException('"' . trim((string) ($bank['bank_name'] ?? '')) . '" is not a bank Collexia can pay to. Correct the bank name on the client first.');
        }

        $type = CollexiaV3Codes::accountTypeFromLabel((string) ($bank['account_type'] ?? ''));
        if ($type === null) {
            throw new \RuntimeException('The client\'s bank account type is blank or not Savings / Cheque / Current -- set it in the client\'s bank details first.');
        }

        // Paying an account into itself: Collexia's UAT answered this with a bare "192 Internal server error"
        // (sandbox, 24 Nov 2026), so refuse it here with a reason staff can act on.
        $fromNo = preg_replace('/\s+/', '', (string) $this->settings->get('collexia_encr_from_account_no'));
        if ($fromNo !== '' && ltrim($fromNo, '0') === ltrim($accountNo, '0')
            && $banId === (int) $this->settings->get('collexia_encr_from_ban_id')) {
            throw new \RuntimeException('The client\'s bank account (' . CollexiaV3Codes::BANK_IDS[$banId] . ', ending ' . substr($accountNo, -4)
                . ') is the same account the payout is paid FROM. Correct the client\'s bank details, or pay this loan out another way.');
        }

        return [
            'account_no' => $accountNo,
            'ban_id' => $banId,
            'account_type' => $type,
            'label' => CollexiaV3Codes::BANK_IDS[$banId] . ' · account ending ' . substr($accountNo, -4) . ' (' . CollexiaV3Codes::ACCOUNT_TYPES[$type] . ')',
        ];
    }

    /**
     * 14 chars, a-z A-Z 0-9 (spec 9.1): "ER" + loan id (6) + 6 random. The spec says
     * Collexia will supply its own formula for this; until it does, this is unique
     * per loan and the column is UNIQUE, so a collision fails loudly, never silently.
     */
    public static function newReference(int $loanId): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return 'ER' . str_pad((string) ($loanId % 1000000), 6, '0', STR_PAD_LEFT) . $random;
    }

    /** Statement references: Collexia documents letters and digits only, 40 max. Spaces are kept (its own sample has them). */
    public static function statementReference(string $text): string
    {
        return substr(trim((string) preg_replace('/[^A-Za-z0-9 ]/', '', $text)), 0, 40);
    }

    /** Collexia status -> our state. 3 is the only "money left"; 4/5/7 are "it did not". */
    public static function stateFromStatus(int $status): string
    {
        return match ($status) {
            3 => 'Completed',
            4, 5, 7 => 'Failed',
            default => 'Submitted',
        };
    }

    /**
     * Sends the payout for a disbursement that is waiting ('Not Sent'). Safe to call
     * again after a failure -- see class docblock point 2. Never throws: the outcome
     * is recorded on the disbursement and returned.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(int $disbursementId, string $frontEndUserName): array
    {
        $d = $this->loans->disbursementById($disbursementId);
        if (!$d || empty($d['encr_reference_no'])) {
            return ['ok' => false, 'message' => 'This disbursement was not set up for an EnCr payout.'];
        }
        if (($d['status'] ?? '') === 'Cancelled') {
            return ['ok' => false, 'message' => 'This payout was set aside (the loan was paid another way or its release was cancelled), so it is not sent.'];
        }
        if (($d['encr_state'] ?? '') !== 'Not Sent') {
            return ['ok' => false, 'message' => 'This payout has already been sent (' . ($d['encr_state'] ?? 'unknown') . ') -- use Check payout status instead.'];
        }
        if (!$this->settings->encrEnabled()) {
            return $this->unsent($disbursementId, 'Collexia EnCr is switched off or not fully configured, so nothing was sent.');
        }

        // Checked before anything goes out, so a bad payee is reported as "not sent" with its real reason.
        try {
            $payee = $this->resolvePayee((int) $d['borrower_id']);
        } catch (\RuntimeException $e) {
            return $this->unsent($disbursementId, 'Not sent to Collexia: ' . $e->getMessage());
        }

        $loan = $this->loans->find((int) $d['loan_id']);

        // Claimed under a row lock first, and marked "may have been received" for as long as the request is in
        // flight: nobody can pay the loan another way or cancel its release meanwhile, and a payout set aside
        // before this point is never sent (see Loan::claimEncrSend()).
        $claimed = $this->loans->claimEncrSend($disbursementId);
        if ($claimed === null) {
            return ['ok' => false, 'message' => 'This payout is no longer waiting to be sent (it was set aside or its state changed), so nothing was sent. Refresh the loan page.'];
        }
        // What was known before this attempt: a clear refusal of THIS attempt says nothing about an earlier unclear one.
        $earlierUnclear = !empty($claimed['encr_maybe_received']);

        try {
            $this->api->transfer([
                'remoteReferenceNo' => $d['encr_reference_no'],
                'amount' => (float) $d['amount'],
                'toAccountNo' => $payee['account_no'],
                'toBanId' => $payee['ban_id'],
                'toAccountType' => $payee['account_type'],
                'ownStatementReference' => self::statementReference('Loan ' . ($loan['loan_no'] ?? '')),
                'beneficiaryReference' => self::statementReference('Loan disbursement ' . ($loan['loan_no'] ?? '')),
                'fromAccountNo' => (string) $this->settings->get('collexia_encr_from_account_no'),
                'fromBanId' => (int) $this->settings->get('collexia_encr_from_ban_id'),
                'fromAccountType' => (int) $this->settings->get('collexia_encr_from_account_type'),
                'fromGlobalId' => $this->settings->encrFromGlobalId(),
                'uacId' => (int) $this->settings->get('collexia_encr_uac_id'),
                'transferAlreadyAuthed' => $this->settings->encrTransferAlreadyAuthed(),
                'frontEndUserName' => $frontEndUserName,
            ]);
        } catch (CollexiaApiException $e) {
            $codes = array_map('strval', array_column($e->errors(), 'code'));
            if (array_intersect($codes, CollexiaV3Codes::ENCR_DUPLICATE_CODES)) {
                return $this->submitted($disbursementId, 'Collexia already holds this payout (' . $e->getMessage() . ') -- it was received on an earlier attempt and will not be paid twice.');
            }
            // A server-side error (5xx, or no HTTP status at all) is not a clear "no": the payout may have been captured.
            if (self::answerIsUnclear($e->httpStatus())) {
                return $this->unsent($disbursementId, 'Collexia answered with an error (' . $e->getMessage() . '), so it is not certain the payout was refused. Use "Retry payout" (the same reference is re-used, so it cannot be paid twice) or "Check payout status" for a clear answer.', true);
            }
            // A clear refusal of THIS attempt. It is not proof that an earlier attempt with no clear answer was not captured
            // (Collexia may validate other fields before it checks the reference), so that earlier doubt is kept: only a
            // status check can clear it.
            return $this->unsent($disbursementId, 'Collexia refused the payout: ' . $e->getMessage()
                . ($earlierUnclear ? ' An earlier attempt got no clear answer, so use "Check payout status" before paying this loan another way.' : ''), $earlierUnclear);
        } catch (\Throwable $e) {
            // CollexiaClient::post() refuses a few things (switched off, not configured, demo/UAT guard) BEFORE anything is sent:
            // those are a clear "not sent" for this attempt. A failed or timed-out request, or anything unexpected, may have reached Collexia.
            $maybe = !($e instanceof \RuntimeException) || str_starts_with($e->getMessage(), 'Failed to reach the Collexia API');
            return $this->unsent($disbursementId, 'The payout may not have reached Collexia (' . $e->getMessage() . '). It is safe to try again -- the same reference is re-used, so it cannot be paid twice.', $maybe || $earlierUnclear);
        }

        return $this->submitted($disbursementId, 'Accepted by Collexia. This only confirms it was received -- check the payout status to see that the money has left.');
    }

    /**
     * Asks Collexia what became of the payout and records it. Completed is the only
     * result that means the borrower has been paid -- and the moment it is seen, the
     * waiting loan is released and posted (LoanReleaseService::finaliseEncrPayout(),
     * which posts once however often this runs).
     *
     * @return array{ok: bool, message: string, finalised?: bool}
     */
    public function checkStatus(int $disbursementId, string $frontEndUserName, ?int $userId = null): array
    {
        $d = $this->loans->disbursementById($disbursementId);
        if (!$d || empty($d['encr_reference_no'])) {
            return ['ok' => false, 'message' => 'This disbursement was not set up for an EnCr payout.'];
        }
        if (($d['status'] ?? '') === 'Cancelled') {
            return ['ok' => false, 'message' => 'This payout was set aside (the loan was paid another way or its release was cancelled).'];
        }
        $unclearSend = ($d['encr_state'] ?? '') === 'Not Sent' && !empty($d['encr_maybe_received']);
        if (($d['encr_state'] ?? '') === 'Not Sent' && !$unclearSend) {
            return ['ok' => false, 'message' => 'This payout has not been sent to Collexia yet -- send it first.'];
        }

        try {
            $r = $this->api->transferInfo($d['encr_reference_no'], $frontEndUserName, (int) $this->settings->get('collexia_encr_uac_id'));
        } catch (\Throwable $e) {
            // After a send with no clear answer: Collexia clearly saying it has nothing under this reference settles it -- it was never received.
            if ($unclearSend && $e instanceof CollexiaApiException && in_array($e->httpStatus(), [400, 404, 422], true)) {
                $message = 'Collexia holds no payout under ' . $d['encr_reference_no'] . ' (' . $e->getMessage() . '), so the earlier attempt never reached it and nothing will be paid. Retry the payout, pay the loan another way, or cancel the release.';
                $this->loans->updateDisbursementFields($disbursementId, [
                    'encr_maybe_received' => 0,
                    'encr_checked_at' => date('Y-m-d H:i:s'),
                    'encr_last_message' => substr($message, 0, 500),
                ]);
                return ['ok' => false, 'message' => $message];
            }
            $this->loans->updateDisbursementFields($disbursementId, [
                'encr_checked_at' => date('Y-m-d H:i:s'),
                'encr_last_message' => substr('Status check failed: ' . $e->getMessage(), 0, 500),
            ]);
            return ['ok' => false, 'message' => 'Could not get the payout status from Collexia: ' . $e->getMessage()];
        }

        $status = (int) ($r['status'] ?? 0);
        $state = self::stateFromStatus($status);
        $label = CollexiaV3Codes::ENCR_STATUSES[$status] ?? ('status ' . $status);
        $paidOut = isset($r['amountPaidOut']) ? round((float) $r['amountPaidOut'], 2) : null;

        $message = 'Collexia status: ' . $label;
        if (!empty($r['responseDescription'])) {
            $message .= ' — ' . $r['responseDescription'];
        }
        if ($state === 'Completed' && $paidOut !== null && abs($paidOut - (float) $d['amount']) > 0.005) {
            $message .= ' — WARNING: Collexia paid out ' . number_format($paidOut, 2) . ' but the loan is ' . number_format((float) $d['amount'], 2) . '.';
        }
        $alreadyPosted = ($d['status'] ?? '') === 'Disbursed';
        if ($state === 'Failed') {
            $message .= $alreadyPosted
                // a payout from before loans waited for Collexia: the release was posted up front
                ? ' — the money did NOT leave. The loan is already released and posted in the books, so pay the client another way and correct the entry.'
                : ' — the money did NOT leave. Nothing was posted. Pay the loan out another way or cancel the release.';
        } elseif ($state === 'Submitted' && !$alreadyPosted) {
            $message .= ' — not paid yet. Nothing is posted until Collexia reports it Completed.';
        }

        $this->loans->updateDisbursementFields($disbursementId, [
            'encr_state' => $state,
            'encr_maybe_received' => 0,
            'encr_status_code' => $status ?: null,
            'encr_response_code' => isset($r['responseCode']) ? substr((string) $r['responseCode'], 0, 10) : null,
            'encr_response_description' => isset($r['responseDescription']) ? substr((string) $r['responseDescription'], 0, 250) : null,
            'encr_amount_paid_out' => $paidOut,
            'encr_checked_at' => date('Y-m-d H:i:s'),
            'encr_last_message' => substr($message, 0, 500),
        ]);

        // The money has left: release and post the loan now (once -- see LoanReleaseService::finaliseEncrPayout()).
        if ($state === 'Completed' && !$alreadyPosted) {
            $final = ($this->release ??= new LoanReleaseService())->finaliseEncrPayout($disbursementId, $userId, self::dateFromCollexia($r['statusDate'] ?? null));
            return ['ok' => empty($final['error']), 'message' => $message . ' — ' . $final['message'], 'finalised' => $final['finalised']];
        }

        return ['ok' => $state !== 'Failed', 'message' => $message, 'finalised' => false];
    }

    /** Collexia's YYYYMMDD (or YYYY-MM-DD) date as Y-m-d, or null when it gave none. */
    public static function dateFromCollexia($value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));
        if (strlen($digits) !== 8 || !checkdate((int) substr($digits, 4, 2), (int) substr($digits, 6, 2), (int) substr($digits, 0, 4))) {
            return null;
        }
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
    }

    /** A 5xx, or no HTTP status at all, is not a clear answer about whether Collexia captured the payout. */
    public static function answerIsUnclear(?int $httpStatus): bool
    {
        return $httpStatus === null || $httpStatus === 0 || $httpStatus >= 500;
    }

    private function submitted(int $id, string $message): array
    {
        $lateWarning = $this->recordSendOutcome($id, [
            'encr_state' => 'Submitted',
            'encr_maybe_received' => 0,
            'encr_submitted_at' => date('Y-m-d H:i:s'),
            'encr_last_message' => substr($message, 0, 500),
        ], true);
        return $lateWarning === null ? ['ok' => true, 'message' => $message] : ['ok' => false, 'message' => $lateWarning];
    }

    /** @param ?bool $maybeReceived true = the payout may have been captured; false = clearly not; null = this attempt sent nothing, keep what was known */
    private function unsent(int $id, string $message, ?bool $maybeReceived = null): array
    {
        $lateWarning = $this->recordSendOutcome($id, [
            'encr_state' => 'Not Sent',
            'encr_last_message' => substr($message, 0, 500),
        ] + ($maybeReceived === null ? [] : ['encr_maybe_received' => $maybeReceived ? 1 : 0]), false);
        return ['ok' => false, 'message' => $lateWarning ?? $message];
    }

    /**
     * Writes a send's outcome -- but never silently onto a payout that was set aside (Cancelled) meanwhile.
     * Loan::claimEncrSend() makes that impossible in normal use; this is the second line of defence. If
     * Collexia ACCEPTED a payout that had been set aside, the client may be paid twice: that is recorded on
     * the row and in the audit log, loudly, and returned as the message.
     *
     * @return ?string null when written normally, otherwise what happened instead
     */
    private function recordSendOutcome(int $id, array $fields, bool $accepted): ?string
    {
        return $this->loans->transaction(function () use ($id, $fields, $accepted) {
            $row = $this->loans->disbursementForUpdate($id);
            if (!$row || ($row['status'] ?? '') !== 'Cancelled') {
                $this->loans->updateDisbursementFields($id, $fields);
                return null;
            }
            if (!$accepted) {
                return 'This payout had been set aside while it was being sent, and Collexia did not take it. Nothing was changed.';
            }
            $warning = 'WARNING: Collexia accepted payout ' . ($row['encr_reference_no'] ?? '') . ' AFTER it had been set aside (the loan was paid another way or its release was cancelled). '
                . 'The client may be paid twice -- contact Collexia at once to stop it.';
            $this->loans->updateDisbursementFields($id, [
                'encr_state' => 'Submitted',
                'encr_maybe_received' => 1,
                'encr_submitted_at' => date('Y-m-d H:i:s'),
                'encr_last_message' => substr($warning, 0, 500),
            ]);
            Audit::log('Update', 'Loans', 'Loan #' . (int) ($row['loan_id'] ?? 0) . ': ' . $warning);
            return $warning;
        });
    }
}
