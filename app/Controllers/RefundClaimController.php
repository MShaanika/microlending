<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RefundClaim;

class RefundClaimController extends Controller
{
    private RefundClaim $refundClaims;

    public function __construct()
    {
        $this->refundClaims = new RefundClaim();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('refund_claims/index', [
            'title' => 'Refund Claims',
            'claims' => $this->refundClaims->paginated($status),
            'status' => $status,
        ]);
    }

    public function approve(string $id): void
    {
        Auth::requireLogin();
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
        $this->refundClaims->updateStatus($id, 'Approved', Auth::user()['id'] ?? null, null, $approvedAmount);

        Audit::log('Approve', 'Refund Claims', 'Approved refund claim #' . $id . ' for ' . format_money($approvedAmount));
        Session::flash('success', 'Refund claim approved.');
        $this->redirect('/refund-claims');
    }

    public function reject(string $id): void
    {
        Auth::requireLogin();
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

    public function markPaid(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/refund-claims');
        }

        $claim = $this->refundClaims->find($id);
        if (!$claim || $claim['status'] !== 'Approved') {
            Session::flash('error', 'Only approved claims can be marked as paid.');
            $this->redirect('/refund-claims');
        }

        $this->refundClaims->updateStatus($id, 'Paid', Auth::user()['id'] ?? null);

        Audit::log('Pay', 'Refund Claims', 'Marked refund claim #' . $id . ' as paid');
        Session::flash('success', 'Refund claim marked as paid.');
        $this->redirect('/refund-claims');
    }
}
