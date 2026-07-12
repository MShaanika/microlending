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
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;

class ExpenseController extends Controller
{
    private Expense $expenses;
    private ExpenseCategory $categories;
    private Branch $branches;
    private BankAccount $bankAccounts;
    private PaymentMethod $paymentMethods;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct()
    {
        $this->expenses = new Expense();
        $this->categories = new ExpenseCategory();
        $this->branches = new Branch();
        $this->bankAccounts = new BankAccount();
        $this->paymentMethods = new PaymentMethod();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::authorize('expenses.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $categoryId = (int) ($_GET['category_id'] ?? 0);

        $this->view('expenses/index', [
            'title' => 'Expenses',
            'expenses' => $this->expenses->paginated($status, $categoryId),
            'categories' => $this->categories->allCategories(true),
            'status' => $status,
            'categoryId' => $categoryId,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('expenses.create');
        $this->view('expenses/create', [
            'title' => 'Capture Expense',
            'categories' => $this->categories->allCategories(true),
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('expenses.create');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/create');
            return;
        }

        $errors = $this->validate($_POST);
        $attachmentError = $this->validateAttachment($_FILES['attachment'] ?? null);
        if ($attachmentError) {
            $errors['attachment'] = $attachmentError;
        }

        if (!empty($errors)) {
            $this->view('expenses/create', [
                'title' => 'Capture Expense',
                'categories' => $this->categories->allCategories(true),
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $amount = (float) $_POST['amount'];
        $taxAmount = (float) ($_POST['tax_amount'] ?? 0);
        $expenseNo = generate_reference('EXP');

        $expenseId = $this->expenses->create([
            'branch_id' => (int) $_POST['branch_id'],
            'category_id' => (int) $_POST['category_id'],
            'expense_no' => $expenseNo,
            'expense_date' => $_POST['expense_date'],
            'supplier_name' => trim($_POST['supplier_name'] ?? '') ?: null,
            'description' => trim($_POST['description']),
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total_amount' => round($amount + $taxAmount, 2),
            'reference_no' => trim($_POST['reference_no'] ?? '') ?: null,
            'status' => 'Draft',
            'captured_by' => $userId,
        ]);

        $this->storeAttachment($expenseId, $expenseNo, $_FILES['attachment'] ?? null, $userId);

        Audit::log('Create', 'Expenses', 'Captured expense #' . $expenseId . ' (' . $expenseNo . ')');
        Session::flash('success', 'Expense captured as a draft. Submit it for approval when ready.');
        $this->redirect('/expenses/' . $expenseId);
    }

    public function edit(string $id): void
    {
        Auth::authorize('expenses.create');
        $expense = $this->expenses->find((int) $id);

        if (!$expense || $expense['status'] !== 'Draft') {
            Session::flash('error', 'Only draft expenses can be edited.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $this->view('expenses/edit', [
            'title' => 'Edit Expense - ' . $expense['expense_no'],
            'expense' => $expense,
            'categories' => $this->categories->allCategories(true),
            'branches' => $this->branches->all(),
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('expenses.create');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id . '/edit');
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || $expense['status'] !== 'Draft') {
            Session::flash('error', 'Only draft expenses can be edited.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $errors = $this->validate($_POST);
        $attachmentError = $this->validateAttachment($_FILES['attachment'] ?? null, true);
        if ($attachmentError) {
            $errors['attachment'] = $attachmentError;
        }

        if (!empty($errors)) {
            $this->view('expenses/edit', [
                'title' => 'Edit Expense - ' . $expense['expense_no'],
                'expense' => array_merge($expense, $_POST),
                'categories' => $this->categories->allCategories(true),
                'branches' => $this->branches->all(),
                'errors' => $errors,
            ]);
            return;
        }

        $amount = (float) $_POST['amount'];
        $taxAmount = (float) ($_POST['tax_amount'] ?? 0);

        $this->expenses->updateRecord($id, [
            'branch_id' => (int) $_POST['branch_id'],
            'category_id' => (int) $_POST['category_id'],
            'expense_date' => $_POST['expense_date'],
            'supplier_name' => trim($_POST['supplier_name'] ?? '') ?: null,
            'description' => trim($_POST['description']),
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total_amount' => round($amount + $taxAmount, 2),
            'reference_no' => trim($_POST['reference_no'] ?? '') ?: null,
        ]);

        $this->storeAttachment($id, $expense['expense_no'], $_FILES['attachment'] ?? null, Auth::user()['id'] ?? null);

        Audit::log('Update', 'Expenses', 'Updated draft expense #' . $id);
        Session::flash('success', 'Expense updated.');
        $this->redirect('/expenses/' . $id);
    }

    public function show(string $id): void
    {
        Auth::authorize('expenses.view');
        $expense = $this->expenses->find((int) $id);

        if (!$expense) {
            Session::flash('error', 'Expense not found.');
            $this->redirect('/expenses');
            return;
        }

        $this->view('expenses/show', [
            'title' => 'Expense ' . $expense['expense_no'],
            'expense' => $expense,
            'attachments' => $this->expenses->attachmentsFor((int) $id),
            'approvals' => $this->expenses->approvalsFor((int) $id),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'paymentMethods' => $this->paymentMethods->allMethods(),
        ]);
    }

    public function submit(string $id): void
    {
        Auth::authorize('expenses.create');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || $expense['status'] !== 'Draft') {
            Session::flash('error', 'Only draft expenses can be submitted.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $this->expenses->updateRecord($id, ['status' => 'Pending Approval']);
        Audit::log('Submit', 'Expenses', 'Submitted expense #' . $id . ' for approval');
        Session::flash('success', 'Expense submitted for approval.');
        $this->redirect('/expenses/' . $id);
    }

    public function approve(string $id): void
    {
        Auth::authorize('expenses.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || $expense['status'] !== 'Pending Approval') {
            Session::flash('error', 'Only expenses pending approval can be approved.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->expenses->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $this->expenses->addApproval([
            'expense_id' => $id,
            'approval_level' => 1,
            'approver_id' => $userId,
            'status' => 'Approved',
            'comments' => trim($_POST['comments'] ?? '') ?: null,
            'actioned_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Approve', 'Expenses', 'Approved expense #' . $id);
        Session::flash('success', 'Expense approved. It can now be paid.');
        $this->redirect('/expenses/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('expenses.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || $expense['status'] !== 'Pending Approval') {
            Session::flash('error', 'Only expenses pending approval can be rejected.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            Session::flash('error', 'A rejection reason is required.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->expenses->updateRecord($id, [
            'status' => 'Rejected',
            'rejection_reason' => $reason,
        ]);
        $this->expenses->addApproval([
            'expense_id' => $id,
            'approval_level' => 1,
            'approver_id' => $userId,
            'status' => 'Rejected',
            'comments' => $reason,
            'actioned_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Reject', 'Expenses', 'Rejected expense #' . $id);
        Session::flash('success', 'Expense rejected.');
        $this->redirect('/expenses/' . $id);
    }

    /**
     * The one place accounting is actually affected -- cash-basis, same as
     * every other posting flow in this system: Dr the category's expense
     * account, Cr the bank account actually paid from (or the default 1010
     * fallback for a Cash payment with no specific account selected, same
     * convention Payment::postCollectionAccounting() uses).
     */
    public function pay(string $id): void
    {
        Auth::authorize('expenses.pay');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || $expense['status'] !== 'Approved') {
            Session::flash('error', 'Only approved expenses can be paid.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $paymentMethodId = (int) ($_POST['payment_method_id'] ?? 0) ?: null;
        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0) ?: null;
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;

        $expenseAccountId = (int) $expense['category_account_id'];
        if (!$expenseAccountId) {
            Session::flash('error', 'This expense\'s category has no GL account configured.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Bank Account';
        $userId = Auth::user()['id'] ?? null;
        $total = round((float) $expense['total_amount'], 2);

        try {
            $journalId = $this->journal->post(
                'EXPENSE_PAID',
                'expenses',
                $id,
                $expense['expense_no'],
                'Expense paid: ' . $expense['description'],
                [
                    ['account_id' => $expenseAccountId, 'debit' => $total, 'credit' => 0, 'description' => $expense['category_name'] . ' - ' . $expense['expense_no']],
                    ['account_id' => $bankGlAccount, 'debit' => 0, 'credit' => $total, 'description' => 'Paid from ' . $bankLabel],
                ],
                $userId,
                date('Y-m-d')
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/expenses/' . $id);
            return;
        }

        $this->expenses->updateRecord($id, [
            'status' => 'Paid',
            'payment_method_id' => $paymentMethodId,
            'bank_account_id' => $bankAccountId,
            'paid_by' => $userId,
            'paid_at' => date('Y-m-d H:i:s'),
            'journal_id' => $journalId,
        ]);

        Audit::log('Pay', 'Expenses', 'Paid expense #' . $id . ' (' . format_money($total) . ')');
        Session::flash('success', 'Expense paid and journal entry posted.');
        $this->redirect('/expenses/' . $id);
    }

    public function cancel(string $id): void
    {
        Auth::authorize('expenses.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $expense = $this->expenses->find($id);
        if (!$expense || !in_array($expense['status'], ['Draft', 'Pending Approval'], true)) {
            Session::flash('error', 'Only a draft or pending expense can be cancelled.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $this->expenses->updateRecord($id, ['status' => 'Cancelled']);
        Audit::log('Cancel', 'Expenses', 'Cancelled expense #' . $id);
        Session::flash('success', 'Expense cancelled.');
        $this->redirect('/expenses/' . $id);
    }

    public function downloadAttachment(string $id, string $attachmentId): void
    {
        Auth::authorize('expenses.view');
        $attachment = $this->expenses->findAttachment((int) $id, (int) $attachmentId);

        if (!$attachment) {
            Session::flash('error', 'Attachment not found.');
            $this->redirect('/expenses/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $attachment['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/expenses/' . $id);
            return;
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

    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['branch_id'])) {
            $errors['branch_id'] = 'Branch is required.';
        }
        if (empty($data['category_id'])) {
            $errors['category_id'] = 'Category is required.';
        }
        if (trim((string) ($data['expense_date'] ?? '')) === '') {
            $errors['expense_date'] = 'Expense date is required.';
        }
        if (trim((string) ($data['description'] ?? '')) === '') {
            $errors['description'] = 'Description is required.';
        }
        if (!isset($data['amount']) || (float) $data['amount'] <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }
        return $errors;
    }

    private function validateAttachment(?array $file, bool $optional = false): ?string
    {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed. Please try again.';
        }
        if ($file['size'] > self::MAX_DOCUMENT_SIZE) {
            return 'File is too large (max 5MB).';
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return 'Only PDF, JPG and PNG files are allowed.';
        }
        return null;
    }

    private function storeAttachment(int $expenseId, string $expenseNo, ?array $file, ?int $userId): void
    {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $expenseNo);
        $targetDir = STORAGE_PATH . '/uploads/expenses/' . $safeFolder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $storedName = uniqid('receipt_', true) . '.' . $ext;
        $destination = $targetDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return;
        }

        $this->expenses->addAttachment([
            'expense_id' => $expenseId,
            'attachment_name' => $file['name'],
            'attachment_type' => $ext,
            'file_path' => 'uploads/expenses/' . $safeFolder . '/' . $storedName,
            'uploaded_by' => $userId,
        ]);
    }
}
