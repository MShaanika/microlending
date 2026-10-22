<?php

namespace App\Services;

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
 *   1. Nothing is sent until the loan is released and the books are posted. The
 *      payout is a separate step AFTER the release transaction commits, so a failed
 *      release can never leave money paid out with no entry behind it.
 *   2. The payout carries one fixed remoteReferenceNo for its whole life. A retry
 *      re-sends that same reference, so if the first attempt did reach Collexia the
 *      switch refuses it as a duplicate (spec Appendix B 10598/10626) instead of
 *      paying twice -- and that refusal is read as "already received", not an error.
 *   3. A transfer being accepted (200/204, no body) only means Collexia captured
 *      it. It is never reported as paid until transferInfo() says Completed.
 *   4. The same reference is what the Cash Book and General Journal show for the
 *      disbursement, so the books can be matched to Collexia's records.
 */
class CollexiaEncrDisbursementService
{
    private CollexiaSetting $settings;
    private CollexiaEncrApiClient $api;
    private Loan $loans;
    private ?Borrower $borrowers;

    public function __construct(?CollexiaSetting $settings = null, ?CollexiaEncrApiClient $api = null, ?Loan $loans = null, ?Borrower $borrowers = null)
    {
        $this->settings = $settings ?? new CollexiaSetting();
        $this->api = $api ?? new CollexiaEncrApiClient();
        $this->loans = $loans ?? new Loan();
        $this->borrowers = $borrowers;
    }

    public function lastDebug(): ?array
    {
        return $this->api->lastDebug();
    }

    /** True once the EnCr migration (database/collexia_encr_disbursement.sql) has been applied. */
    public static function schemaReady(): bool
    {
        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM loan_disbursements LIKE 'encr_reference_no'");
            return $stmt !== false && $stmt->fetch() !== false;
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
            throw new \RuntimeException('The borrower has no bank details on file.');
        }

        $accountNo = preg_replace('/\s+/', '', (string) ($bank['account_number'] ?? ''));
        if ($accountNo === '' || !ctype_alnum($accountNo)) {
            throw new \RuntimeException('The borrower\'s bank account number is missing or not a plain account number.');
        }

        $banId = CollexiaV3Codes::banIdFromName((string) ($bank['bank_name'] ?? ''));
        if ($banId === null) {
            throw new \RuntimeException('"' . trim((string) ($bank['bank_name'] ?? '')) . '" is not a bank Collexia can pay to. Correct the bank name on the borrower first.');
        }

        $type = CollexiaV3Codes::accountTypeFromLabel((string) ($bank['account_type'] ?? ''));
        if ($type === null) {
            throw new \RuntimeException('The borrower\'s bank account type is blank or not Savings / Cheque / Current -- set it in the borrower\'s bank details first.');
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
        if (($d['encr_state'] ?? '') !== 'Not Sent') {
            return ['ok' => false, 'message' => 'This payout has already been sent (' . ($d['encr_state'] ?? 'unknown') . ') -- use Check payout status instead.'];
        }
        if (!$this->settings->encrEnabled()) {
            return $this->unsent($disbursementId, 'Collexia EnCr is switched off or not fully configured, so nothing was sent.');
        }

        try {
            $loan = $this->loans->find((int) $d['loan_id']);
            $payee = $this->resolvePayee((int) $d['borrower_id']);

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
            return $this->unsent($disbursementId, 'Collexia refused the payout: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->unsent($disbursementId, 'The payout may not have reached Collexia (' . $e->getMessage() . '). It is safe to try again -- the same reference is re-used, so it cannot be paid twice.');
        }

        return $this->submitted($disbursementId, 'Accepted by Collexia. This only confirms it was received -- check the payout status to see that the money has left.');
    }

    /**
     * Asks Collexia what became of the payout and records it. Completed is the only
     * result that means the borrower has been paid.
     *
     * @return array{ok: bool, message: string}
     */
    public function checkStatus(int $disbursementId, string $frontEndUserName): array
    {
        $d = $this->loans->disbursementById($disbursementId);
        if (!$d || empty($d['encr_reference_no'])) {
            return ['ok' => false, 'message' => 'This disbursement was not set up for an EnCr payout.'];
        }
        if (($d['encr_state'] ?? '') === 'Not Sent') {
            return ['ok' => false, 'message' => 'This payout has not been sent to Collexia yet -- send it first.'];
        }

        try {
            $r = $this->api->transferInfo($d['encr_reference_no'], $frontEndUserName, (int) $this->settings->get('collexia_encr_uac_id'));
        } catch (\Throwable $e) {
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
        if ($state === 'Failed') {
            $message .= ' — the money did NOT leave. The loan is already released and posted in the books, so pay the borrower another way and correct the entry.';
        }

        $this->loans->updateDisbursementFields($disbursementId, [
            'encr_state' => $state,
            'encr_status_code' => $status ?: null,
            'encr_response_code' => isset($r['responseCode']) ? substr((string) $r['responseCode'], 0, 10) : null,
            'encr_response_description' => isset($r['responseDescription']) ? substr((string) $r['responseDescription'], 0, 250) : null,
            'encr_amount_paid_out' => $paidOut,
            'encr_checked_at' => date('Y-m-d H:i:s'),
            'encr_last_message' => substr($message, 0, 500),
        ]);

        return ['ok' => $state !== 'Failed', 'message' => $message];
    }

    private function submitted(int $id, string $message): array
    {
        $this->loans->updateDisbursementFields($id, [
            'encr_state' => 'Submitted',
            'encr_submitted_at' => date('Y-m-d H:i:s'),
            'encr_last_message' => substr($message, 0, 500),
        ]);
        return ['ok' => true, 'message' => $message];
    }

    private function unsent(int $id, string $message): array
    {
        $this->loans->updateDisbursementFields($id, [
            'encr_state' => 'Not Sent',
            'encr_last_message' => substr($message, 0, 500),
        ]);
        return ['ok' => false, 'message' => $message];
    }
}
