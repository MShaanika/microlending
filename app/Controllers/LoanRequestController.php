<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\LoanRequest;

class LoanRequestController extends Controller
{
    private LoanRequest $loanRequests;

    public function __construct()
    {
        $this->loanRequests = new LoanRequest();
    }

    public function index(): void
    {
        Auth::authorize('loans.view');
        $filter = \App\Support\ListFilter::fromRequest($_GET);
        $range = \App\Support\ListFilter::isRange($filter);
        $status = $filter['status'];
        $limit = $filter['export'] ? \App\Support\ListFilter::EXPORT_LIMIT : ($range ? 1000 : 100);
        $requests = $this->loanRequests->paginated($status, $limit, $filter['q'], $filter);

        if ($filter['export']) {
            $this->exportList('Loan requests', 'loan_requests', ['Request No', 'Borrower', 'Type', 'Amount', 'Term (months)', 'Purpose', 'Status', 'Submitted', 'Reviewed by'], array_map(static fn ($r) => [
                $r['request_no'], $r['borrower_name'], $r['request_type'] ?? '', number_format((float) $r['requested_amount'], 2, '.', ''), $r['requested_term_months'], $r['purpose'], $r['status'], substr((string) $r['requested_at'], 0, 10), $r['reviewed_by_name'],
            ], $requests), $filter);
        }

        $this->listPage('loan_requests/index', [
            'title' => 'Borrower Loan Requests',
            'requests' => $requests,
            'status' => $status,
            'search' => $filter['q'],
            'filter' => $filter,
            'filtersActive' => $filter['q'] !== '' || $status !== '' || $range,
            'limit' => $limit,
        ]);
    }

    public function approve(string $id): void
    {
        Auth::authorize('loans.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-requests');
        }

        $request = $this->loanRequests->find($id);
        if (!$request || $request['status'] !== 'Pending') {
            Session::flash('error', 'Only pending requests can be approved.');
            $this->redirect('/loan-requests');
        }

        $this->loanRequests->updateStatus($id, 'Approved', Auth::user()['id'] ?? null, trim($_POST['notes'] ?? '') ?: null);

        Audit::log('Approve', 'Loan Requests', 'Approved loan request #' . $id);
        Session::flash('success', 'Request approved. Create the loan for ' . $request['borrower_name'] . ' from the New Loan screen.');
        $this->redirect('/loans/create?borrower_id=' . $request['borrower_id']);
    }

    public function reject(string $id): void
    {
        Auth::authorize('loans.deny');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-requests');
        }

        $request = $this->loanRequests->find($id);
        if (!$request || $request['status'] !== 'Pending') {
            Session::flash('error', 'Only pending requests can be rejected.');
            $this->redirect('/loan-requests');
        }

        $this->loanRequests->updateStatus($id, 'Rejected', Auth::user()['id'] ?? null, trim($_POST['notes'] ?? '') ?: null);

        Audit::log('Reject', 'Loan Requests', 'Rejected loan request #' . $id);
        Session::flash('success', 'Request rejected.');
        $this->redirect('/loan-requests');
    }

    public function documents(string $id): void
    {
        Auth::authorize('loans.view');
        $request = $this->loanRequests->find((int) $id);
        if (!$request) {
            Session::flash('error', 'Loan request not found.');
            $this->redirect('/loan-requests');
        }

        $this->view('loan_requests/documents', [
            'title' => 'Documents for ' . $request['request_no'],
            'request' => $request,
            'documents' => $this->loanRequests->documentsFor((int) $id),
        ]);
    }

    public function downloadDocument(string $id, string $documentId): void
    {
        Auth::authorize('loans.view');
        $documents = $this->loanRequests->documentsFor((int) $id);
        $document = null;
        foreach ($documents as $d) {
            if ((int) $d['id'] === (int) $documentId) {
                $document = $d;
                break;
            }
        }

        if (!$document) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/loan-requests');
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/loan-requests');
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
