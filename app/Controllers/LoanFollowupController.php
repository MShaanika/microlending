<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\LoanFollowup;
use App\Services\LoanFollowupAlerts;
use App\Services\LoanFollowupFlow;

/**
 * "Loan questions": while the online applications' loans are approved and managed in the old loan system, any admin answers, one question at a time, whether an
 * application was approved, for how much, whether it was paid out and whether each instalment was paid. The answers feed the Creditinfo files.
 *
 * Every answer is checked AGAIN inside the transaction, with the row locked: two admins answering the same question at the same moment cannot overwrite each other.
 */
class LoanFollowupController extends Controller
{
    private LoanFollowup $followups;
    private LoanFollowupFlow $flow;

    public function __construct()
    {
        $this->followups = new LoanFollowup();
        $this->flow = new LoanFollowupFlow();
    }

    public function index(): void
    {
        Auth::authorize('loans.followups');
        $this->followups->syncApplications();

        $set = (new LoanFollowupAlerts())->cards(date('Y-m-d H:i:s'));
        $cards = $set['cards'];
        $waiting = $set['waiting'];

        $this->view('loans/followups/index', [
            'title' => 'Loan Questions',
            'cards' => $cards,
            'waiting' => $waiting,
            'flow' => $this->flow,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('loans.followups');
        $f = $this->followups->find((int) $id);
        $app = $f ? $this->followups->application((int) $id) : null;
        if (!$f || !$app) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/loans/followups');
            return;
        }
        $instalments = $this->followups->instalments((int) $id);
        $this->view('loans/followups/show', [
            'title' => 'Loan ' . $f['application_no'],
            'f' => $f,
            'app' => $app,
            'instalments' => $instalments,
            'payments' => $this->followups->paymentsByInstalment((int) $id),
            'answers' => $this->followups->answers((int) $id),
            'flow' => $this->flow,
            'canReopen' => $f['status'] === 'Open' && $f['disbursed'] === 'Yes' && !$this->anyAnswered($instalments),
        ]);
    }

    public function answer(string $id): void
    {
        Auth::authorize('loans.followups');
        $id = (int) $id;
        $this->requireCsrf();
        $question = (string) ($_POST['question'] ?? '');
        $userId = (int) (Auth::user()['id'] ?? 0) ?: null;
        $now = date('Y-m-d H:i:s');

        try {
            $note = $this->followups->transaction(function () use ($id, $question, $userId, $now): string {
                $f = $this->followups->lock($id);
                $app = $f ? $this->followups->application($id) : null;
                if (!$f || !$app || $f['status'] !== 'Open') {
                    throw new \DomainException('This application is not open any more.');
                }
                // only the question that is really due may be answered, judged on the row as it is NOW
                $due = $this->flow->nextQuestion($f, $this->followups->instalments($id), $now);
                if ($due === null || $due['key'] !== $question) {
                    throw new \DomainException('That question has already been answered or is not due. Here is the current list.');
                }
                $result = $this->flow->answer($f, $question, $_POST, $app, $now);
                $this->followups->updateRecord($id, $result['update'] + ['last_answered_by' => $userId, 'last_answered_at' => $now]);
                $this->followups->logAnswer($id, $question, $result['note'], $userId);
                if ($result['schedule']) {
                    $fresh = $this->followups->find($id);
                    $this->followups->createInstalments($id, $this->flow->schedule((float) $fresh['approved_amount'], (int) ($fresh['term_months'] ?: ($app['requested_term_months'] ?: 1)), (string) $fresh['disbursed_on'], (int) $fresh['pay_day']));
                }
                Audit::log('Update', 'Loan Questions', $f['application_no'] . ': ' . $result['note']);
                return $result['note'];
            });
        } catch (\InvalidArgumentException | \DomainException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect($this->backTo());
            return;
        } catch (\PDOException $e) {
            Session::flash('error', 'Somebody else answered this at the same moment. Here is the current list.');
            $this->redirect($this->backTo());
            return;
        }

        Session::flash('success', 'Saved: ' . $note);
        $this->redirect($this->backTo());
    }

    public function answerInstalment(string $id, string $instalmentId): void
    {
        Auth::authorize('loans.followups');
        $id = (int) $id;
        $this->requireCsrf();
        $userId = (int) (Auth::user()['id'] ?? 0) ?: null;
        $now = date('Y-m-d H:i:s');

        try {
            $note = $this->followups->transaction(function () use ($id, $instalmentId, $userId, $now): string {
                $f = $this->followups->lock($id);
                $i = $this->followups->instalment((int) $instalmentId);
                if (!$f || !$i || (int) $i['followup_id'] !== $id || $f['status'] !== 'Open') {
                    throw new \DomainException('Instalment not found.');
                }
                $due = $this->flow->nextQuestion($f, $this->followups->instalments($id), $now);
                if ($due === null || $due['key'] !== 'instalment' || (int) ($due['instalment_id'] ?? 0) !== (int) $i['id']) {
                    throw new \DomainException('That question has already been answered or is not due. Here is the current list.');
                }
                $result = $this->flow->answerInstalment($i, $_POST, $now, (string) $f['disbursed_on']);
                $this->followups->updateInstalment((int) $i['id'], $result['update'] + ['answered_by' => $userId, 'answered_at' => $now]);
                if ($result['payment'] !== null) {
                    $this->followups->addPayment((int) $i['id'], $result['payment']['amount'], $result['payment']['paid_on'], $userId);
                }
                $this->followups->logAnswer($id, 'instalment', $result['note'], $userId);
                $this->followups->updateRecord($id, ['last_answered_by' => $userId, 'last_answered_at' => $now]);
                Audit::log('Update', 'Loan Questions', $f['application_no'] . ': ' . $result['note']);
                return $result['note'];
            });
        } catch (\InvalidArgumentException | \DomainException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect($this->backTo());
            return;
        } catch (\PDOException $e) {
            Session::flash('error', 'Somebody else answered this at the same moment. Here is the current list.');
            $this->redirect($this->backTo());
            return;
        }

        Session::flash('success', 'Saved: ' . $note);
        $this->redirect($this->backTo());
    }

    /**
     * A wrong amount, term, pay-out date or pay day was entered: start again from the amount question. Only while no instalment has been answered, so no payment
     * already reported is ever thrown away.
     */
    public function reopen(string $id): void
    {
        Auth::authorize('loans.followups');
        $id = (int) $id;
        $this->requireCsrf();
        $userId = (int) (Auth::user()['id'] ?? 0) ?: null;

        try {
            $this->followups->transaction(function () use ($id, $userId): void {
                $f = $this->followups->lock($id);
                if (!$f || $f['status'] !== 'Open' || $f['disbursed'] !== 'Yes') {
                    throw new \DomainException('Only a loan that was marked as paid out can be corrected this way.');
                }
                if ($this->anyAnswered($this->followups->instalments($id))) {
                    throw new \DomainException('Payments were already reported on this loan, so it cannot be restarted. Ask the system owner.');
                }
                $this->followups->deleteInstalments($id);
                $this->followups->updateRecord($id, ['approved_amount' => null, 'term_months' => null, 'disbursed' => null, 'disbursed_on' => null, 'pay_day' => null, 'ask_count' => 0, 'next_ask_at' => null,
                    'last_answered_by' => $userId, 'last_answered_at' => date('Y-m-d H:i:s')]);
                $this->followups->logAnswer($id, 'reopened', 'Restarted from the amount question (the earlier answers stay in this history)', $userId);
                Audit::log('Update', 'Loan Questions', $f['application_no'] . ' restarted from the amount question');
            });
        } catch (\DomainException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/loans/followups/' . $id);
            return;
        }
        Session::flash('success', 'Restarted. Please answer the amount question again.');
        $this->redirect('/loans/followups');
    }

    /** "This is not a real loan" (a test, a duplicate): closes it so it is never asked about or reported. */
    public function ignore(string $id): void
    {
        Auth::authorize('loans.followups');
        $id = (int) $id;
        $this->requireCsrf();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $userId = (int) (Auth::user()['id'] ?? 0) ?: null;
        $closed = $this->followups->closeUnlessPaidOut($id, 'Closed by staff: ' . mb_substr($reason !== '' ? $reason : 'not a real loan', 0, 40), $userId);
        if (!$closed) {
            Session::flash('error', 'Only an application that is still open and has not been paid out can be closed this way.');
            $this->redirect('/loans/followups');
            return;
        }
        $f = $this->followups->find($id);
        $this->followups->logAnswer($id, 'closed', 'Closed by staff: ' . ($reason !== '' ? $reason : 'not a real loan'), $userId);
        Audit::log('Update', 'Loan Questions', ($f['application_no'] ?? $id) . ' closed by staff');
        Session::flash('success', 'Closed.');
        $this->redirect('/loans/followups');
    }

    /** @param array<int, array<string,mixed>> $instalments */
    private function anyAnswered(array $instalments): bool
    {
        foreach ($instalments as $i) {
            if ($i['answered_at'] !== null || $i['paid_amount'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where to go after an answer: the page the pop-up was shown on (an address inside this system only), otherwise the Loan Questions list.
     * Only a plain path is accepted: no scheme, no host, no "//" and no backslash, so it can never send a person to another site.
     */
    private function backTo(): string
    {
        $r = (string) ($_POST['return'] ?? '');
        $base = rtrim(base_url(), '/');
        if ($base !== '' && str_starts_with($r, $base . '/')) {
            $r = substr($r, strlen($base));
        }
        if ($r !== '' && $r[0] === '/' && !str_starts_with($r, '//') && !str_contains($r, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $r) && !preg_match('#^/loans/followups/\d+/(answer|instalments|ignore|reopen)#', $r)) {
            return $r;
        }
        return '/loans/followups';
    }

    private function requireCsrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans/followups');
        }
    }
}
