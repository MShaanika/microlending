<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\NotificationLog;
use App\Models\NotificationQueue;
use App\Models\NotificationTemplate;
use App\Models\RefundClaim;
use App\Services\EmailSenderService;
use App\Services\NotificationMergeService;
use App\Services\SmsSenderService;

class NotificationController extends Controller
{
    private NotificationQueue $queue;
    private NotificationTemplate $templates;
    private NotificationLog $logs;
    private Borrower $borrowers;
    private Loan $loans;
    private LoanApplication $applications;
    private RefundClaim $refundClaims;

    /** Compose is scoped to the two channels that actually have a queue
     *  screen to review them in -- Templates can define WhatsApp/Portal
     *  templates too, but there's nowhere to send those from yet. */
    public const COMPOSE_CHANNELS = ['SMS', 'Email'];

    public function __construct()
    {
        $this->queue = new NotificationQueue();
        $this->templates = new NotificationTemplate();
        $this->logs = new NotificationLog();
        $this->borrowers = new Borrower();
        $this->loans = new Loan();
        $this->applications = new LoanApplication();
        $this->refundClaims = new RefundClaim();
    }

    public function smsQueue(): void
    {
        $this->queueView('SMS', 'SMS Queue');
    }

    public function emailQueue(): void
    {
        $this->queueView('Email', 'Email Queue');
    }

    private function queueView(string $channel, string $title): void
    {
        Auth::authorize('notifications.view');

        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $this->view('notifications/queue/index', [
            'title' => $title,
            'channel' => $channel,
            'status' => $status,
            'search' => $search,
            'items' => $this->queue->paginated($channel, $status, $search),
        ]);
    }

    public function compose(): void
    {
        Auth::authorize('notifications.view');

        $borrowerId = (int) ($_GET['borrower_id'] ?? 0);
        $loanId = (int) ($_GET['loan_id'] ?? 0);
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        $claimId = (int) ($_GET['claim_id'] ?? 0);
        $channel = in_array($_GET['channel'] ?? '', self::COMPOSE_CHANNELS, true) ? $_GET['channel'] : 'SMS';
        $templateId = (int) ($_GET['template_id'] ?? 0);

        $borrower = $borrowerId ? $this->borrowers->find($borrowerId) : null;
        $loan = $loanId ? $this->loans->find($loanId) : null;
        $application = $applicationId ? $this->applications->find($applicationId) : null;
        $claim = $claimId ? $this->refundClaims->find($claimId) : null;
        $borrowerLoans = $borrower ? $this->loans->forBorrower((int) $borrower['id']) : [];

        $context = $this->buildContext($borrower, $loan, $application, $claim);
        $template = $templateId ? $this->templates->find($templateId) : null;
        $previewBody = $template ? NotificationMergeService::render($template['message_body'], $context) : '';
        $previewSubject = $template ? NotificationMergeService::render((string) ($template['subject'] ?? ''), $context) : '';
        $defaultContact = $borrower ? ($channel === 'Email' ? (string) ($borrower['email'] ?? '') : (string) ($borrower['phone'] ?? ''))
            : ($application ? (string) ($application['applicant_phone'] ?? '') : '');

        $this->view('notifications/compose', [
            'title' => 'Compose Notification',
            'channels' => self::COMPOSE_CHANNELS,
            'templates' => $this->templates->allTemplates($channel, true),
            'borrowers' => $this->borrowers->paginated('', '', 500),
            'borrower' => $borrower,
            'loan' => $loan,
            'application' => $application,
            'claim' => $claim,
            'borrowerLoans' => $borrowerLoans,
            'selectedChannel' => $channel,
            'selectedTemplateId' => $templateId,
            'previewBody' => $previewBody,
            'previewSubject' => $previewSubject,
            'defaultContact' => $defaultContact,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('notifications.send');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/compose');
            return;
        }

        $channel = $_POST['channel'] ?? '';
        $recipientContact = trim($_POST['recipient_contact'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $errors = [];

        if (!in_array($channel, self::COMPOSE_CHANNELS, true)) {
            $errors['channel'] = 'Select a channel.';
        }
        if ($recipientContact === '') {
            $errors['recipient_contact'] = 'Recipient ' . ($channel === 'Email' ? 'email address' : 'phone number') . ' is required.';
        }
        if ($message === '') {
            $errors['message'] = 'Message is required.';
        }

        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $claimId = (int) ($_POST['claim_id'] ?? 0);
        $borrower = $borrowerId ? $this->borrowers->find($borrowerId) : null;
        $loan = $loanId ? $this->loans->find($loanId) : null;
        $application = $applicationId ? $this->applications->find($applicationId) : null;
        $claim = $claimId ? $this->refundClaims->find($claimId) : null;

        if (!empty($errors)) {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            $this->view('notifications/compose', [
                'title' => 'Compose Notification',
                'channels' => self::COMPOSE_CHANNELS,
                'templates' => $this->templates->allTemplates($channel ?: 'SMS', true),
                'borrowers' => $this->borrowers->paginated('', '', 500),
                'borrower' => $borrower,
                'loan' => $loan,
                'application' => $application,
                'claim' => $claim,
                'borrowerLoans' => $borrower ? $this->loans->forBorrower((int) $borrower['id']) : [],
                'selectedChannel' => $channel ?: 'SMS',
                'selectedTemplateId' => $templateId,
                'previewBody' => $message,
                'previewSubject' => trim($_POST['subject'] ?? ''),
                'defaultContact' => $recipientContact,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $templateId = (int) ($_POST['template_id'] ?? 0) ?: null;

        $id = $this->queue->create([
            'borrower_id' => $borrower['id'] ?? null,
            'template_id' => $templateId,
            'channel' => $channel,
            'recipient_name' => $borrower ? ($borrower['first_name'] . ' ' . $borrower['last_name']) : (trim($_POST['recipient_name'] ?? '') ?: null),
            'recipient_contact' => $recipientContact,
            'subject' => $channel === 'Email' ? (trim($_POST['subject'] ?? '') ?: null) : null,
            'message' => $message,
            'source_module' => 'Manual',
            'source_table' => $application ? 'loan_applications' : ($claim ? 'refund_claims' : ($loan ? 'loans' : ($borrower ? 'borrowers' : null))),
            'source_id' => $application['id'] ?? $claim['id'] ?? $loan['id'] ?? $borrower['id'] ?? null,
            'status' => 'Pending',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Notifications', 'Queued ' . $channel . ' notification #' . $id);
        Session::flash('success', 'Notification queued.');
        $this->redirect($channel === 'Email' ? '/notifications/email' : '/notifications/sms');
    }

    public function sendNow(string $id): void
    {
        Auth::authorize('notifications.send');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/sms');
            return;
        }

        $item = $this->queue->find($id);
        if (!$item) {
            Session::flash('error', 'Notification not found.');
            $this->redirect('/notifications/sms');
            return;
        }

        $redirectPath = $item['channel'] === 'Email' ? '/notifications/email' : '/notifications/sms';

        if ($item['status'] !== 'Pending') {
            Session::flash('error', 'Only pending notifications can be sent.');
            $this->redirect($redirectPath);
            return;
        }

        $result = $item['channel'] === 'Email'
            ? EmailSenderService::send($item['recipient_contact'], (string) ($item['subject'] ?? 'Notification'), $item['message'], $item['recipient_name'])
            : SmsSenderService::send($item['recipient_contact'], $item['message']);

        if ($result['success']) {
            $this->queue->updateStatus($id, 'Sent');
            $this->logs->create([
                'notification_id' => $id,
                'borrower_id' => $item['borrower_id'],
                'user_id' => Auth::user()['id'] ?? null,
                'channel' => $item['channel'],
                'recipient_contact' => $item['recipient_contact'],
                'message' => $item['message'],
                'status' => 'Sent',
                'provider_reference' => $result['providerReference'],
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('Sent', 'Notifications', 'Sent notification #' . $id . ' via ' . $item['channel']);
            Session::flash('success', $item['channel'] . ' sent to ' . $item['recipient_contact'] . '.');
        } else {
            $this->queue->recordAttemptFailure($id, (string) $result['error']);
            Session::flash('error', 'Send failed: ' . $result['error']);
        }

        $this->redirect($redirectPath);
    }

    public function markSent(string $id): void
    {
        $this->transition($id, 'Sent');
    }

    public function markFailed(string $id): void
    {
        $this->transition($id, 'Failed');
    }

    public function cancel(string $id): void
    {
        $this->transition($id, 'Cancelled');
    }

    private function transition(string $id, string $status): void
    {
        Auth::authorize('notifications.send');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/sms');
            return;
        }

        $item = $this->queue->find($id);
        if (!$item) {
            Session::flash('error', 'Notification not found.');
            $this->redirect('/notifications/sms');
            return;
        }

        $this->queue->updateStatus($id, $status);

        if (in_array($status, ['Sent', 'Failed'], true)) {
            $this->logs->create([
                'notification_id' => $id,
                'borrower_id' => $item['borrower_id'],
                'user_id' => Auth::user()['id'] ?? null,
                'channel' => $item['channel'],
                'recipient_contact' => $item['recipient_contact'],
                'message' => $item['message'],
                'status' => $status,
                'sent_at' => $status === 'Sent' ? date('Y-m-d H:i:s') : null,
            ]);
        }

        Audit::log($status, 'Notifications', 'Marked notification #' . $id . ' as ' . $status);
        Session::flash('success', 'Notification marked as ' . $status . '.');
        $this->redirect($item['channel'] === 'Email' ? '/notifications/email' : '/notifications/sms');
    }

    /**
     * Merge context for NotificationMergeService -- flat map of whichever
     * of the seeded templates' placeholders can actually be resolved from
     * the borrower/loan/application/claim in hand. Missing pieces (e.g. no
     * loan selected) simply leave that placeholder unresolved, handled
     * gracefully by the merge service rather than erroring.
     */
    private function buildContext(?array $borrower, ?array $loan, ?array $application = null, ?array $claim = null): array
    {
        $context = [
            'current_date' => date('d F Y'),
        ];

        if ($borrower) {
            $context['borrower_full_name'] = trim($borrower['first_name'] . ' ' . $borrower['last_name']);
        } elseif ($application) {
            // An application may predate a borrower record existing at all
            // (e.g. still Pending/Rejected) -- fall back to the applicant's
            // own name so {{borrower_full_name}} still resolves.
            $context['borrower_full_name'] = trim($application['applicant_first_name'] . ' ' . $application['applicant_last_name']);
        }

        if ($loan) {
            $context['loan_no'] = $loan['loan_no'];

            $schedule = $this->loans->schedule((int) $loan['id']);
            foreach ($schedule as $row) {
                if ($row['status'] === 'Pending') {
                    $context['amount_due'] = format_money((float) $row['total_due'] - (float) $row['total_paid']);
                    $context['due_date'] = date('d F Y', strtotime($row['due_date']));
                    break;
                }
            }

            $arrears = \App\Services\ArrearsService::loanOutstanding((int) $loan['id'], date('Y-m-d'));
            if (($arrears['days_in_arrears'] ?? 0) > 0) {
                $context['arrears_amount'] = format_money($arrears['outstanding_balance']);
            }
        }

        if ($application) {
            $context['application_no'] = $application['application_no'];
        }

        if ($claim) {
            $context['claim_no'] = $claim['claim_no'];
        }

        return $context;
    }
}
