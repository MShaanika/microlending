<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CaseEscalation;
use App\Models\CollectionContact;
use App\Models\Loan;
use App\Models\PaymentPromise;
use App\Services\ArrearsService;

class CollectionsController extends Controller
{
    private CollectionContact $contacts;
    private PaymentPromise $promises;
    private CaseEscalation $escalations;
    private Loan $loans;

    public function __construct()
    {
        $this->contacts = new CollectionContact();
        $this->promises = new PaymentPromise();
        $this->escalations = new CaseEscalation();
        $this->loans = new Loan();
    }

    public function index(): void
    {
        Auth::authorize('collections.arrears');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

        $loans = ArrearsService::overdueLoans($asOfDate);
        foreach ($loans as &$loan) {
            $loan['contact_count'] = $this->contacts->countForLoan((int) $loan['loan_id']);
            $loan['pending_promise'] = $this->promises->latestPendingForLoan((int) $loan['loan_id']);
            $loan['open_escalation'] = $this->escalations->latestOpenForLoan((int) $loan['loan_id']);
        }
        unset($loan);

        $this->view('collections/worklist/index', [
            'title' => 'Collections Worklist',
            'asOfDate' => $asOfDate,
            'loans' => $loans,
        ]);
    }

    public function show(string $loanId): void
    {
        Auth::authorize('collections.arrears');
        $loanId = (int) $loanId;
        $loan = $this->loans->find($loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/collections/worklist');
            return;
        }

        $this->view('collections/worklist/show', [
            'title' => 'Collections Case - ' . $loan['loan_no'],
            'loan' => $loan,
            'outstanding' => ArrearsService::loanOutstanding($loanId, date('Y-m-d')),
            'contacts' => $this->contacts->forLoan($loanId),
            'promises' => $this->promises->forLoan($loanId),
            'escalations' => $this->escalations->forLoan($loanId),
        ]);
    }

    public function storeContact(): void
    {
        Auth::authorize('collections.arrears');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collections/worklist');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        $notes = trim($_POST['notes'] ?? '');

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/collections/worklist');
            return;
        }
        if ($notes === '') {
            Session::flash('error', 'Contact notes are required.');
            $this->redirect('/collections/worklist/' . $loanId);
            return;
        }

        $this->contacts->create([
            'loan_id' => $loanId,
            'borrower_id' => $loan['borrower_id'],
            'contact_method' => $_POST['contact_method'] ?? 'Phone Call',
            'outcome' => $_POST['outcome'] ?? 'No Answer',
            'notes' => $notes,
            'contacted_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Collections', 'Logged contact for loan ' . $loan['loan_no']);
        Session::flash('success', 'Contact logged.');
        $this->redirect('/collections/worklist/' . $loanId);
    }

    public function storePromise(): void
    {
        Auth::authorize('collections.arrears');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collections/worklist');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        $promiseDate = $_POST['promise_date'] ?? '';

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/collections/worklist');
            return;
        }
        if ($promiseDate === '' || strtotime($promiseDate) === false) {
            Session::flash('error', 'Enter a valid promise date.');
            $this->redirect('/collections/worklist/' . $loanId);
            return;
        }

        $this->promises->create([
            'loan_id' => $loanId,
            'borrower_id' => $loan['borrower_id'],
            'promise_date' => $promiseDate,
            'expected_amount' => (float) ($_POST['expected_amount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'status' => 'Pending',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Collections', 'Recorded promise to pay for loan ' . $loan['loan_no'] . ' on ' . $promiseDate);
        Session::flash('success', 'Promise to pay recorded.');
        $this->redirect('/collections/worklist/' . $loanId);
    }

    public function updatePromise(string $id): void
    {
        Auth::authorize('collections.arrears');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collections/worklist');
            return;
        }

        $promise = $this->promises->find($id);
        if (!$promise) {
            Session::flash('error', 'Promise not found.');
            $this->redirect('/collections/worklist');
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['Kept', 'Broken', 'Cancelled'], true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/collections/worklist/' . $promise['loan_id']);
            return;
        }

        $this->promises->updateStatus($id, $status);

        Audit::log('Update', 'Collections', 'Marked promise #' . $id . ' as ' . $status);
        Session::flash('success', 'Promise updated.');
        $this->redirect('/collections/worklist/' . $promise['loan_id']);
    }

    public function storeEscalation(): void
    {
        Auth::authorize('collections.arrears');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collections/worklist');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        $reason = trim($_POST['reason'] ?? '');

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/collections/worklist');
            return;
        }
        if ($reason === '') {
            Session::flash('error', 'A reason is required to escalate.');
            $this->redirect('/collections/worklist/' . $loanId);
            return;
        }

        $this->escalations->create([
            'loan_id' => $loanId,
            'borrower_id' => $loan['borrower_id'],
            'escalation_level' => $_POST['escalation_level'] ?? 'Supervisor',
            'reason' => $reason,
            'status' => 'Open',
            'escalated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Escalate', 'Collections', 'Escalated loan ' . $loan['loan_no'] . ' to ' . ($_POST['escalation_level'] ?? 'Supervisor'));
        Session::flash('success', 'Case escalated.');
        $this->redirect('/collections/worklist/' . $loanId);
    }

    public function resolveEscalation(string $id): void
    {
        Auth::authorize('collections.arrears');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collections/worklist');
            return;
        }

        $escalation = $this->escalations->find($id);
        if (!$escalation) {
            Session::flash('error', 'Escalation not found.');
            $this->redirect('/collections/worklist');
            return;
        }

        $this->escalations->resolve($id, Auth::user()['id'] ?? 0, trim($_POST['resolution_notes'] ?? ''));

        Audit::log('Resolve', 'Collections', 'Resolved escalation #' . $id);
        Session::flash('success', 'Escalation resolved.');
        $this->redirect('/collections/worklist/' . $escalation['loan_id']);
    }
}
