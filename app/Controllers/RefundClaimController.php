<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\RefundClaim;
use App\Services\DocumentGenerationService;
use App\Services\TemplatedSmsService;

class RefundClaimController extends Controller
{
    private RefundClaim $refundClaims;
    private GeneratedDocument $documents;
    private DocumentTemplate $templates;
    private BankAccount $bankAccounts;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->refundClaims = new RefundClaim();
        $this->documents = new GeneratedDocument();
        $this->templates = new DocumentTemplate();
        $this->bankAccounts = new BankAccount();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::authorize('refunds.view');
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('refund_claims/index', [
            'title' => 'Refund Claims',
            'claims' => $this->refundClaims->paginated($status),
            'status' => $status,
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
        ]);
    }

    public function approve(string $id): void
    {
        Auth::authorize('refunds.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/refund-claims');
        }

        $claim = $this->refundClaims->find($id);
        if (!$claim || !in_array($claim['status'], ['Pending', 'Under Review'], true)) {
            Session::flash('error', 'Only pending claims can be approved.');
            $this->redirect('/refund-claims');
        }

        $approvedAmount = (float) ($_POST['approved_amount'] ?? $claim['claim_amount']);
        $userId = Auth::user()['id'] ?? null;
        $this->refundClaims->updateStatus($id, 'Approved', $userId, null, $approvedAmount);

        $smsResult = TemplatedSmsService::send(
            'REFUND_APPROVED_SMS',
            (string) ($claim['borrower_phone'] ?? ''),
            ['borrower_full_name' => $claim['borrower_name'], 'claim_no' => $claim['claim_no']],
            (int) $claim['borrower_id'],
            $userId
        );

        Audit::log('Approve', 'Refund Claims', 'Approved refund claim #' . $id . ' for ' . format_money($approvedAmount) . ' (' . $smsResult['note'] . ')');
        Session::flash('success', 'Refund claim approved (' . $smsResult['note'] . ').');
        $this->redirect('/refund-claims');
    }

    public function reject(string $id): void
    {
        Auth::authorize('refunds.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/refund-claims');
        }

        $reason = trim($_POST['reason'] ?? '') ?: 'Not specified';
        $this->refundClaims->updateStatus($id, 'Rejected', Auth::user()['id'] ?? null, $reason);

        Audit::log('Reject', 'Refund Claims', 'Rejected refund claim #' . $id . ': ' . $reason);
        Session::flash('success', 'Refund claim rejected.');
        $this->redirect('/refund-claims');
    }

    /**
     * Marking a claim paid posts its payout journal automatically (Dr
     * Refunds Payable / Cr the selected bank account) instead of staff
     * doing a separate manual journal entry -- same pattern as
     * LoanRecoveryController::store(). The claim only flips to Paid once
     * the journal posts successfully.
     */
    public function markPaid(string $id): void
    {
        Auth::authorize('refunds.pay');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/refund-claims');
            return;
        }

        $claim = $this->refundClaims->find($id);
        if (!$claim || $claim['status'] !== 'Approved') {
            Session::flash('error', 'Only approved claims can be marked as paid.');
            $this->redirect('/refund-claims');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $amount = (float) ($claim['approved_amount'] ?: $claim['claim_amount']);

        $bankAccountId = ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null;
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;
        $bankGlAccountId = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $refundsPayableId = $this->accounts->idByCode('2020');

        try {
            $journalId = $this->journal->post(
                'REFUND_CLAIM_PAID',
                'refund_claims',
                $id,
                $claim['claim_no'],
                'Refund claim payout for ' . $claim['borrower_name'] . ' (' . $claim['claim_no'] . ')',
                [
                    ['account_id' => $refundsPayableId, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $bankGlAccountId, 'debit' => 0, 'credit' => $amount],
                ],
                $userId
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/refund-claims');
            return;
        }

        $this->refundClaims->updateStatus($id, 'Paid', $userId);
        $this->refundClaims->attachJournal($id, $journalId);

        Audit::log('Pay', 'Refund Claims', 'Marked refund claim #' . $id . ' as paid and posted journal for ' . format_money($amount));
        Session::flash('success', 'Refund claim marked as paid and journal posted.');
        $this->redirect('/refund-claims');
    }

    /**
     * Generate the claim form or the client letter straight from the
     * template engine -- a fresh generated_documents row is created each
     * time so earlier copies stay on record.
     */
    public function generateDocument(string $id): void
    {
        Auth::authorize('refunds.view');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/refund-claims');
            return;
        }

        $claim = $this->refundClaims->find($id);
        if (!$claim) {
            Session::flash('error', 'Refund claim not found.');
            $this->redirect('/refund-claims');
            return;
        }

        $templateCode = $_POST['template_code'] ?? '';
        if (!in_array($templateCode, ['REFUND_CLAIM_FORM', 'REFUND_CLAIM_LETTER'], true)) {
            Session::flash('error', 'Select which document to generate.');
            $this->redirect('/refund-claims');
            return;
        }

        $template = $this->templates->findByCode($templateCode);
        if (!$template) {
            Session::flash('error', 'That template is not configured.');
            $this->redirect('/refund-claims');
            return;
        }

        $documentId = $this->documents->create([
            'template_id' => $template['id'],
            'document_no' => generate_reference('DOC'),
            'document_title' => $template['template_name'] . ' - ' . $claim['claim_no'],
            'borrower_id' => $claim['borrower_id'],
            'loan_id' => $claim['loan_id'],
            'refund_claim_id' => $id,
            'source_module' => 'Refund Claims',
            'status' => 'Draft',
        ]);

        $document = $this->documents->find($documentId);

        try {
            $filePath = DocumentGenerationService::generate($document);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/refund-claims');
            return;
        }

        $this->documents->markFulfilled($documentId, $filePath, Auth::user()['id'] ?? null);

        Audit::log('Generate', 'Documents', 'Generated ' . $template['template_name'] . ' for refund claim #' . $id);
        Session::flash('success', $template['template_name'] . ' generated. Find it under Letters to download.');
        $this->redirect('/refund-claims');
    }
}
