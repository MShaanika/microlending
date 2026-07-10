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
use App\Controllers\RefundClaimController;
use App\Controllers\AccountingAccountController;
use App\Controllers\BankAccountController;
use App\Controllers\JournalEntryController;
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
use App\Controllers\PortalAuthController;
use App\Controllers\PortalController;

$router->get('/', [AuthController::class, 'showLogin']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);

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
$router->post('/loans/{id}/approve', [LoanController::class, 'approve']);
$router->post('/loans/{id}/release', [LoanController::class, 'release']);

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

// Borrower letter requests (Completion / Consolidation) -- staff fulfil by uploading the prepared PDF
$router->get('/letters', [LetterController::class, 'index']);
$router->post('/letters/{id}/fulfill', [LetterController::class, 'fulfill']);

// Refund claims (submitted via the self-service portal, reviewed by staff)
$router->get('/refund-claims', [RefundClaimController::class, 'index']);
$router->post('/refund-claims/{id}/approve', [RefundClaimController::class, 'approve']);
$router->post('/refund-claims/{id}/reject', [RefundClaimController::class, 'reject']);
$router->post('/refund-claims/{id}/mark-paid', [RefundClaimController::class, 'markPaid']);

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
$router->get('/accounting/journals/create', [JournalEntryController::class, 'create']);
$router->post('/accounting/journals', [JournalEntryController::class, 'store']);
$router->get('/accounting/journals/{id}', [JournalEntryController::class, 'show']);
$router->post('/accounting/journals/{id}/reverse', [JournalEntryController::class, 'reverse']);

$router->get('/accounting/fiscal-years', [FiscalYearController::class, 'index']);
$router->get('/accounting/fiscal-years/create', [FiscalYearController::class, 'create']);
$router->post('/accounting/fiscal-years', [FiscalYearController::class, 'store']);
$router->get('/accounting/fiscal-years/{id}', [FiscalYearController::class, 'show']);
$router->post('/accounting/fiscal-years/{id}/close', [FiscalYearController::class, 'close']);
$router->post('/accounting/fiscal-years/{id}/open', [FiscalYearController::class, 'open']);
$router->post('/accounting/periods/{id}/close', [FiscalYearController::class, 'closePeriod']);
$router->post('/accounting/periods/{id}/reopen', [FiscalYearController::class, 'reopenPeriod']);

$router->get('/accounting/trial-balance', [TrialBalanceController::class, 'index']);
$router->get('/accounting/cash-book', [CashBookController::class, 'index']);
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

$router->get('/portal/dashboard', [PortalController::class, 'dashboard']);
$router->get('/portal/loans', [PortalController::class, 'loans']);
$router->get('/portal/loans/{id}', [PortalController::class, 'loanShow']);
$router->get('/portal/loans/{id}/invoice', [PortalController::class, 'loanInvoice']);

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