<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;

/**
 * One-off clean-up for loans released with a Collexia EnCr payout BEFORE loans waited for Collexia:
 * those were posted at release (journal + Cash Book, Active, interest/fees charged) even though the
 * payout never completed. This undoes that posting the way this app reverses a release
 * (TopUpService::reverseChildTopup(): reversal journals for the LOAN_RELEASED and INTEREST_ACCRUAL
 * entries) and puts the loan in "Awaiting Payout", so it is finalised by the normal Completed check
 * -- or paid another way / cancelled -- like any new EnCr release. Used by bin/encr_unpost_unpaid.php.
 *
 * Interest recognised by the periodic accrual (a journal shared by many loans) is taken back with its
 * own INTEREST_ACCRUAL_REVERSAL entry. A "Not Sent" payout is marked "may have been received", since
 * the old code did not record whether Collexia's answer was clear.
 *
 * Refuses any loan with a payment, a charged penalty, an earned/paid commission, a live debit
 * order, a top-up, or a levy / stamp duty already included in a submission: those need a person
 * to look at them first.
 */
class EncrUnpostService
{
    private \PDO $db;
    private Loan $loans;
    private AccountingJournal $journal;

    public function __construct(?Loan $loans = null, ?AccountingJournal $journal = null)
    {
        $this->db = Database::connection();
        $this->loans = $loans ?? new Loan();
        $this->journal = $journal ?? new AccountingJournal();
    }

    /** Loans posted at release whose EnCr payout is not Completed. */
    public function candidates(): array
    {
        return $this->db->query(
            "SELECT l.id AS loan_id, l.loan_no, l.loan_status, d.id AS disbursement_id, d.encr_reference_no, d.encr_state, d.amount, d.created_by
             FROM loan_disbursements d
             JOIN loans l ON l.id = d.loan_id
             WHERE d.encr_reference_no IS NOT NULL AND d.status = 'Disbursed' AND COALESCE(d.encr_state, '') <> 'Completed'
               AND l.loan_status IN ('Active', 'Current')
             ORDER BY l.id"
        )->fetchAll();
    }

    /** What would be done for one candidate, plus every reason it is refused (empty = it can be unposted). */
    public function plan(array $c): array
    {
        $loanId = (int) $c['loan_id'];
        $refusals = [];
        $count = function (string $sql) use ($loanId): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$loanId]);
            return (int) $stmt->fetchColumn();
        };

        if ($count("SELECT COUNT(*) FROM payments WHERE loan_id = ? AND status IN ('Pending', 'Posted')") > 0 || TopUpService::hasAnyPayment($loanId)) {
            $refusals[] = 'it has a payment recorded';
        }
        if ($count("SELECT COUNT(*) FROM penalties WHERE loan_id = ? AND status IN ('Pending', 'Charged', 'Paid')") > 0) {
            $refusals[] = 'it has a penalty charged';
        }
        if ($count("SELECT COUNT(*) FROM debit_orders WHERE loan_id = ? AND status IN ('Active', 'Suspended')") > 0) {
            $refusals[] = 'it has a live debit order (cancel it first)';
        }
        if ($count("SELECT COUNT(*) FROM agent_commissions WHERE loan_id = ? AND (earned_amount > 0 OR paid_amount > 0)") > 0) {
            $refusals[] = 'an agent commission on it has been earned or paid';
        }
        if ($count("SELECT COUNT(*) FROM loans WHERE topup_of_loan_id = ? AND loan_status IN ('Active', 'Current', 'Released', 'Completed', 'Awaiting Payout')") > 0) {
            $refusals[] = 'it has been topped up';
        }
        if ($count("SELECT COUNT(*) FROM namfisa_levy_transactions WHERE loan_id = ? AND status = 'Submitted'") > 0) {
            $refusals[] = 'its NAMFISA levy has already been included in a NAMFISA submission';
        }
        if ($count("SELECT COUNT(*) FROM duty_stamp_transactions WHERE loan_id = ? AND status = 'Submitted'") > 0) {
            $refusals[] = 'its stamp duty has already been included in a duty-stamp submission';
        }
        if (empty($c['created_by'])) {
            $refusals[] = 'the disbursement has no user recorded to post the reversal under';
        }

        $journals = $this->db->prepare(
            "SELECT id, journal_no, source_module, reference_no FROM accounting_journal_entries
             WHERE source_table = 'loans' AND source_id = ? AND status = 'Posted' AND source_module IN ('LOAN_RELEASED', 'INTEREST_ACCRUAL')
             ORDER BY id"
        );
        $journals->execute([$loanId]);
        $journalRows = $journals->fetchAll();

        return ['journals' => $journalRows, 'periodic_interest' => $this->periodicInterest($loanId, $journalRows), 'refusals' => $refusals];
    }

    /**
     * Interest accrued for this loan by the periodic accrual (InterestAccrualService::accrue(), bin/accrue_interest.php):
     * that posts ONE journal per due date for all loans together (source_table 'loan_schedules', no loan id), so it is
     * not among the loan's own journals above and cannot simply be reversed. It is the loan's accrued interest
     * (interest_accruals) less what its own Upfront INTEREST_ACCRUAL journal recognised.
     */
    private function periodicInterest(int $loanId, array $journals): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM interest_accruals WHERE loan_id = ? AND status = 'Accrued'");
        $stmt->execute([$loanId]);
        $accrued = (float) $stmt->fetchColumn();

        $upfrontIds = array_map(fn ($j) => (int) $j['id'], array_filter($journals, fn ($j) => $j['source_module'] === 'INTEREST_ACCRUAL'));
        $upfront = 0.0;
        if ($upfrontIds) {
            $upfront = (float) $this->db->query(
                'SELECT COALESCE(SUM(debit), 0) FROM accounting_journal_lines WHERE journal_id IN (' . implode(',', $upfrontIds) . ')'
            )->fetchColumn();
        }
        return max(0.0, round($accrued - $upfront, 2));
    }

    /**
     * Reverses the release postings and moves the loan to Awaiting Payout, in one transaction.
     * @return array{ok: bool, message: string}
     */
    public function unpost(array $c): array
    {
        $plan = $this->plan($c);
        if ($plan['refusals']) {
            return ['ok' => false, 'message' => 'Refused: ' . implode('; ', $plan['refusals']) . '.'];
        }
        $loanId = (int) $c['loan_id'];
        $userId = (int) $c['created_by'];

        try {
            return $this->loans->transaction(function () use ($c, $plan, $loanId, $userId) {
                $locked = $this->loans->findForUpdate($loanId);
                $d = $this->loans->disbursementForUpdate((int) $c['disbursement_id']);
                if (!$locked || !in_array($locked['loan_status'], ['Active', 'Current'], true) || !$d || $d['status'] !== 'Disbursed' || ($d['encr_state'] ?? '') === 'Completed') {
                    return ['ok' => false, 'message' => 'Skipped: the loan or its payout changed since it was listed.'];
                }

                $reversed = [];
                foreach ($plan['journals'] as $j) {
                    $this->journal->reverse((int) $j['id'], $userId);
                    $reversed[] = $j['journal_no'] . ' (' . $j['source_module'] . ')';
                }

                // Interest the periodic accrual recognised for this loan sits in a journal shared with other loans, so it is
                // taken back with its own entry (the exact opposite of InterestAccrualService::accrue()'s Dr 1030 / Cr 4010).
                $periodic = (float) $plan['periodic_interest'];
                if ($periodic > 0) {
                    $accounts = new AccountingAccount();
                    $this->journal->post(
                        'INTEREST_ACCRUAL_REVERSAL',
                        'loans',
                        $loanId,
                        generate_reference('IAJ'),
                        'Interest accrued for ' . ($c['loan_no'] ?? ('loan #' . $loanId)) . ' taken back: its EnCr payout never completed, so the loan was never paid out',
                        [
                            ['account_id' => $accounts->idByCode('4010'), 'debit' => $periodic, 'credit' => 0],
                            ['account_id' => $accounts->idByCode('1030'), 'debit' => 0, 'credit' => $periodic],
                        ],
                        $userId
                    );
                    $reversed[] = 'periodic interest ' . number_format($periodic, 2) . ' (INTEREST_ACCRUAL_REVERSAL)';
                }

                // Every accrual of this loan is now reversed (above); the rows go, so once the payout completes the interest is
                // recognised again exactly once (recognizeUpfront() / accrue() skip instalments that already have a row).
                $this->db->prepare("DELETE FROM interest_accruals WHERE loan_id = ?")->execute([$loanId]);
                $this->db->prepare("UPDATE namfisa_levy_transactions SET status = 'Calculated', journal_id = NULL WHERE loan_id = ? AND status = 'Posted'")->execute([$loanId]);
                $this->db->prepare("UPDATE duty_stamp_transactions SET status = 'Calculated', journal_id = NULL WHERE loan_id = ? AND status = 'Posted'")->execute([$loanId]);
                $this->db->prepare("DELETE FROM agent_commissions WHERE loan_id = ? AND earned_amount = 0 AND paid_amount = 0")->execute([$loanId]);

                // Before loans waited for Collexia every failed send was recorded as a plain "Not Sent", even when Collexia gave
                // no clear answer (e.g. "192 Internal server error"). So a "Not Sent" payout is treated as "may have been received":
                // it cannot be paid another way or cancelled until a retry or "Check payout status" gives a clear answer.
                $this->loans->updateDisbursementFields((int) $d['id'], [
                    'status' => 'Pending',
                    'disbursed_by' => null,
                    'disbursed_at' => null,
                    'encr_last_message' => substr('Posting undone ' . date('Y-m-d H:i') . ': the loan now waits for Collexia to confirm the payout. (' . ($d['encr_last_message'] ?? '') . ')', 0, 500),
                ] + (($d['encr_state'] ?? '') === 'Not Sent' ? ['encr_maybe_received' => 1] : []));
                $this->loans->updateFields($loanId, ['loan_status' => Loan::STATUS_AWAITING_PAYOUT, 'released_by' => null, 'released_at' => null]);
                $note = 'EnCr payout ' . $d['encr_reference_no'] . ' (' . ($d['encr_state'] ?? '?') . ') never completed, so the release posting was reversed ('
                    . ($reversed ? implode(', ', $reversed) : 'no journals found') . ') and the loan now waits for Collexia.';
                $this->loans->logStatus($loanId, $locked['loan_status'], Loan::STATUS_AWAITING_PAYOUT, $userId, $note);
                Audit::log('Reverse', 'Loans', 'Loan #' . $loanId . ': ' . $note);

                return ['ok' => true, 'message' => $note];
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => 'Nothing was changed: ' . $e->getMessage()];
        }
    }
}
