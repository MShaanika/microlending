<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\RecurringJournalTemplate;

class RecurringJournalController extends Controller
{
    private RecurringJournalTemplate $templates;
    private AccountingAccount $accounts;

    public function __construct()
    {
        $this->templates = new RecurringJournalTemplate();
        $this->accounts = new AccountingAccount();
    }

    public function index(): void
    {
        Auth::authorize('accounting.recurring_journals');
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('accounting/recurring_journals/index', [
            'title' => 'Recurring Journals',
            'rows' => $this->templates->paginated($status),
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('accounting.recurring_journals');
        $this->view('accounting/recurring_journals/create', [
            'title' => 'New Recurring Journal',
            'accounts' => $this->accounts->allAccounts(true),
            'old' => [],
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.recurring_journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/recurring-journals/create');
            return;
        }

        [$data, $error] = $this->validateInput($_POST);

        if ($error) {
            $this->view('accounting/recurring_journals/create', [
                'title' => 'New Recurring Journal',
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $error,
            ]);
            return;
        }

        $data['template_no'] = generate_reference('REC');
        $data['next_run_date'] = $data['start_date'];
        $data['status'] = 'Active';
        $data['created_by'] = Auth::user()['id'] ?? null;

        $id = $this->templates->create($data);

        Audit::log('Create', 'Accounting', 'Created recurring journal template #' . $id . ': ' . $data['description']);
        Session::flash('success', 'Recurring journal template created.');
        $this->redirect('/accounting/recurring-journals/' . $id);
    }

    public function show(string $id): void
    {
        Auth::authorize('accounting.recurring_journals');
        $template = $this->templates->find((int) $id);
        if (!$template) {
            Session::flash('error', 'Recurring journal template not found.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        $this->view('accounting/recurring_journals/show', [
            'title' => $template['description'],
            'template' => $template,
            'journals' => $this->templates->generatedJournals((int) $id),
        ]);
    }

    public function edit(string $id): void
    {
        Auth::authorize('accounting.recurring_journals');
        $template = $this->templates->find((int) $id);
        if (!$template) {
            Session::flash('error', 'Recurring journal template not found.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        $this->view('accounting/recurring_journals/edit', [
            'title' => 'Edit ' . $template['description'],
            'template' => $template,
            'accounts' => $this->accounts->allAccounts(true),
            'old' => $template,
            'error' => null,
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('accounting.recurring_journals');
        $id = (int) $id;
        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Recurring journal template not found.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/recurring-journals/' . $id . '/edit');
            return;
        }

        [$data, $error] = $this->validateInput($_POST);

        if ($error) {
            $this->view('accounting/recurring_journals/edit', [
                'title' => 'Edit ' . $template['description'],
                'template' => $template,
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $error,
            ]);
            return;
        }

        // Only reset next_run_date to the new start_date if it hasn't fired
        // yet -- editing an already-running schedule shouldn't restart it.
        if (!$template['last_run_at'] && $data['start_date'] !== $template['start_date']) {
            $data['next_run_date'] = $data['start_date'];
        }

        $this->templates->updateFields($id, $data);

        Audit::log('Update', 'Accounting', 'Updated recurring journal template #' . $id);
        Session::flash('success', 'Recurring journal template updated.');
        $this->redirect('/accounting/recurring-journals/' . $id);
    }

    public function pause(string $id): void
    {
        $this->setStatus($id, 'Inactive', 'paused');
    }

    public function resume(string $id): void
    {
        $this->setStatus($id, 'Active', 'resumed');
    }

    private function setStatus(string $id, string $status, string $verb): void
    {
        Auth::authorize('accounting.recurring_journals');
        $id = (int) $id;
        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Recurring journal template not found.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/recurring-journals/' . $id);
            return;
        }

        if ($template['status'] === 'Expired') {
            Session::flash('error', 'This template has expired and cannot be paused or resumed.');
            $this->redirect('/accounting/recurring-journals/' . $id);
            return;
        }

        $this->templates->updateFields($id, ['status' => $status]);

        Audit::log('Update', 'Accounting', 'Recurring journal template #' . $id . ' ' . $verb);
        Session::flash('success', 'Template ' . $verb . '.');
        $this->redirect('/accounting/recurring-journals/' . $id);
    }

    public function delete(string $id): void
    {
        Auth::authorize('accounting.recurring_journals');
        $id = (int) $id;
        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Recurring journal template not found.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/recurring-journals');
            return;
        }

        $this->templates->delete($id);

        Audit::log('Delete', 'Accounting', 'Deleted recurring journal template #' . $id . ': ' . $template['description']);
        Session::flash('success', 'Recurring journal template deleted. Journals it already generated are unaffected.');
        $this->redirect('/accounting/recurring-journals');
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function validateInput(array $post): array
    {
        $description = trim($post['description'] ?? '');
        $debitAccountId = (int) ($post['debit_account_id'] ?? 0);
        $creditAccountId = (int) ($post['credit_account_id'] ?? 0);
        $amount = (float) ($post['amount'] ?? 0);
        $frequency = $post['frequency'] ?? 'Monthly';
        $startDate = $post['start_date'] ?? '';
        $endDate = trim($post['end_date'] ?? '') ?: null;

        if ($description === '') {
            return [[], 'Description is required.'];
        }
        if (!$debitAccountId || !$creditAccountId) {
            return [[], 'Select both a debit account and a credit account.'];
        }
        if ($debitAccountId === $creditAccountId) {
            return [[], 'Debit and credit accounts must be different.'];
        }
        if ($amount <= 0) {
            return [[], 'Amount must be greater than zero.'];
        }
        if (!in_array($frequency, ['Weekly', 'Monthly', 'Quarterly', 'Annually'], true)) {
            return [[], 'Select a valid frequency.'];
        }
        if ($startDate === '') {
            return [[], 'Start date is required.'];
        }
        if ($endDate && $endDate < $startDate) {
            return [[], 'End date cannot be before the start date.'];
        }

        return [[
            'description' => $description,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'amount' => $amount,
            'frequency' => $frequency,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], null];
    }
}
