<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Events;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\StatutoryCharge;

/**
 * Releasing a loan, and -- for a Collexia EnCr payout -- waiting for the money to really leave.
 *
 * MANUAL release (the usual one, and the only one while EnCr is switched off): exactly what it
 * always was -- in one transaction the disbursement is posted (journal + Cash Book), Upfront
 * interest is recognised, the loan goes Active and the disbursement record is written.
 *
 * ENCR release: the loan goes to "Awaiting Payout" and a PENDING disbursement record carries the
 * EnCr reference. NOTHING is posted and the loan does not count as disbursed anywhere (every report,
 * arrears, penalty, debit order and CPL query lists the statuses it counts, and none lists this one;
 * every disbursement report counts only loan_disbursements.status = 'Disbursed'). Only when
 * Collexia reports the payout Completed does finaliseEncrPayout() run the very same posting as a
 * manual release (postRelease()), dated the day the money left, with the EnCr reference.
 *
 * If the payout was not sent or failed, staff may pay the loan out another way (a normal manual
 * release) or cancel the release (back to Approved) -- but never while Collexia may still pay it
 * (Submitted, or the last send got no clear answer): see whyPayoutCannotBeAbandoned().
 *
 * The repayment schedule is NOT touched by any of this: it is generated when the loan is created
 * (LoanController::store()), and a manual release does not regenerate it either, however many days
 * after approval it happens. An EnCr payout normally completes the same or the next working day, so
 * the confirmed date is used for the books and released_at, and the schedule keeps its dates. A
 * payout confirmed so late that the first instalment no longer makes sense is a case for Reschedule.
 */
class LoanReleaseService
{
    /** The EnCr states in which Collexia will certainly not pay the loan out (anymore). */
    public const ABANDONABLE_STATES = ['Not Sent', 'Failed'];

    private Loan $loans;
    private AccountingJournal $journal;
    private AccountingAccount $accounts;
    private StatutoryCharge $statutoryCharges;
    private BankAccount $bankAccounts;

    public function __construct(?Loan $loans = null, ?AccountingJournal $journal = null, ?AccountingAccount $accounts = null, ?StatutoryCharge $statutoryCharges = null, ?BankAccount $bankAccounts = null)
    {
        $this->loans = $loans ?? new Loan();
        $this->journal = $journal ?? new AccountingJournal();
        $this->accounts = $accounts ?? new AccountingAccount();
        $this->statutoryCharges = $statutoryCharges ?? new StatutoryCharge();
        $this->bankAccounts = $bankAccounts ?? new BankAccount();
    }

    /**
     * The release itself. Runs INSIDE the caller's transaction (LoanController::release() also holds
     * the idempotency key in it), after which the caller fires the after-release steps
     * (afterRelease()) -- for a manual release only; an EnCr release has not paid anything yet.
     *
     * @param ?string $encrReference null = manual release; otherwise the EnCr remoteReferenceNo
     * @return int the disbursement id
     */
    public function release(int $loanId, array $loan, ?int $userId, ?array $bankAccount, string $method, ?string $referenceNo, ?string $encrReference = null): int
    {
        // Locked, authoritative status check -- a second concurrent release request blocks here
        // until this transaction commits or rolls back, then correctly sees the changed status.
        $locked = $this->loans->findForUpdate($loanId);
        if (!$locked || $locked['loan_status'] !== 'Approved') {
            throw new \RuntimeException('Only approved loans can be released.');
        }

        if ($encrReference === null) {
            $this->postRelease($loanId, $loan, $userId, $bankAccount, $referenceNo, 'Approved', date('Y-m-d'), date('Y-m-d H:i:s'), 'Loan released / disbursed.');
            return $this->createManualDisbursement($loanId, $loan, $userId, $bankAccount, $method, $referenceNo);
        }

        $this->loans->updateFields($loanId, ['loan_status' => Loan::STATUS_AWAITING_PAYOUT]);
        $this->loans->logStatus($loanId, 'Approved', Loan::STATUS_AWAITING_PAYOUT, $userId,
            'Released with a Collexia EnCr payout (' . $encrReference . '). Nothing is posted until Collexia confirms the money has left.');

        return $this->loans->createDisbursement([
            'loan_id' => $loanId,
            'borrower_id' => (int) $loan['borrower_id'],
            'disbursement_no' => generate_reference('DSB'),
            'disbursement_date' => date('Y-m-d'),
            'disbursement_method' => 'Bank Transfer',
            'bank_account_id' => $bankAccount ? $bankAccount['id'] : null,
            'amount' => (float) $loan['principal_amount'],
            'reference_no' => $encrReference,
            'status' => 'Pending',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
            'created_by' => $userId,
            'encr_reference_no' => $encrReference,
            'encr_state' => 'Not Sent',
        ]);
    }

    /**
     * Everything a release posts and changes on the loan, in the order the manual release always did it.
     * Shared by the manual release, the EnCr finalisation and "pay out another way", so all three post the same.
     * Must run inside a transaction holding the loan row lock.
     */
    public function postRelease(int $loanId, array $loan, ?int $userId, ?array $bankAccount, ?string $referenceNo, string $fromStatus, string $date, string $releasedAt, string $note, ?int $releasedBy = null): void
    {
        // Post accounting first: if this fails (e.g. a closed accounting period), the whole transaction
        // rolls back -- the loan must not end up Active/disbursed with no journal behind it, or vice versa.
        $this->postDisbursementAccounting($loanId, $loan, $userId, $bankAccount, $referenceNo, $date);

        // A flat, non-refundable fee loan earns its interest in full the moment it's disbursed -- see
        // InterestAccrualService::recognizeUpfront(). Progressive loans are unaffected.
        if (($loan['interest_recognition_method'] ?? '') === 'Upfront') {
            InterestAccrualService::recognizeUpfront($loanId, $date, $userId);
        }

        $this->loans->updateFields($loanId, [
            'loan_status' => 'Active',
            'released_by' => $releasedBy ?? $userId,
            'released_at' => $releasedAt,
        ]);
        $this->loans->logStatus($loanId, $fromStatus, 'Active', $userId, $note);
        AgentCommissionService::onDisbursement($loan, $userId);
    }

    /** After the release transaction has COMMITTED (a listener must never see a disbursement that could still roll back). Returns a note for the flash message. */
    public function afterRelease(int $loanId, array $loan, ?int $userId): string
    {
        Events::fire('LoanDisbursed', ['loan_id' => $loanId, 'released_by' => $userId]);

        // A released top-up changes what the original loan's live debit order should collect: flag it and cancel it so ONE new
        // combined debit order can be registered (same as a reschedule).
        return !empty($loan['topup_of_loan_id'])
            ? (new TopUpMandateAdjustment())->afterTopUpChange((int) $loan['topup_of_loan_id'], 'top-up ' . $loan['loan_no'] . ' was released', $userId)
            : '';
    }

    /**
     * Collexia reported the payout Completed: release the loan for real, exactly as a manual release
     * would, dated the day the money left. Idempotent -- the disbursement and the loan are row-locked
     * and only a Pending disbursement of a loan still Awaiting Payout is ever posted, so a double click
     * or two status checks at once post once.
     *
     * @param ?string $paidOn Collexia's own status date (Y-m-d) when it gave one
     * @return array{finalised: bool, error: bool, message: string} error = Collexia confirmed it but posting failed (nothing was posted)
     */
    public function finaliseEncrPayout(int $disbursementId, ?int $userId, ?string $paidOn = null): array
    {
        $loan = null;
        try {
            $result = $this->loans->transaction(function () use ($disbursementId, $userId, $paidOn, &$loan) {
                $d = $this->loans->disbursementForUpdate($disbursementId);
                if (!$d || empty($d['encr_reference_no'])) {
                    return ['finalised' => false, 'error' => false, 'message' => 'This disbursement was not set up for an EnCr payout.'];
                }
                $locked = $this->loans->findForUpdate((int) $d['loan_id']);
                if (($d['status'] ?? '') === 'Disbursed' || ($locked && in_array($locked['loan_status'], ['Active', 'Current', 'Completed'], true))) {
                    return ['finalised' => false, 'error' => false, 'message' => 'The loan is already released and posted -- nothing more to do.'];
                }
                if (($d['status'] ?? '') !== 'Pending' || !$locked || $locked['loan_status'] !== Loan::STATUS_AWAITING_PAYOUT) {
                    return ['finalised' => false, 'error' => false, 'message' => 'This loan is no longer waiting for this payout, so nothing was posted.'];
                }
                if (($d['encr_state'] ?? '') !== 'Completed') {
                    return ['finalised' => false, 'error' => false, 'message' => 'Collexia has not confirmed this payout as Completed, so nothing was posted.'];
                }

                $loanId = (int) $d['loan_id'];
                $loan = $this->loans->find($loanId);
                $date = self::paidOnDate($paidOn, (string) $d['disbursement_date']);
                $now = date('Y-m-d H:i:s');
                $bankAccount = !empty($d['bank_account_id']) ? $this->bankAccounts->find((int) $d['bank_account_id']) : null;
                $releasedBy = !empty($d['created_by']) ? (int) $d['created_by'] : $userId;

                $this->postRelease($loanId, $loan, $userId, $bankAccount, $d['encr_reference_no'], Loan::STATUS_AWAITING_PAYOUT, $date, $now,
                    'Collexia confirmed EnCr payout ' . $d['encr_reference_no'] . ' as Completed (paid ' . $date . '). Loan released and posted.', $releasedBy);
                $this->loans->updateDisbursementFields($disbursementId, [
                    'status' => 'Disbursed',
                    'disbursement_date' => $date,
                    'disbursed_by' => $releasedBy,
                    'disbursed_at' => $now,
                ]);
                Audit::log('Release', 'Loans', 'Loan #' . $loanId . ' released and posted: Collexia confirmed EnCr payout ' . $d['encr_reference_no'] . ' (paid ' . $date . ').');

                return ['finalised' => true, 'error' => false, 'message' => 'The loan is now released and posted in the books (Cash Book and General Journal reference ' . $d['encr_reference_no'] . ', dated ' . $date . ').'];
            });
        } catch (\RuntimeException $e) {
            return ['finalised' => false, 'error' => true, 'message' => 'Collexia confirmed the payout, but posting it failed: ' . $e->getMessage() . ' Nothing was posted -- fix that and use "Check payout status" again.'];
        }

        if ($result['finalised'] && $loan) {
            $result['message'] .= $this->afterRelease((int) $loan['id'], $loan, $userId);
        }
        return $result;
    }

    /**
     * The EnCr payout did not go through: release the loan the usual, manual way instead (posted now,
     * with the method/bank account/reference staff give). The EnCr disbursement record is kept, cancelled.
     *
     * @return array{ok: bool, message: string}
     */
    public function payEncrLoanAnotherWay(int $loanId, ?int $userId, string $method, ?array $bankAccount, ?string $referenceNo): array
    {
        $loan = null;
        try {
            $result = $this->loans->transaction(function () use ($loanId, $userId, $method, $bankAccount, $referenceNo, &$loan) {
                [$locked, $d, $refusal] = $this->lockWaitingLoan($loanId);
                if ($refusal !== null) {
                    return ['ok' => false, 'message' => $refusal];
                }

                $loan = $this->loans->find($loanId);
                $this->loans->updateDisbursementFields((int) $d['id'], [
                    'status' => 'Cancelled',
                    'encr_last_message' => substr('Not paid through Collexia: paid out another way instead on ' . date('Y-m-d H:i') . '. (' . ($d['encr_last_message'] ?? '') . ')', 0, 500),
                ]);
                $this->postRelease($loanId, $loan, $userId, $bankAccount, $referenceNo, Loan::STATUS_AWAITING_PAYOUT, date('Y-m-d'), date('Y-m-d H:i:s'),
                    'Collexia EnCr payout ' . $d['encr_reference_no'] . ' did not go through (' . $d['encr_state'] . '); paid out another way (' . $method . ') and released.');
                $this->createManualDisbursement($loanId, $loan, $userId, $bankAccount, $method, $referenceNo);
                Audit::log('Release', 'Loans', 'Loan #' . $loanId . ': EnCr payout ' . $d['encr_reference_no'] . ' (' . $d['encr_state'] . ') abandoned; paid out another way (' . $method
                    . ($referenceNo ? ', reference ' . $referenceNo : '') . ') and released.');

                return ['ok' => true, 'message' => 'Loan released, disbursement recorded, and accounting entries posted. The Collexia payout ' . $d['encr_reference_no'] . ' was set aside.'];
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => 'Nothing was changed: ' . $e->getMessage()];
        }

        if ($result['ok'] && $loan) {
            $result['message'] .= $this->afterRelease($loanId, $loan, $userId);
        }
        return $result;
    }

    /**
     * The EnCr payout did not go through: undo the release (the loan goes back to Approved). Nothing had
     * been posted, so nothing needs reversing; the EnCr disbursement record is kept, cancelled.
     *
     * @return array{ok: bool, message: string}
     */
    public function cancelEncrRelease(int $loanId, ?int $userId): array
    {
        try {
            return $this->loans->transaction(function () use ($loanId, $userId) {
                [$locked, $d, $refusal] = $this->lockWaitingLoan($loanId);
                if ($refusal !== null) {
                    return ['ok' => false, 'message' => $refusal];
                }

                $this->loans->updateDisbursementFields((int) $d['id'], [
                    'status' => 'Cancelled',
                    'encr_last_message' => substr('Release cancelled on ' . date('Y-m-d H:i') . ' -- the loan went back to Approved. (' . ($d['encr_last_message'] ?? '') . ')', 0, 500),
                ]);
                $this->loans->updateFields($loanId, ['loan_status' => 'Approved', 'released_by' => null, 'released_at' => null]);
                $this->loans->logStatus($loanId, Loan::STATUS_AWAITING_PAYOUT, 'Approved', $userId,
                    'Release cancelled: Collexia EnCr payout ' . $d['encr_reference_no'] . ' did not go through (' . $d['encr_state'] . '). Nothing had been posted.');
                Audit::log('Update', 'Loans', 'Loan #' . $loanId . ': release cancelled, back to Approved. EnCr payout ' . $d['encr_reference_no'] . ' (' . $d['encr_state'] . ') set aside; nothing had been posted.');

                return ['ok' => true, 'message' => 'Release cancelled. The loan is back to Approved and nothing was posted. It can be released again (through Collexia with a new reference, or the usual way).'];
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => 'Nothing was changed: ' . $e->getMessage()];
        }
    }

    /**
     * Why the loan may NOT be paid another way / have its release cancelled right now, or null when it may.
     * The rule: only when Collexia will certainly not pay it -- never sent (with a clear answer), or failed.
     */
    public static function whyPayoutCannotBeAbandoned(?array $d): ?string
    {
        if (!$d || empty($d['encr_reference_no'])) {
            return 'This loan has no Collexia payout waiting.';
        }
        $state = (string) ($d['encr_state'] ?? '');
        if ($state === 'Completed') {
            return 'Collexia has already paid this loan out.';
        }
        if ($state === 'Submitted') {
            return 'Collexia has accepted this payout, so the money may still leave. Use "Check payout status" first: only once Collexia reports it failed can the loan be paid another way or the release be cancelled.';
        }
        if (!empty($d['encr_maybe_received'])) {
            return 'The last attempt to send this payout got no clear answer from Collexia, so the money may still leave. Use "Retry payout" (the same reference is re-used, so it cannot be paid twice) or "Check payout status" first.';
        }
        if (!in_array($state, self::ABANDONABLE_STATES, true)) {
            return 'The payout state "' . $state . '" is not clear, so the money may still leave. Check the payout status first.';
        }
        return null;
    }

    /** The day the money left: Collexia's own date when it is a real date between the release and today, otherwise today. */
    public static function paidOnDate(?string $paidOn, string $releasedOn): string
    {
        $today = date('Y-m-d');
        if ($paidOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || strtotime($paidOn) === false) {
            return $today;
        }
        if (strcmp($paidOn, $today) > 0) {
            return $today;
        }
        if ($releasedOn !== '' && strcmp($paidOn, $releasedOn) < 0) {
            return $releasedOn;
        }
        return $paidOn;
    }

    /**
     * Locks a loan that is waiting for its EnCr payout, and its EnCr disbursement.
     * @return array{0: ?array, 1: ?array, 2: ?string} [loan, disbursement, refusal reason or null]
     */
    private function lockWaitingLoan(int $loanId): array
    {
        $locked = $this->loans->findForUpdate($loanId);
        if (!$locked || $locked['loan_status'] !== Loan::STATUS_AWAITING_PAYOUT) {
            return [$locked, null, 'This loan is not waiting for a Collexia payout.'];
        }
        $active = $this->loans->activeDisbursement($loanId);
        $d = $active ? $this->loans->disbursementForUpdate((int) $active['id']) : null;
        if ($d && ($d['status'] ?? '') !== 'Pending') {
            return [$locked, $d, 'This loan\'s payout record is not pending (it is ' . $d['status'] . '), so nothing was changed.'];
        }
        return [$locked, $d, self::whyPayoutCannotBeAbandoned($d)];
    }

    private function createManualDisbursement(int $loanId, array $loan, ?int $userId, ?array $bankAccount, string $method, ?string $referenceNo): int
    {
        return $this->loans->createDisbursement([
            'loan_id' => $loanId,
            'borrower_id' => (int) $loan['borrower_id'],
            'disbursement_no' => generate_reference('DSB'),
            'disbursement_date' => date('Y-m-d'),
            'disbursement_method' => $method,
            'bank_account_id' => $bankAccount ? $bankAccount['id'] : null,
            'amount' => (float) $loan['principal_amount'],
            'reference_no' => $referenceNo,
            'status' => 'Disbursed',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
            'disbursed_by' => $userId,
            'disbursed_at' => date('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    /**
     * Principal and statutory charges are booked as owed the moment the
     * loan is disbursed, not recognized piece by piece as collected:
     *
     *   Dr Loans Receivable (principal)
     *   Dr NAMFISA Levy Receivable (levy)
     *   Dr Stamp Duty Receivable (stamp)
     *     Cr Bank Account (principal -- what actually leaves the bank)
     *     Cr NAMFISA Levy Payable / Cr Stamp Duty Payable (statutory
     *       amounts owed to government, collected from the borrower via
     *       the loan; these don't move on collection -- they're already
     *       fully booked here, both as what's owed FROM the borrower and
     *       what's owed TO the authority)
     *
     * Interest is NOT booked here -- for a Progressive loan it's only
     * earned period by period over the loan's term, recognized separately
     * installment by installment as each one's due date arrives (or
     * immediately, for an early payment). An Upfront loan's full interest
     * IS recognized at disbursement, but by a separate call right after
     * this method returns (see postRelease()) -- kept out of this method since
     * it isn't part of the principal/levy/stamp entry itself.
     *
     * (Moved unchanged from LoanController; $journalDate is the only addition -- null/today for a manual release.)
     */
    public function postDisbursementAccounting(int $loanId, array $loan, ?int $userId, ?array $bankAccount = null, ?string $referenceNo = null, ?string $journalDate = null): int
    {
        $principal = (float) $loan['principal_amount'];
        $levyTxn = $this->statutoryCharges->findNamfisaLevyByLoan($loanId);
        $stampTxn = $this->statutoryCharges->findDutyStampByLoan($loanId);
        $levy = $levyTxn ? (float) $levyTxn['levy_amount'] : 0.0;
        $stamp = $stampTxn ? (float) $stampTxn['stamp_amount'] : 0.0;

        $loanReceivable = $this->accounts->idByCode('1020');
        $levyReceivable = $this->accounts->idByCode('1051');
        $stampReceivable = $this->accounts->idByCode('1060');
        $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Bank Account';
        $levyPayable = $this->accounts->idByCode('2030');
        $stampPayable = $this->accounts->idByCode('2040');

        $lines = [
            [
                'account_id' => $loanReceivable,
                'debit' => round($principal, 2),
                'credit' => 0,
                'description' => 'Loan receivable for ' . $loan['loan_no'],
            ],
            [
                'account_id' => $bankGlAccount,
                'debit' => 0,
                'credit' => $principal,
                'description' => 'Loan disbursed from ' . $bankLabel . ' for ' . $loan['loan_no'],
            ],
        ];

        if ($levy > 0) {
            $lines[] = [
                'account_id' => $levyReceivable,
                'debit' => $levy,
                'credit' => 0,
                'description' => 'NAMFISA levy receivable for ' . $loan['loan_no'],
            ];
            $lines[] = [
                'account_id' => $levyPayable,
                'debit' => 0,
                'credit' => $levy,
                'description' => 'NAMFISA levy withheld for ' . $loan['loan_no'],
            ];
        }
        if ($stamp > 0) {
            $lines[] = [
                'account_id' => $stampReceivable,
                'debit' => $stamp,
                'credit' => 0,
                'description' => 'Stamp duty receivable for ' . $loan['loan_no'],
            ];
            $lines[] = [
                'account_id' => $stampPayable,
                'debit' => 0,
                'credit' => $stamp,
                'description' => 'Duty stamp withheld for ' . $loan['loan_no'],
            ];
        }

        // The reference the user typed on the Release form (the bank transfer / payment
        // reference) is what the Cash Book and General Journal display, so the entry can be
        // matched line by line against the bank statement. The loan number is the fallback
        // when none was entered, and stays in the description either way.
        $journalId = $this->journal->post(
            'LOAN_RELEASED',
            'loans',
            $loanId,
            $referenceNo ?: $loan['loan_no'],
            'Loan disbursed: ' . $loan['loan_no'],
            $lines,
            $userId,
            $journalDate
        );

        if ($levyTxn) {
            $this->statutoryCharges->markNamfisaLevyPosted($loanId, $journalId);
        }
        if ($stampTxn) {
            $this->statutoryCharges->markDutyStampPosted($loanId, $journalId);
        }

        return $journalId;
    }
}
