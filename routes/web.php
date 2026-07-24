<?php
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\BorrowerController;
use App\Controllers\LoanProductController;
use App\Controllers\LoanController;
use App\Controllers\LoanRequestController;
use App\Controllers\PaymentController;
use App\Controllers\AssetController;
use App\Controllers\LetterController;
use App\Controllers\TemplateController;
use App\Controllers\GeneratedDocumentController;
use App\Controllers\RefundClaimController;
use App\Controllers\AccountingAccountController;
use App\Controllers\BankAccountController;
use App\Controllers\JournalEntryController;
use App\Controllers\AdjustmentJournalController;
use App\Controllers\RecurringJournalController;
use App\Controllers\GeneralLedgerController;
use App\Controllers\FiscalYearController;
use App\Controllers\TrialBalanceController;
use App\Controllers\CashBookController;
use App\Controllers\AfsReportController;
use App\Controllers\BadDebtProvisionController;
use App\Controllers\LoanWriteOffController;
use App\Controllers\LoanRecoveryController;
use App\Controllers\PenaltyAccrualController;
use App\Controllers\BankReconciliationController;
use App\Controllers\UserController;
use App\Controllers\RoleController;
use App\Controllers\PermissionController;
use App\Controllers\CompanySettingController;
use App\Controllers\CollectionsController;
use App\Controllers\ReportController;
use App\Controllers\OperationalReportController;
use App\Controllers\StatutoryChargeSettingController;
use App\Controllers\NotificationTemplateController;
use App\Controllers\NotificationController;
use App\Controllers\NotificationSettingController;
use App\Controllers\NamfisaReportController;
use App\Controllers\PaymentMethodReportController;
use App\Controllers\DutyStampController;
use App\Controllers\QuarterlyReportController;
use App\Controllers\RegulatoryReportController;
use App\Controllers\PortalAuthController;
use App\Controllers\PortalController;
use App\Controllers\ApplicationController;
use App\Controllers\ApplicationIntakeController;
use App\Controllers\IntakeSourceController;
use App\Controllers\RescheduleController;
use App\Controllers\DebitOrderController;
use App\Controllers\DebitOrderCancellationController;
use App\Controllers\DebitOrderRunController;
use App\Controllers\DebitOrderCollectionController;
use App\Controllers\ExpenseController;
use App\Controllers\ExpenseCategoryController;
use App\Controllers\AiSettingController;
use App\Controllers\DocumentationController;

$router->get('/', [AuthController::class, 'showLogin']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/forgot-password', [AuthController::class, 'showForgotForm']);
$router->post('/forgot-password', [AuthController::class, 'sendResetLink']);
$router->get('/reset-password/{token}', [AuthController::class, 'showResetForm']);
$router->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/documentation/{key}/download', [DocumentationController::class, 'download']);

// Borrowers
$router->get('/borrowers', [BorrowerController::class, 'index']);
$router->get('/borrowers/create', [BorrowerController::class, 'create']);
$router->post('/borrowers', [BorrowerController::class, 'store']);
$router->get('/borrowers/{id}', [BorrowerController::class, 'show']);
$router->get('/borrowers/{id}/documents/{documentId}', [BorrowerController::class, 'downloadDocument']);
$router->get('/borrowers/{id}/edit', [BorrowerController::class, 'edit']);
$router->post('/borrowers/{id}', [BorrowerController::class, 'update']);
$router->post('/borrowers/{id}/delete', [BorrowerController::class, 'destroy']);
$router->post('/borrowers/{id}/portal-access', [BorrowerController::class, 'createPortalAccess']);

// Loan Products & Plans
$router->get('/loan-products', [LoanProductController::class, 'index']);
$router->get('/loan-products/create', [LoanProductController::class, 'create']);
$router->post('/loan-products', [LoanProductController::class, 'store']);
$router->post('/loan-products/{id}/plans', [LoanProductController::class, 'addPlan']);

// Loans
$router->get('/loans', [LoanController::class, 'index']);
$router->get('/loans/create', [LoanController::class, 'create']);
$router->post('/loans', [LoanController::class, 'store']);
$router->get('/loans/{id}', [LoanController::class, 'show']);
$router->get('/loans/{id}/statement', [LoanController::class, 'statement']);
$router->get('/loans/{id}/statement.xlsx', [LoanController::class, 'statementExcel']);
$router->post('/loans/{id}/statement/email', [LoanController::class, 'emailStatement']);
$router->post('/loans/{id}/approve', [LoanController::class, 'approve']);
$router->post('/loans/{id}/release', [LoanController::class, 'release']);
$router->get('/loans/{id}/topup-created', [LoanController::class, 'topupCreated']);
$router->post('/loan-topups/{topupId}/reverse', [LoanController::class, 'reverseTopup']);

// Loan Reschedules
$router->get('/reschedules', [RescheduleController::class, 'index']);
$router->get('/loans/{id}/reschedule', [RescheduleController::class, 'create']);
$router->post('/loans/{id}/reschedule/preview', [RescheduleController::class, 'previewAction']);
$router->post('/reschedules', [RescheduleController::class, 'store']);
$router->get('/reschedules/{id}', [RescheduleController::class, 'show']);
$router->post('/reschedules/{id}/approve', [RescheduleController::class, 'approve']);
$router->post('/reschedules/{id}/reject', [RescheduleController::class, 'reject']);
$router->post('/reschedules/{id}/implement', [RescheduleController::class, 'implement']);
$router->get('/reschedules/{id}/generate-letter', [RescheduleController::class, 'generateLetter']);

// Debit Orders & Cancellations
$router->get('/debit-orders', [DebitOrderController::class, 'index']);
$router->get('/loans/{id}/debit-orders/create', [DebitOrderController::class, 'create']);
$router->post('/debit-orders', [DebitOrderController::class, 'store']);
$router->get('/debit-orders/{id}', [DebitOrderController::class, 'show']);
$router->get('/debit-orders/{id}/cancel', [DebitOrderCancellationController::class, 'create']);
$router->get('/debit-order-cancellations', [DebitOrderCancellationController::class, 'index']);
$router->post('/debit-order-cancellations', [DebitOrderCancellationController::class, 'store']);
$router->get('/debit-order-cancellations/{id}', [DebitOrderCancellationController::class, 'show']);
$router->post('/debit-order-cancellations/{id}/approve', [DebitOrderCancellationController::class, 'approve']);
$router->post('/debit-order-cancellations/{id}/reject', [DebitOrderCancellationController::class, 'reject']);
$router->get('/debit-order-cancellations/{id}/generate-letter', [DebitOrderCancellationController::class, 'generateLetter']);

// Debit Order Batch Collection Runs
$router->get('/debit-order-runs', [DebitOrderRunController::class, 'index']);
$router->get('/debit-order-runs/create', [DebitOrderRunController::class, 'create']);
$router->post('/debit-order-runs', [DebitOrderRunController::class, 'store']);
$router->get('/debit-order-runs/{id}', [DebitOrderRunController::class, 'show']);
$router->get('/debit-order-runs/{id}/export', [DebitOrderRunController::class, 'export']);
$router->post('/debit-order-runs/{id}/submit', [DebitOrderRunController::class, 'submit']);
$router->post('/debit-order-runs/{id}/cancel', [DebitOrderRunController::class, 'cancel']);

$router->get('/debit-order-collections', [DebitOrderCollectionController::class, 'index']);
$router->get('/debit-order-collections/create', [DebitOrderCollectionController::class, 'create']);
$router->post('/debit-order-collections', [DebitOrderCollectionController::class, 'store']);
$router->get('/debit-order-collections/{id}', [DebitOrderCollectionController::class, 'show']);

// Expenses
$router->get('/expenses', [ExpenseController::class, 'index']);
$router->get('/expenses/create', [ExpenseController::class, 'create']);
$router->post('/expenses', [ExpenseController::class, 'store']);
$router->get('/expenses/{id}', [ExpenseController::class, 'show']);
$router->get('/expenses/{id}/edit', [ExpenseController::class, 'edit']);
$router->post('/expenses/{id}', [ExpenseController::class, 'update']);
$router->post('/expenses/{id}/submit', [ExpenseController::class, 'submit']);
$router->post('/expenses/{id}/approve', [ExpenseController::class, 'approve']);
$router->post('/expenses/{id}/reject', [ExpenseController::class, 'reject']);
$router->post('/expenses/{id}/pay', [ExpenseController::class, 'pay']);
$router->post('/expenses/{id}/cancel', [ExpenseController::class, 'cancel']);
$router->get('/expenses/{id}/attachments/{attachmentId}', [ExpenseController::class, 'downloadAttachment']);

$router->get('/expense-categories', [ExpenseCategoryController::class, 'index']);
$router->get('/expense-categories/create', [ExpenseCategoryController::class, 'create']);
$router->post('/expense-categories', [ExpenseCategoryController::class, 'store']);

// Payments / Collections
$router->get('/payments', [PaymentController::class, 'index']);
$router->get('/loans/{id}/payments/create', [PaymentController::class, 'create']);
$router->post('/payments', [PaymentController::class, 'store']);
$router->post('/payments/{id}/confirm', [PaymentController::class, 'confirm']);
$router->post('/payments/{id}/reject', [PaymentController::class, 'reject']);

// Borrower Loan Requests (submitted via the self-service portal, reviewed by staff)
$router->get('/loan-requests', [LoanRequestController::class, 'index']);
$router->post('/loan-requests/{id}/approve', [LoanRequestController::class, 'approve']);
$router->post('/loan-requests/{id}/reject', [LoanRequestController::class, 'reject']);
$router->get('/loan-requests/{id}/documents', [LoanRequestController::class, 'documents']);
$router->get('/loan-requests/{id}/documents/{documentId}', [LoanRequestController::class, 'downloadDocument']);

// Online Loan Applications (submitted publicly via a client website's own form)
$router->get('/applications', [ApplicationController::class, 'index']);
$router->get('/applications/{id}', [ApplicationController::class, 'show']);
$router->post('/applications/{id}/screen', [ApplicationController::class, 'screen']);
$router->post('/applications/{id}/upload-bank-statement', [ApplicationController::class, 'uploadBankStatement']);
$router->post('/applications/{id}/analyze-bank-statements', [ApplicationController::class, 'analyzeBankStatements']);
$router->post('/applications/{id}/approve', [ApplicationController::class, 'approve']);
$router->post('/applications/{id}/reject', [ApplicationController::class, 'reject']);
$router->post('/applications/{id}/convert', [ApplicationController::class, 'convert']);
$router->get('/applications/{id}/documents/{documentId}', [ApplicationController::class, 'downloadDocument']);
$router->get('/applications/{id}/generate/{templateCode}', [ApplicationController::class, 'generateDocument']);

// Public, unauthenticated intake -- an external client website's own form
// POSTs here directly (cross-origin). See ApplicationIntakeController.
$router->post('/api/applications/{sourceCode}', [ApplicationIntakeController::class, 'submit']);

// Borrower letter requests (Completion / Consolidation) -- staff fulfil by uploading the prepared PDF
$router->get('/letters', [LetterController::class, 'index']);
$router->get('/letters/create', [LetterController::class, 'create']);
$router->post('/letters', [LetterController::class, 'store']);
$router->post('/letters/{id}/fulfill', [LetterController::class, 'fulfill']);
$router->post('/letters/{id}/generate', [LetterController::class, 'generate']);
$router->get('/letters/{id}/download', [LetterController::class, 'download']);

// Document Templates
$router->get('/templates', [TemplateController::class, 'index']);
$router->get('/templates/create', [TemplateController::class, 'create']);
$router->post('/templates', [TemplateController::class, 'store']);
$router->get('/templates/{id}/edit', [TemplateController::class, 'edit']);
$router->post('/templates/{id}', [TemplateController::class, 'update']);
$router->get('/templates/{id}/fields', [TemplateController::class, 'fields']);
$router->post('/templates/{id}/fields', [TemplateController::class, 'addField']);
$router->post('/templates/{id}/fields/{fieldId}/delete', [TemplateController::class, 'deleteField']);

// Generated Documents
$router->get('/generated-documents', [GeneratedDocumentController::class, 'index']);
$router->get('/generated-documents/{id}/download', [GeneratedDocumentController::class, 'download']);

// Compliance
$router->get('/compliance/settings', [StatutoryChargeSettingController::class, 'index']);
$router->post('/compliance/settings/namfisa', [StatutoryChargeSettingController::class, 'storeNamfisaSetting']);
$router->post('/compliance/settings/duty-stamp', [StatutoryChargeSettingController::class, 'storeDutyStampSetting']);

$router->get('/compliance/namfisa', [NamfisaReportController::class, 'index']);
$router->post('/compliance/namfisa/mark-submitted', [NamfisaReportController::class, 'markSubmitted']);

$router->get('/compliance/duty-stamps', [DutyStampController::class, 'index']);
$router->post('/compliance/duty-stamps/mark-submitted', [DutyStampController::class, 'markSubmitted']);

$router->get('/compliance/payment-methods', [PaymentMethodReportController::class, 'index']);

$router->get('/compliance/quarterly-reports', [QuarterlyReportController::class, 'index']);
$router->get('/compliance/quarterly-reports/create', [QuarterlyReportController::class, 'create']);
$router->post('/compliance/quarterly-reports', [QuarterlyReportController::class, 'store']);
$router->get('/compliance/quarterly-reports/{id}', [QuarterlyReportController::class, 'show']);
$router->post('/compliance/quarterly-reports/{id}/submit', [QuarterlyReportController::class, 'submit']);
$router->post('/compliance/quarterly-reports/{id}/approve', [QuarterlyReportController::class, 'approve']);
$router->post('/compliance/quarterly-reports/{id}/reject', [QuarterlyReportController::class, 'reject']);
$router->get('/compliance/quarterly-reports/{id}/download', [QuarterlyReportController::class, 'download']);

// Notifications
$router->get('/notifications/templates', [NotificationTemplateController::class, 'index']);
$router->get('/notifications/templates/create', [NotificationTemplateController::class, 'create']);
$router->post('/notifications/templates', [NotificationTemplateController::class, 'store']);
$router->get('/notifications/templates/{id}/edit', [NotificationTemplateController::class, 'edit']);
$router->post('/notifications/templates/{id}', [NotificationTemplateController::class, 'update']);

$router->get('/notifications/sms', [NotificationController::class, 'smsQueue']);
$router->get('/notifications/email', [NotificationController::class, 'emailQueue']);
$router->get('/notifications/compose', [NotificationController::class, 'compose']);
$router->post('/notifications/compose', [NotificationController::class, 'store']);
$router->post('/notifications/{id}/mark-sent', [NotificationController::class, 'markSent']);
$router->post('/notifications/{id}/mark-failed', [NotificationController::class, 'markFailed']);
$router->post('/notifications/{id}/cancel', [NotificationController::class, 'cancel']);
$router->post('/notifications/{id}/send-now', [NotificationController::class, 'sendNow']);

$router->get('/notifications/settings', [NotificationSettingController::class, 'index']);
$router->post('/notifications/settings/email', [NotificationSettingController::class, 'storeEmailSettings']);
$router->post('/notifications/settings/sms', [NotificationSettingController::class, 'storeSmsSettings']);
$router->post('/notifications/settings/email/test', [NotificationSettingController::class, 'testEmail']);
$router->post('/notifications/settings/sms/test', [NotificationSettingController::class, 'testSms']);

$router->get('/settings/intake-sources', [IntakeSourceController::class, 'index']);
$router->post('/settings/intake-sources/{id}/regenerate', [IntakeSourceController::class, 'regenerateToken']);
$router->get('/settings/ai', [AiSettingController::class, 'index']);
$router->post('/settings/ai', [AiSettingController::class, 'store']);
$router->post('/settings/ai/test', [AiSettingController::class, 'test']);

// Refund claims (submitted via the self-service portal, reviewed by staff)
$router->get('/refund-claims', [RefundClaimController::class, 'index']);
$router->post('/refund-claims/{id}/approve', [RefundClaimController::class, 'approve']);
$router->post('/refund-claims/{id}/reject', [RefundClaimController::class, 'reject']);
$router->post('/refund-claims/{id}/mark-paid', [RefundClaimController::class, 'markPaid']);
$router->post('/refund-claims/{id}/generate-document', [RefundClaimController::class, 'generateDocument']);

// Accounting: Chart of Accounts, Bank Accounts, General Ledger
$router->get('/accounting/accounts', [AccountingAccountController::class, 'index']);
$router->get('/accounting/accounts/create', [AccountingAccountController::class, 'create']);
$router->post('/accounting/accounts', [AccountingAccountController::class, 'store']);
$router->get('/accounting/accounts/{id}/edit', [AccountingAccountController::class, 'edit']);
$router->post('/accounting/accounts/{id}', [AccountingAccountController::class, 'update']);

$router->get('/accounting/bank-accounts', [BankAccountController::class, 'index']);
$router->get('/accounting/bank-accounts/create', [BankAccountController::class, 'create']);
$router->post('/accounting/bank-accounts', [BankAccountController::class, 'store']);
$router->get('/accounting/bank-accounts/{id}/edit', [BankAccountController::class, 'edit']);
$router->post('/accounting/bank-accounts/{id}', [BankAccountController::class, 'update']);

$router->get('/accounting/journals', [JournalEntryController::class, 'index']);
$router->get('/accounting/journals/export.xlsx', [JournalEntryController::class, 'exportExcel']);
$router->get('/accounting/journals/create', [JournalEntryController::class, 'create']);
$router->post('/accounting/journals', [JournalEntryController::class, 'store']);
$router->get('/accounting/journals/{id}', [JournalEntryController::class, 'show']);
$router->post('/accounting/journals/{id}/reverse', [JournalEntryController::class, 'reverse']);

$router->get('/accounting/adjustment-journals', [AdjustmentJournalController::class, 'index']);
$router->get('/accounting/adjustment-journals/create', [AdjustmentJournalController::class, 'create']);
$router->post('/accounting/adjustment-journals', [AdjustmentJournalController::class, 'store']);
$router->get('/accounting/adjustment-journals/{id}', [AdjustmentJournalController::class, 'show']);
$router->get('/accounting/adjustment-journals/{id}/edit', [AdjustmentJournalController::class, 'edit']);
$router->post('/accounting/adjustment-journals/{id}', [AdjustmentJournalController::class, 'update']);
$router->post('/accounting/adjustment-journals/{id}/post', [AdjustmentJournalController::class, 'post']);
$router->post('/accounting/adjustment-journals/{id}/reverse', [AdjustmentJournalController::class, 'reverse']);

$router->get('/accounting/recurring-journals', [RecurringJournalController::class, 'index']);
$router->get('/accounting/recurring-journals/create', [RecurringJournalController::class, 'create']);
$router->post('/accounting/recurring-journals', [RecurringJournalController::class, 'store']);
$router->get('/accounting/recurring-journals/{id}', [RecurringJournalController::class, 'show']);
$router->get('/accounting/recurring-journals/{id}/edit', [RecurringJournalController::class, 'edit']);
$router->post('/accounting/recurring-journals/{id}', [RecurringJournalController::class, 'update']);
$router->post('/accounting/recurring-journals/{id}/pause', [RecurringJournalController::class, 'pause']);
$router->post('/accounting/recurring-journals/{id}/resume', [RecurringJournalController::class, 'resume']);
$router->post('/accounting/recurring-journals/{id}/delete', [RecurringJournalController::class, 'delete']);

$router->get('/accounting/general-ledger', [GeneralLedgerController::class, 'index']);
$router->get('/accounting/general-ledger/export.xlsx', [GeneralLedgerController::class, 'exportExcel']);

$router->get('/accounting/fiscal-years', [FiscalYearController::class, 'index']);
$router->get('/accounting/fiscal-years/create', [FiscalYearController::class, 'create']);
$router->post('/accounting/fiscal-years', [FiscalYearController::class, 'store']);
$router->get('/accounting/fiscal-years/{id}', [FiscalYearController::class, 'show']);
$router->post('/accounting/fiscal-years/{id}/close', [FiscalYearController::class, 'close']);
$router->post('/accounting/fiscal-years/{id}/open', [FiscalYearController::class, 'open']);
$router->post('/accounting/periods/{id}/close', [FiscalYearController::class, 'closePeriod']);
$router->post('/accounting/periods/{id}/reopen', [FiscalYearController::class, 'reopenPeriod']);

$router->get('/accounting/trial-balance', [TrialBalanceController::class, 'index']);
$router->get('/accounting/trial-balance/export.xlsx', [TrialBalanceController::class, 'exportExcel']);
$router->get('/accounting/cash-book', [CashBookController::class, 'index']);
$router->get('/accounting/cash-book/export.csv', [CashBookController::class, 'exportCsv']);
$router->get('/accounting/afs-export', [AfsReportController::class, 'index']);
$router->get('/accounting/afs-export/download', [AfsReportController::class, 'export']);

$router->get('/accounting/bad-debt-provisions', [BadDebtProvisionController::class, 'index']);
$router->get('/accounting/bad-debt-provisions/preview', [BadDebtProvisionController::class, 'preview']);
$router->post('/accounting/bad-debt-provisions', [BadDebtProvisionController::class, 'post']);
$router->get('/accounting/bad-debt-provisions/runs/{date}', [BadDebtProvisionController::class, 'show']);
$router->get('/accounting/bad-debts', [BadDebtProvisionController::class, 'badDebts']);
$router->get('/accounting/bad-debts/{id}/write-off/create', [LoanWriteOffController::class, 'create']);

$router->get('/accounting/loan-write-offs', [LoanWriteOffController::class, 'index']);
$router->post('/accounting/loan-write-offs', [LoanWriteOffController::class, 'store']);
$router->get('/accounting/loan-write-offs/{id}', [LoanWriteOffController::class, 'show']);
$router->post('/accounting/loan-write-offs/{id}/approve', [LoanWriteOffController::class, 'approve']);
$router->post('/accounting/loan-write-offs/{id}/post', [LoanWriteOffController::class, 'post']);
$router->get('/accounting/loan-write-offs/{id}/recoveries/create', [LoanRecoveryController::class, 'create']);

$router->post('/accounting/loan-recoveries', [LoanRecoveryController::class, 'store']);

$router->get('/accounting/penalty-accruals', [PenaltyAccrualController::class, 'index']);
$router->get('/accounting/penalty-accruals/preview', [PenaltyAccrualController::class, 'preview']);
$router->post('/accounting/penalty-accruals', [PenaltyAccrualController::class, 'post']);
$router->get('/accounting/penalty-accruals/runs/{date}', [PenaltyAccrualController::class, 'show']);

$router->get('/accounting/bank-reconciliation', [BankReconciliationController::class, 'index']);
$router->get('/accounting/bank-reconciliation/import', [BankReconciliationController::class, 'importForm']);
$router->post('/accounting/bank-reconciliation/import', [BankReconciliationController::class, 'import']);
$router->post('/accounting/bank-reconciliation/match', [BankReconciliationController::class, 'match']);
$router->post('/accounting/bank-reconciliation/unmatch', [BankReconciliationController::class, 'unmatch']);
$router->post('/accounting/bank-reconciliation/create-adjustment', [BankReconciliationController::class, 'createAdjustment']);
$router->post('/accounting/bank-reconciliation/auto-match', [BankReconciliationController::class, 'autoMatch']);
$router->post('/accounting/bank-reconciliation/complete', [BankReconciliationController::class, 'complete']);
$router->post('/accounting/bank-reconciliation/reopen', [BankReconciliationController::class, 'reopen']);

// Settings
$router->get('/settings/users', [UserController::class, 'index']);
$router->get('/settings/users/create', [UserController::class, 'create']);
$router->post('/settings/users', [UserController::class, 'store']);
$router->get('/settings/users/{id}/edit', [UserController::class, 'edit']);
$router->post('/settings/users/{id}', [UserController::class, 'update']);
$router->post('/settings/users/{id}/toggle-active', [UserController::class, 'toggleActive']);
$router->get('/settings/users/{id}/reset-password', [UserController::class, 'resetPasswordForm']);
$router->post('/settings/users/{id}/reset-password', [UserController::class, 'resetPassword']);

$router->get('/settings/roles', [RoleController::class, 'index']);
$router->get('/settings/roles/create', [RoleController::class, 'create']);
$router->post('/settings/roles', [RoleController::class, 'store']);
$router->get('/settings/roles/{id}/edit', [RoleController::class, 'edit']);
$router->post('/settings/roles/{id}', [RoleController::class, 'update']);
$router->get('/settings/roles/{id}/permissions', [RoleController::class, 'permissions']);
$router->post('/settings/roles/{id}/permissions', [RoleController::class, 'updatePermissions']);

$router->get('/settings/permissions', [PermissionController::class, 'index']);

$router->get('/settings/company', [CompanySettingController::class, 'edit']);
$router->post('/settings/company', [CompanySettingController::class, 'update']);

// Collections worklist
$router->get('/collections/worklist', [CollectionsController::class, 'index']);
$router->get('/collections/worklist/{loanId}', [CollectionsController::class, 'show']);
$router->post('/collections/contacts', [CollectionsController::class, 'storeContact']);
$router->post('/collections/promises', [CollectionsController::class, 'storePromise']);
$router->post('/collections/promises/{id}', [CollectionsController::class, 'updatePromise']);
$router->post('/collections/escalations', [CollectionsController::class, 'storeEscalation']);
$router->post('/collections/escalations/{id}/resolve', [CollectionsController::class, 'resolveEscalation']);

// Reports
$router->get('/reports', [ReportController::class, 'index']);
$router->get('/reports/operational', [OperationalReportController::class, 'index']);
$router->get('/reports/regulatory', [RegulatoryReportController::class, 'index']);

// Fixed Assets: Depreciation & Amortization
// Note: routed under /fixed-assets (not /assets) because /assets collides with
// the public/assets static theme directory served by Apache.
$router->get('/fixed-assets', [AssetController::class, 'index']);
$router->get('/fixed-assets/create', [AssetController::class, 'create']);
$router->post('/fixed-assets', [AssetController::class, 'store']);
$router->get('/fixed-assets/{id}', [AssetController::class, 'show']);
$router->post('/fixed-assets/{id}/depreciate', [AssetController::class, 'depreciate']);
$router->post('/fixed-assets/{id}/dispose', [AssetController::class, 'dispose']);

// Borrower self-service portal (separate auth from staff /login)
$router->get('/portal/login', [PortalAuthController::class, 'showLogin']);
$router->post('/portal/login', [PortalAuthController::class, 'login']);
$router->get('/portal/logout', [PortalAuthController::class, 'logout']);
$router->get('/portal/forgot-password', [PortalAuthController::class, 'showForgotForm']);
$router->post('/portal/forgot-password', [PortalAuthController::class, 'sendResetLink']);
$router->get('/portal/reset-password/{token}', [PortalAuthController::class, 'showResetForm']);
$router->post('/portal/reset-password/{token}', [PortalAuthController::class, 'resetPassword']);

$router->get('/portal/dashboard', [PortalController::class, 'dashboard']);
$router->get('/portal/loans', [PortalController::class, 'loans']);
$router->get('/portal/loans/{id}', [PortalController::class, 'loanShow']);
$router->get('/portal/loans/{id}/invoice', [PortalController::class, 'loanInvoice']);
$router->get('/portal/loans/{id}/statement.xlsx', [PortalController::class, 'loanStatementExcel']);

$router->get('/portal/loan-requests', [PortalController::class, 'loanRequestsIndex']);
$router->get('/portal/loan-requests/create', [PortalController::class, 'loanRequestCreate']);
$router->post('/portal/loan-requests', [PortalController::class, 'loanRequestStore']);

$router->get('/portal/payments', [PortalController::class, 'paymentsIndex']);
$router->get('/portal/payments/create', [PortalController::class, 'paymentCreate']);
$router->post('/portal/payments', [PortalController::class, 'paymentStore']);

$router->get('/portal/letters', [PortalController::class, 'letters']);
$router->get('/portal/letters/create', [PortalController::class, 'letterCreate']);
$router->post('/portal/letters', [PortalController::class, 'letterStore']);
$router->get('/portal/letters/{id}/download', [PortalController::class, 'letterDownload']);

$router->get('/portal/refund-claims', [PortalController::class, 'refundClaims']);
$router->get('/portal/refund-claims/create', [PortalController::class, 'refundClaimCreate']);
$router->post('/portal/refund-claims', [PortalController::class, 'refundClaimStore']);