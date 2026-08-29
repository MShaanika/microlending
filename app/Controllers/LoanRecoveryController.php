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
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BadDebt;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanWriteOff;

class LoanRecoveryController extends Controller
{
    private LoanRecovery $recoveries;
    private LoanWriteOff $writeOffs;
    private BadDebt $badDebts;
    private Loan $loans;
    private BankAccount $bankAccounts;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->recoveries = new LoanRecovery();
        $this->writeOffs = new LoanWriteOff();
        $this->badDebts = new BadDebt();
        $this->loans = new Loan();
        $this->bankAccounts = new BankAccount();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function create(string $writeOffId): void
    {
        Auth::authorize('accounting.recoveries');
        $writeOff = $this->writeOffs->find((int) $writeOffId);

        if (!$writeOff || $writeOff['status'] !== 'Posted') {
            Session::flash('error', 'Only posted write-offs can have recoveries recorded against them.');
            $this->redirect('/accounting/loan-write-offs');
            return;
        }

        $this->view('accounting/loan_recoveries/create', [
            'title' => 'Record Recovery - ' . $writeOff['write_off_no'],
            'writeOff' => $writeOff,
            'totalRecovered' => $this->writeOffs->totalRecoveredFor((int) $writeOffId),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.recoveries');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs');
            return;
        }

        $writeOffId = (int) ($_POST['write_off_id'] ?? 0);
        $writeOff = $this->writeOffs->find($writeOffId);
        $amount = (float) ($_POST['recovered_amount'] ?? 0);

        if (!$writeOff) {
            Session::flash('error', 'Write-off not found.');
            $this->redirect('/accounting/loan-write-offs');
            return;
        }

        if ($amount <= 0) {
            Session::flash('error', 'Enter a recovered amount greater than zero.');
            $this->redirect('/accounting/loan-write-offs/' . $writeOffId . '/recoveries/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $key = $this->idempotencyKey();
        $bankAccountId = ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null;
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;

        $bankGlAccountId = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $recoveryIncomeId = $this->accounts->idByCode('4040');

        $recoveryDate = $_POST['recovery_date'] ?: date('Y-m-d');
        $referenceNo = trim($_POST['reference_no'] ?? '') ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $successMessage = 'Recovery recorded and posted.';

        try {
            $this->recoveries->transaction(function () use (
                $writeOffId, $writeOff, $amount, $userId, $key, $bankGlAccountId, $recoveryIncomeId,
                $recoveryDate, $referenceNo, $notes, $successMessage
            ) {
                Idempotency::begin($key, 'recovery.store', $userId);

                // Locks the write-off row so a second concurrent recovery
                // against the same write-off can't race this one's
                // totalRecovered() read and bad-debt status transition below.
                $locked = $this->writeOffs->findForUpdate($writeOffId);
                if (!$locked || $locked['status'] !== 'Posted') {
                    throw new \RuntimeException('Only posted write-offs can have recoveries recorded against them.');
                }

                $journalId = $this->journal->post(
                    'LOAN_RECOVERY',
                    'loan_recoveries',
                    null,
                    generate_reference('REC'),
                    'Bad debt recovery for loan ' . $writeOff['loan_no'] . ' (write-off ' . $writeOff['write_off_no'] . ')',
                    [
                        ['account_id' => $bankGlAccountId, 'debit' => $amount, 'credit' => 0],
                        ['account_id' => $recoveryIncomeId, 'debit' => 0, 'credit' => $amount],
                    ],
                    $userId,
                    $recoveryDate
                );

                $recoveryId = $this->recoveries->create([
                    'loan_id' => $writeOff['loan_id'],
                    'borrower_id' => $writeOff['borrower_id'],
                    'branch_id' => $writeOff['branch_id'],
                    'write_off_id' => $writeOffId,
                    'recovery_no' => generate_reference('RCV'),
                    'recovery_date' => $recoveryDate,
                    'recovered_amount' => $amount,
                    'payment_method_id' => null,
                    'reference_no' => $referenceNo,
                    'notes' => $notes,
                    'status' => 'Posted',
                    'received_by' => $userId,
                    'posted_by' => $userId,
                    'posted_at' => date('Y-m-d H:i:s'),
                    'journal_id' => $journalId,
                ]);

                $this->recoveries->createAllocation([
                    'recovery_id' => $recoveryId,
                    'write_off_id' => $writeOffId,
                    'loan_id' => $writeOff['loan_id'],
                    'amount_allocated' => $amount,
                ]);

                $totalRecovered = $this->writeOffs->totalRecoveredFor($writeOffId);
                if (!empty($writeOff['bad_debt_id'])) {
                    $newStatus = $totalRecovered >= (float) $writeOff['outstanding_balance'] ? 'Recovered' : 'Under Recovery';
                    $this->badDebts->updateRecord((int) $writeOff['bad_debt_id'], ['status' => $newStatus]);

                    if ($newStatus === 'Recovered') {
                        $this->loans->updateFields((int) $writeOff['loan_id'], ['loan_status' => 'Recovered - Closed']);
                        $this->loans->logStatus(
                            (int) $writeOff['loan_id'],
                            $writeOff['loan_status'],
                            'Recovered - Closed',
                            $userId,
                            'Fully recovered after write-off ' . $writeOff['write_off_no'] . '.'
                        );
                    }
                }

                Audit::log('Create', 'Accounting', 'Recorded recovery #' . $recoveryId . ' of ' . format_money($amount) . ' for write-off ' . $writeOff['write_off_no'], [], $key);
                Idempotency::complete($key, 'recovery.store', 'REDIRECT', [
                    'flash_type' => 'success',
                    'flash_message' => $successMessage,
                    'redirect' => '/accounting/loan-write-offs/' . $writeOffId,
                ]);
            });
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/accounting/loan-write-offs/' . $writeOffId . '/recoveries/create');
            return;
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/loan-write-offs/' . $writeOffId . '/recoveries/create');
            return;
        }

        Session::flash('success', $successMessage);
        $this->redirect('/accounting/loan-write-offs/' . $writeOffId);
    }
}
