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
use App\Controllers\SocialAnalyticsController;
use App\Controllers\HrmDepartmentController;
use App\Controllers\HrmDesignationController;
use App\Controllers\HrmShiftController;
use App\Controllers\HrmEmployeeController;
use App\Controllers\HrmAttendanceController;
use App\Controllers\HrmLeaveTypeController;
use App\Controllers\HrmLeaveApplicationController;
use App\Controllers\HrmLeaveBalanceController;
use App\Controllers\HrmHolidayController;
use App\Controllers\HrmAllowanceTypeController;
use App\Controllers\HrmDeductionTypeController;
use App\Controllers\HrmAllowanceController;
use App\Controllers\HrmDeductionController;
use App\Controllers\HrmPayrollController;
use App\Controllers\HrmAwardTypeController;
use App\Controllers\HrmAwardController;
use App\Controllers\HrmComplaintTypeController;
use App\Controllers\HrmComplaintController;
use App\Controllers\HrmWarningTypeController;
use App\Controllers\HrmWarningController;
use App\Controllers\HrmTerminationTypeController;
use App\Controllers\HrmTerminationController;
use App\Controllers\HrmPromotionController;
use App\Controllers\HrmTransferController;
use App\Controllers\HrmAnnouncementCategoryController;
use App\Controllers\HrmAnnouncementController;
use App\Controllers\HrmEventTypeController;
use App\Controllers\HrmEventController;
use App\Controllers\StaffLoanTypeController;
use App\Controllers\HrmZoomMeetingController;
use App\Controllers\HrmZoomSettingController;
use App\Controllers\StaffLoanController;
use App\Controllers\HrmDocumentTypeController;
use App\Controllers\PerformanceIndicatorCategoryController;
use App\Controllers\PerformanceIndicatorController;
use App\Controllers\PerformanceGoalTypeController;
use App\Controllers\PerformanceReviewCycleController;
use App\Controllers\PerformanceEmployeeGoalController;
use App\Controllers\PerformanceEmployeeReviewController;
use App\Controllers\TrainingTypeController;
use App\Controllers\TrainerController;
use App\Controllers\TrainingController;
use App\Controllers\RecruitmentJobTypeController;
use App\Controllers\RecruitmentCandidateSourceController;
use App\Controllers\RecruitmentInterviewTypeController;
use App\Controllers\RecruitmentJobLocationController;
use App\Controllers\RecruitmentCustomQuestionController;
use App\Controllers\RecruitmentJobPostingController;
use App\Controllers\RecruitmentCandidateController;
use App\Controllers\RecruitmentInterviewController;
use App\Controllers\RecruitmentOfferController;
use App\Controllers\RecruitmentOfferLetterTemplateController;
use App\Controllers\RecruitmentOnboardingChecklistController;
use App\Controllers\RecruitmentCandidateOnboardingController;
use App\Controllers\RecruitmentFrontendController;
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
$router->get('/accounting/cash-book/export.xlsx', [CashBookController::class, 'exportExcel']);
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
$router->get('/accounting/bank-reconciliation/history', [BankReconciliationController::class, 'history']);
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

$router->get('/settings/social-analytics', [SocialAnalyticsController::class, 'settingsEdit']);
$router->post('/settings/social-analytics/{id}', [SocialAnalyticsController::class, 'settingsUpdate']);

// Social & Web Analytics
$router->get('/social-analytics', [SocialAnalyticsController::class, 'index']);
$router->post('/social-analytics/{settingId}/entries', [SocialAnalyticsController::class, 'storeMetric']);
$router->post('/social-analytics/entries/{id}/delete', [SocialAnalyticsController::class, 'deleteMetric']);

// HRM: Departments
$router->get('/hrm/departments', [HrmDepartmentController::class, 'index']);
$router->get('/hrm/departments/create', [HrmDepartmentController::class, 'create']);
$router->post('/hrm/departments', [HrmDepartmentController::class, 'store']);
$router->get('/hrm/departments/{id}/edit', [HrmDepartmentController::class, 'edit']);
$router->post('/hrm/departments/{id}', [HrmDepartmentController::class, 'update']);
$router->post('/hrm/departments/{id}/toggle-active', [HrmDepartmentController::class, 'toggleActive']);

// HRM: Designations
$router->get('/hrm/designations', [HrmDesignationController::class, 'index']);
$router->get('/hrm/designations/create', [HrmDesignationController::class, 'create']);
$router->post('/hrm/designations', [HrmDesignationController::class, 'store']);
$router->get('/hrm/designations/{id}/edit', [HrmDesignationController::class, 'edit']);
$router->post('/hrm/designations/{id}', [HrmDesignationController::class, 'update']);
$router->post('/hrm/designations/{id}/toggle-active', [HrmDesignationController::class, 'toggleActive']);

// HRM: Shifts
$router->get('/hrm/shifts', [HrmShiftController::class, 'index']);
$router->get('/hrm/shifts/create', [HrmShiftController::class, 'create']);
$router->post('/hrm/shifts', [HrmShiftController::class, 'store']);
$router->get('/hrm/shifts/{id}/edit', [HrmShiftController::class, 'edit']);
$router->post('/hrm/shifts/{id}', [HrmShiftController::class, 'update']);
$router->post('/hrm/shifts/{id}/toggle-active', [HrmShiftController::class, 'toggleActive']);

// HRM: Employees
$router->get('/hrm/employees', [HrmEmployeeController::class, 'index']);
$router->get('/hrm/employees/create', [HrmEmployeeController::class, 'create']);
$router->post('/hrm/employees', [HrmEmployeeController::class, 'store']);
$router->get('/hrm/employees/{id}', [HrmEmployeeController::class, 'show']);
$router->get('/hrm/employees/{id}/edit', [HrmEmployeeController::class, 'edit']);
$router->post('/hrm/employees/{id}', [HrmEmployeeController::class, 'update']);
$router->post('/hrm/employees/{id}/documents', [HrmEmployeeController::class, 'uploadDocument']);
$router->get('/hrm/employees/{id}/documents/{documentId}/download', [HrmEmployeeController::class, 'downloadDocument']);
$router->post('/hrm/employees/{id}/documents/{documentId}/delete', [HrmEmployeeController::class, 'deleteDocument']);

$router->get('/hrm/document-types', [HrmDocumentTypeController::class, 'index']);
$router->get('/hrm/document-types/create', [HrmDocumentTypeController::class, 'create']);
$router->post('/hrm/document-types', [HrmDocumentTypeController::class, 'store']);
$router->get('/hrm/document-types/{id}/edit', [HrmDocumentTypeController::class, 'edit']);
$router->post('/hrm/document-types/{id}', [HrmDocumentTypeController::class, 'update']);
$router->post('/hrm/document-types/{id}/delete', [HrmDocumentTypeController::class, 'delete']);

// Performance module -- standalone, sits outside Human Resources
$router->get('/performance/indicator-categories', [PerformanceIndicatorCategoryController::class, 'index']);
$router->get('/performance/indicator-categories/create', [PerformanceIndicatorCategoryController::class, 'create']);
$router->post('/performance/indicator-categories', [PerformanceIndicatorCategoryController::class, 'store']);
$router->get('/performance/indicator-categories/{id}/edit', [PerformanceIndicatorCategoryController::class, 'edit']);
$router->post('/performance/indicator-categories/{id}', [PerformanceIndicatorCategoryController::class, 'update']);
$router->post('/performance/indicator-categories/{id}/delete', [PerformanceIndicatorCategoryController::class, 'delete']);

$router->get('/performance/indicators', [PerformanceIndicatorController::class, 'index']);
$router->get('/performance/indicators/create', [PerformanceIndicatorController::class, 'create']);
$router->post('/performance/indicators', [PerformanceIndicatorController::class, 'store']);
$router->get('/performance/indicators/{id}/edit', [PerformanceIndicatorController::class, 'edit']);
$router->post('/performance/indicators/{id}', [PerformanceIndicatorController::class, 'update']);
$router->post('/performance/indicators/{id}/delete', [PerformanceIndicatorController::class, 'delete']);

$router->get('/performance/goal-types', [PerformanceGoalTypeController::class, 'index']);
$router->get('/performance/goal-types/create', [PerformanceGoalTypeController::class, 'create']);
$router->post('/performance/goal-types', [PerformanceGoalTypeController::class, 'store']);
$router->get('/performance/goal-types/{id}/edit', [PerformanceGoalTypeController::class, 'edit']);
$router->post('/performance/goal-types/{id}', [PerformanceGoalTypeController::class, 'update']);
$router->post('/performance/goal-types/{id}/delete', [PerformanceGoalTypeController::class, 'delete']);

$router->get('/performance/review-cycles', [PerformanceReviewCycleController::class, 'index']);
$router->get('/performance/review-cycles/create', [PerformanceReviewCycleController::class, 'create']);
$router->post('/performance/review-cycles', [PerformanceReviewCycleController::class, 'store']);
$router->get('/performance/review-cycles/{id}', [PerformanceReviewCycleController::class, 'show']);
$router->get('/performance/review-cycles/{id}/edit', [PerformanceReviewCycleController::class, 'edit']);
$router->post('/performance/review-cycles/{id}', [PerformanceReviewCycleController::class, 'update']);
$router->post('/performance/review-cycles/{id}/delete', [PerformanceReviewCycleController::class, 'delete']);

$router->get('/performance/employee-goals', [PerformanceEmployeeGoalController::class, 'index']);
$router->get('/performance/employee-goals/create', [PerformanceEmployeeGoalController::class, 'create']);
$router->post('/performance/employee-goals', [PerformanceEmployeeGoalController::class, 'store']);
$router->get('/performance/employee-goals/{id}/edit', [PerformanceEmployeeGoalController::class, 'edit']);
$router->post('/performance/employee-goals/{id}', [PerformanceEmployeeGoalController::class, 'update']);
$router->post('/performance/employee-goals/{id}/delete', [PerformanceEmployeeGoalController::class, 'delete']);

$router->get('/performance/employee-reviews', [PerformanceEmployeeReviewController::class, 'index']);
$router->get('/performance/employee-reviews/create', [PerformanceEmployeeReviewController::class, 'create']);
$router->post('/performance/employee-reviews', [PerformanceEmployeeReviewController::class, 'store']);
$router->get('/performance/employee-reviews/{id}', [PerformanceEmployeeReviewController::class, 'show']);
$router->get('/performance/employee-reviews/{id}/edit', [PerformanceEmployeeReviewController::class, 'edit']);
$router->post('/performance/employee-reviews/{id}', [PerformanceEmployeeReviewController::class, 'update']);
$router->get('/performance/employee-reviews/{id}/conduct', [PerformanceEmployeeReviewController::class, 'conduct']);
$router->post('/performance/employee-reviews/{id}/conduct', [PerformanceEmployeeReviewController::class, 'conductStore']);
$router->post('/performance/employee-reviews/{id}/delete', [PerformanceEmployeeReviewController::class, 'delete']);

// HRM: Attendance
$router->get('/hrm/attendance', [HrmAttendanceController::class, 'index']);
$router->get('/hrm/attendance/create', [HrmAttendanceController::class, 'create']);
$router->post('/hrm/attendance', [HrmAttendanceController::class, 'store']);
$router->get('/hrm/attendance/{id}/edit', [HrmAttendanceController::class, 'edit']);
$router->post('/hrm/attendance/{id}', [HrmAttendanceController::class, 'update']);

// HRM: Leave Types
$router->get('/hrm/leave-types', [HrmLeaveTypeController::class, 'index']);
$router->get('/hrm/leave-types/create', [HrmLeaveTypeController::class, 'create']);
$router->post('/hrm/leave-types', [HrmLeaveTypeController::class, 'store']);
$router->get('/hrm/leave-types/{id}/edit', [HrmLeaveTypeController::class, 'edit']);
$router->post('/hrm/leave-types/{id}', [HrmLeaveTypeController::class, 'update']);
$router->post('/hrm/leave-types/{id}/toggle-active', [HrmLeaveTypeController::class, 'toggleActive']);

// HRM: Leave Applications
$router->get('/hrm/leave-applications', [HrmLeaveApplicationController::class, 'index']);
$router->get('/hrm/leave-applications/create', [HrmLeaveApplicationController::class, 'create']);
$router->post('/hrm/leave-applications', [HrmLeaveApplicationController::class, 'store']);
$router->get('/hrm/leave-applications/{id}', [HrmLeaveApplicationController::class, 'show']);
$router->post('/hrm/leave-applications/{id}/approve', [HrmLeaveApplicationController::class, 'approve']);
$router->post('/hrm/leave-applications/{id}/reject', [HrmLeaveApplicationController::class, 'reject']);

// HRM: Leave Balance
$router->get('/hrm/leave-balance', [HrmLeaveBalanceController::class, 'index']);

// HRM: Holidays
$router->get('/hrm/holidays', [HrmHolidayController::class, 'index']);
$router->get('/hrm/holidays/create', [HrmHolidayController::class, 'create']);
$router->post('/hrm/holidays', [HrmHolidayController::class, 'store']);
$router->get('/hrm/holidays/{id}/edit', [HrmHolidayController::class, 'edit']);
$router->post('/hrm/holidays/{id}', [HrmHolidayController::class, 'update']);
$router->post('/hrm/holidays/{id}/delete', [HrmHolidayController::class, 'delete']);

// HRM: Allowance Types
$router->get('/hrm/allowance-types', [HrmAllowanceTypeController::class, 'index']);
$router->get('/hrm/allowance-types/create', [HrmAllowanceTypeController::class, 'create']);
$router->post('/hrm/allowance-types', [HrmAllowanceTypeController::class, 'store']);
$router->get('/hrm/allowance-types/{id}/edit', [HrmAllowanceTypeController::class, 'edit']);
$router->post('/hrm/allowance-types/{id}', [HrmAllowanceTypeController::class, 'update']);
$router->post('/hrm/allowance-types/{id}/delete', [HrmAllowanceTypeController::class, 'delete']);

// HRM: Deduction Types
$router->get('/hrm/deduction-types', [HrmDeductionTypeController::class, 'index']);
$router->get('/hrm/deduction-types/create', [HrmDeductionTypeController::class, 'create']);
$router->post('/hrm/deduction-types', [HrmDeductionTypeController::class, 'store']);
$router->get('/hrm/deduction-types/{id}/edit', [HrmDeductionTypeController::class, 'edit']);
$router->post('/hrm/deduction-types/{id}', [HrmDeductionTypeController::class, 'update']);
$router->post('/hrm/deduction-types/{id}/delete', [HrmDeductionTypeController::class, 'delete']);

// HRM: Allowances (employee assignments)
$router->get('/hrm/allowances', [HrmAllowanceController::class, 'index']);
$router->get('/hrm/allowances/create', [HrmAllowanceController::class, 'create']);
$router->post('/hrm/allowances', [HrmAllowanceController::class, 'store']);
$router->get('/hrm/allowances/{id}/edit', [HrmAllowanceController::class, 'edit']);
$router->post('/hrm/allowances/{id}', [HrmAllowanceController::class, 'update']);
$router->post('/hrm/allowances/{id}/delete', [HrmAllowanceController::class, 'delete']);

// HRM: Deductions (employee assignments)
$router->get('/hrm/deductions', [HrmDeductionController::class, 'index']);
$router->get('/hrm/deductions/create', [HrmDeductionController::class, 'create']);
$router->post('/hrm/deductions', [HrmDeductionController::class, 'store']);
$router->get('/hrm/deductions/{id}/edit', [HrmDeductionController::class, 'edit']);
$router->post('/hrm/deductions/{id}', [HrmDeductionController::class, 'update']);
$router->post('/hrm/deductions/{id}/delete', [HrmDeductionController::class, 'delete']);

// HRM: Payroll
$router->get('/hrm/payrolls', [HrmPayrollController::class, 'index']);
$router->get('/hrm/payrolls/create', [HrmPayrollController::class, 'create']);
$router->post('/hrm/payrolls', [HrmPayrollController::class, 'store']);
$router->get('/hrm/payrolls/{id}', [HrmPayrollController::class, 'show']);
$router->post('/hrm/payrolls/{id}/run', [HrmPayrollController::class, 'run']);
$router->post('/hrm/payrolls/{payrollId}/entries/{entryId}/mark-paid', [HrmPayrollController::class, 'markEntryPaid']);
$router->get('/hrm/payrolls/{payrollId}/entries/{entryId}/payslip', [HrmPayrollController::class, 'payslip']);

// HRM: Performance & Discipline -- lookup types
$router->get('/hrm/award-types', [HrmAwardTypeController::class, 'index']);
$router->get('/hrm/award-types/create', [HrmAwardTypeController::class, 'create']);
$router->post('/hrm/award-types', [HrmAwardTypeController::class, 'store']);
$router->get('/hrm/award-types/{id}/edit', [HrmAwardTypeController::class, 'edit']);
$router->post('/hrm/award-types/{id}', [HrmAwardTypeController::class, 'update']);
$router->post('/hrm/award-types/{id}/delete', [HrmAwardTypeController::class, 'delete']);

$router->get('/hrm/complaint-types', [HrmComplaintTypeController::class, 'index']);
$router->get('/hrm/complaint-types/create', [HrmComplaintTypeController::class, 'create']);
$router->post('/hrm/complaint-types', [HrmComplaintTypeController::class, 'store']);
$router->get('/hrm/complaint-types/{id}/edit', [HrmComplaintTypeController::class, 'edit']);
$router->post('/hrm/complaint-types/{id}', [HrmComplaintTypeController::class, 'update']);
$router->post('/hrm/complaint-types/{id}/delete', [HrmComplaintTypeController::class, 'delete']);

$router->get('/hrm/warning-types', [HrmWarningTypeController::class, 'index']);
$router->get('/hrm/warning-types/create', [HrmWarningTypeController::class, 'create']);
$router->post('/hrm/warning-types', [HrmWarningTypeController::class, 'store']);
$router->get('/hrm/warning-types/{id}/edit', [HrmWarningTypeController::class, 'edit']);
$router->post('/hrm/warning-types/{id}', [HrmWarningTypeController::class, 'update']);
$router->post('/hrm/warning-types/{id}/delete', [HrmWarningTypeController::class, 'delete']);

$router->get('/hrm/termination-types', [HrmTerminationTypeController::class, 'index']);
$router->get('/hrm/termination-types/create', [HrmTerminationTypeController::class, 'create']);
$router->post('/hrm/termination-types', [HrmTerminationTypeController::class, 'store']);
$router->get('/hrm/termination-types/{id}/edit', [HrmTerminationTypeController::class, 'edit']);
$router->post('/hrm/termination-types/{id}', [HrmTerminationTypeController::class, 'update']);
$router->post('/hrm/termination-types/{id}/delete', [HrmTerminationTypeController::class, 'delete']);

// HRM: Performance & Discipline -- Awards
$router->get('/hrm/awards', [HrmAwardController::class, 'index']);
$router->get('/hrm/awards/create', [HrmAwardController::class, 'create']);
$router->post('/hrm/awards', [HrmAwardController::class, 'store']);
$router->get('/hrm/awards/{id}', [HrmAwardController::class, 'show']);
$router->get('/hrm/awards/{id}/edit', [HrmAwardController::class, 'edit']);
$router->post('/hrm/awards/{id}', [HrmAwardController::class, 'update']);
$router->post('/hrm/awards/{id}/delete', [HrmAwardController::class, 'delete']);

// HRM: Performance & Discipline -- Complaints
$router->get('/hrm/complaints', [HrmComplaintController::class, 'index']);
$router->get('/hrm/complaints/create', [HrmComplaintController::class, 'create']);
$router->post('/hrm/complaints', [HrmComplaintController::class, 'store']);
$router->get('/hrm/complaints/{id}', [HrmComplaintController::class, 'show']);
$router->post('/hrm/complaints/{id}/status', [HrmComplaintController::class, 'updateStatus']);
$router->post('/hrm/complaints/{id}/delete', [HrmComplaintController::class, 'delete']);

// HRM: Performance & Discipline -- Warnings
$router->get('/hrm/warnings', [HrmWarningController::class, 'index']);
$router->get('/hrm/warnings/create', [HrmWarningController::class, 'create']);
$router->post('/hrm/warnings', [HrmWarningController::class, 'store']);
$router->get('/hrm/warnings/{id}', [HrmWarningController::class, 'show']);
$router->post('/hrm/warnings/{id}/approve', [HrmWarningController::class, 'approve']);
$router->post('/hrm/warnings/{id}/reject', [HrmWarningController::class, 'reject']);
$router->post('/hrm/warnings/{id}/respond', [HrmWarningController::class, 'respond']);
$router->post('/hrm/warnings/{id}/delete', [HrmWarningController::class, 'delete']);

// HRM: Performance & Discipline -- Terminations
$router->get('/hrm/terminations', [HrmTerminationController::class, 'index']);
$router->get('/hrm/terminations/create', [HrmTerminationController::class, 'create']);
$router->post('/hrm/terminations', [HrmTerminationController::class, 'store']);
$router->get('/hrm/terminations/{id}', [HrmTerminationController::class, 'show']);
$router->post('/hrm/terminations/{id}/approve', [HrmTerminationController::class, 'approve']);
$router->post('/hrm/terminations/{id}/reject', [HrmTerminationController::class, 'reject']);
$router->post('/hrm/terminations/{id}/delete', [HrmTerminationController::class, 'delete']);

// HRM: Performance & Discipline -- Promotions
$router->get('/hrm/promotions', [HrmPromotionController::class, 'index']);
$router->get('/hrm/promotions/create', [HrmPromotionController::class, 'create']);
$router->post('/hrm/promotions', [HrmPromotionController::class, 'store']);
$router->get('/hrm/promotions/{id}', [HrmPromotionController::class, 'show']);
$router->post('/hrm/promotions/{id}/approve', [HrmPromotionController::class, 'approve']);
$router->post('/hrm/promotions/{id}/reject', [HrmPromotionController::class, 'reject']);
$router->post('/hrm/promotions/{id}/delete', [HrmPromotionController::class, 'delete']);

// HRM: Performance & Discipline -- Transfers
$router->get('/hrm/transfers', [HrmTransferController::class, 'index']);
$router->get('/hrm/transfers/create', [HrmTransferController::class, 'create']);
$router->post('/hrm/transfers', [HrmTransferController::class, 'store']);
$router->get('/hrm/transfers/{id}', [HrmTransferController::class, 'show']);
$router->post('/hrm/transfers/{id}/approve', [HrmTransferController::class, 'approve']);
$router->post('/hrm/transfers/{id}/reject', [HrmTransferController::class, 'reject']);
$router->post('/hrm/transfers/{id}/delete', [HrmTransferController::class, 'delete']);

// HRM: Communications -- lookup types
$router->get('/hrm/announcement-categories', [HrmAnnouncementCategoryController::class, 'index']);
$router->get('/hrm/announcement-categories/create', [HrmAnnouncementCategoryController::class, 'create']);
$router->post('/hrm/announcement-categories', [HrmAnnouncementCategoryController::class, 'store']);
$router->get('/hrm/announcement-categories/{id}/edit', [HrmAnnouncementCategoryController::class, 'edit']);
$router->post('/hrm/announcement-categories/{id}', [HrmAnnouncementCategoryController::class, 'update']);
$router->post('/hrm/announcement-categories/{id}/delete', [HrmAnnouncementCategoryController::class, 'delete']);

$router->get('/hrm/event-types', [HrmEventTypeController::class, 'index']);
$router->get('/hrm/event-types/create', [HrmEventTypeController::class, 'create']);
$router->post('/hrm/event-types', [HrmEventTypeController::class, 'store']);
$router->get('/hrm/event-types/{id}/edit', [HrmEventTypeController::class, 'edit']);
$router->post('/hrm/event-types/{id}', [HrmEventTypeController::class, 'update']);
$router->post('/hrm/event-types/{id}/delete', [HrmEventTypeController::class, 'delete']);

// HRM: Communications -- Announcements
$router->get('/hrm/announcements', [HrmAnnouncementController::class, 'index']);
$router->get('/hrm/announcements/create', [HrmAnnouncementController::class, 'create']);
$router->post('/hrm/announcements', [HrmAnnouncementController::class, 'store']);
$router->get('/hrm/announcements/{id}', [HrmAnnouncementController::class, 'show']);
$router->post('/hrm/announcements/{id}/status', [HrmAnnouncementController::class, 'updateStatus']);
$router->post('/hrm/announcements/{id}/delete', [HrmAnnouncementController::class, 'delete']);

// HRM: Communications -- Events
$router->get('/hrm/events', [HrmEventController::class, 'index']);
$router->get('/hrm/events/create', [HrmEventController::class, 'create']);
$router->post('/hrm/events', [HrmEventController::class, 'store']);
$router->get('/hrm/events/{id}', [HrmEventController::class, 'show']);
$router->post('/hrm/events/{id}/approve', [HrmEventController::class, 'approve']);
$router->post('/hrm/events/{id}/reject', [HrmEventController::class, 'reject']);
$router->post('/hrm/events/{id}/delete', [HrmEventController::class, 'delete']);

// HRM: Staff Loans
$router->get('/hrm/staff-loan-types', [StaffLoanTypeController::class, 'index']);
$router->get('/hrm/staff-loan-types/create', [StaffLoanTypeController::class, 'create']);
$router->post('/hrm/staff-loan-types', [StaffLoanTypeController::class, 'store']);
$router->get('/hrm/staff-loan-types/{id}/edit', [StaffLoanTypeController::class, 'edit']);
$router->post('/hrm/staff-loan-types/{id}', [StaffLoanTypeController::class, 'update']);
$router->post('/hrm/staff-loan-types/{id}/delete', [StaffLoanTypeController::class, 'delete']);

$router->get('/hrm/staff-loans', [StaffLoanController::class, 'index']);
$router->get('/hrm/staff-loans/create', [StaffLoanController::class, 'create']);
$router->post('/hrm/staff-loans', [StaffLoanController::class, 'store']);
$router->get('/hrm/staff-loans/{id}', [StaffLoanController::class, 'show']);
$router->post('/hrm/staff-loans/{id}/approve', [StaffLoanController::class, 'approve']);
$router->post('/hrm/staff-loans/{id}/reject', [StaffLoanController::class, 'reject']);
$router->post('/hrm/staff-loans/{id}/cancel', [StaffLoanController::class, 'cancel']);
$router->post('/hrm/staff-loans/{id}/delete', [StaffLoanController::class, 'delete']);
$router->post('/hrm/staff-loans/{id}/documents', [StaffLoanController::class, 'uploadDocument']);
$router->get('/hrm/staff-loans/{id}/documents/{documentId}/download', [StaffLoanController::class, 'downloadDocument']);
$router->post('/hrm/staff-loans/{id}/documents/{documentId}/delete', [StaffLoanController::class, 'deleteDocument']);

// HRM: Zoom Meetings
$router->get('/hrm/zoom-meetings', [HrmZoomMeetingController::class, 'index']);
$router->get('/hrm/zoom-meetings/create', [HrmZoomMeetingController::class, 'create']);
$router->post('/hrm/zoom-meetings', [HrmZoomMeetingController::class, 'store']);
$router->get('/hrm/zoom-meetings/{id}/edit', [HrmZoomMeetingController::class, 'edit']);
$router->post('/hrm/zoom-meetings/{id}', [HrmZoomMeetingController::class, 'update']);
$router->post('/hrm/zoom-meetings/{id}/status', [HrmZoomMeetingController::class, 'updateStatus']);
$router->post('/hrm/zoom-meetings/{id}/delete', [HrmZoomMeetingController::class, 'delete']);

$router->get('/hrm/zoom-settings', [HrmZoomSettingController::class, 'edit']);
$router->post('/hrm/zoom-settings', [HrmZoomSettingController::class, 'update']);

// Training module -- standalone, sits outside Human Resources
$router->get('/training/types', [TrainingTypeController::class, 'index']);
$router->get('/training/types/create', [TrainingTypeController::class, 'create']);
$router->post('/training/types', [TrainingTypeController::class, 'store']);
$router->get('/training/types/{id}/edit', [TrainingTypeController::class, 'edit']);
$router->post('/training/types/{id}', [TrainingTypeController::class, 'update']);
$router->post('/training/types/{id}/delete', [TrainingTypeController::class, 'delete']);

$router->get('/training/trainers', [TrainerController::class, 'index']);
$router->get('/training/trainers/create', [TrainerController::class, 'create']);
$router->post('/training/trainers', [TrainerController::class, 'store']);
$router->get('/training/trainers/{id}/edit', [TrainerController::class, 'edit']);
$router->post('/training/trainers/{id}', [TrainerController::class, 'update']);
$router->post('/training/trainers/{id}/delete', [TrainerController::class, 'delete']);

$router->get('/training/trainings', [TrainingController::class, 'index']);
$router->get('/training/trainings/create', [TrainingController::class, 'create']);
$router->post('/training/trainings', [TrainingController::class, 'store']);
$router->get('/training/trainings/{id}', [TrainingController::class, 'show']);
$router->get('/training/trainings/{id}/edit', [TrainingController::class, 'edit']);
$router->post('/training/trainings/{id}', [TrainingController::class, 'update']);
$router->post('/training/trainings/{id}/delete', [TrainingController::class, 'delete']);
$router->post('/training/trainings/{id}/enroll', [TrainingController::class, 'enroll']);
$router->post('/training/trainings/{id}/enrollments/{enrollmentId}/complete', [TrainingController::class, 'completeEnrollment']);
$router->post('/training/trainings/{id}/enrollments/{enrollmentId}/delete', [TrainingController::class, 'deleteEnrollment']);
$router->post('/training/trainings/{id}/tasks', [TrainingController::class, 'addTask']);
$router->post('/training/trainings/{id}/tasks/{taskId}/complete', [TrainingController::class, 'completeTask']);
$router->post('/training/trainings/{id}/tasks/{taskId}/delete', [TrainingController::class, 'deleteTask']);
$router->post('/training/trainings/{id}/tasks/{taskId}/feedback', [TrainingController::class, 'addFeedback']);

// Recruitment module -- standalone, sits outside Human Resources
$router->get('/recruitment/job-types', [RecruitmentJobTypeController::class, 'index']);
$router->get('/recruitment/job-types/create', [RecruitmentJobTypeController::class, 'create']);
$router->post('/recruitment/job-types', [RecruitmentJobTypeController::class, 'store']);
$router->get('/recruitment/job-types/{id}/edit', [RecruitmentJobTypeController::class, 'edit']);
$router->post('/recruitment/job-types/{id}', [RecruitmentJobTypeController::class, 'update']);
$router->post('/recruitment/job-types/{id}/delete', [RecruitmentJobTypeController::class, 'delete']);

$router->get('/recruitment/candidate-sources', [RecruitmentCandidateSourceController::class, 'index']);
$router->get('/recruitment/candidate-sources/create', [RecruitmentCandidateSourceController::class, 'create']);
$router->post('/recruitment/candidate-sources', [RecruitmentCandidateSourceController::class, 'store']);
$router->get('/recruitment/candidate-sources/{id}/edit', [RecruitmentCandidateSourceController::class, 'edit']);
$router->post('/recruitment/candidate-sources/{id}', [RecruitmentCandidateSourceController::class, 'update']);
$router->post('/recruitment/candidate-sources/{id}/delete', [RecruitmentCandidateSourceController::class, 'delete']);

$router->get('/recruitment/interview-types', [RecruitmentInterviewTypeController::class, 'index']);
$router->get('/recruitment/interview-types/create', [RecruitmentInterviewTypeController::class, 'create']);
$router->post('/recruitment/interview-types', [RecruitmentInterviewTypeController::class, 'store']);
$router->get('/recruitment/interview-types/{id}/edit', [RecruitmentInterviewTypeController::class, 'edit']);
$router->post('/recruitment/interview-types/{id}', [RecruitmentInterviewTypeController::class, 'update']);
$router->post('/recruitment/interview-types/{id}/delete', [RecruitmentInterviewTypeController::class, 'delete']);

$router->get('/recruitment/job-locations', [RecruitmentJobLocationController::class, 'index']);
$router->get('/recruitment/job-locations/create', [RecruitmentJobLocationController::class, 'create']);
$router->post('/recruitment/job-locations', [RecruitmentJobLocationController::class, 'store']);
$router->get('/recruitment/job-locations/{id}/edit', [RecruitmentJobLocationController::class, 'edit']);
$router->post('/recruitment/job-locations/{id}', [RecruitmentJobLocationController::class, 'update']);
$router->post('/recruitment/job-locations/{id}/delete', [RecruitmentJobLocationController::class, 'delete']);

$router->get('/recruitment/custom-questions', [RecruitmentCustomQuestionController::class, 'index']);
$router->get('/recruitment/custom-questions/create', [RecruitmentCustomQuestionController::class, 'create']);
$router->post('/recruitment/custom-questions', [RecruitmentCustomQuestionController::class, 'store']);
$router->get('/recruitment/custom-questions/{id}/edit', [RecruitmentCustomQuestionController::class, 'edit']);
$router->post('/recruitment/custom-questions/{id}', [RecruitmentCustomQuestionController::class, 'update']);
$router->post('/recruitment/custom-questions/{id}/delete', [RecruitmentCustomQuestionController::class, 'delete']);

$router->get('/recruitment/job-postings', [RecruitmentJobPostingController::class, 'index']);
$router->get('/recruitment/job-postings/create', [RecruitmentJobPostingController::class, 'create']);
$router->post('/recruitment/job-postings', [RecruitmentJobPostingController::class, 'store']);
$router->get('/recruitment/job-postings/{id}', [RecruitmentJobPostingController::class, 'show']);
$router->get('/recruitment/job-postings/{id}/edit', [RecruitmentJobPostingController::class, 'edit']);
$router->post('/recruitment/job-postings/{id}', [RecruitmentJobPostingController::class, 'update']);
$router->post('/recruitment/job-postings/{id}/toggle-publish', [RecruitmentJobPostingController::class, 'togglePublish']);
$router->post('/recruitment/job-postings/{id}/delete', [RecruitmentJobPostingController::class, 'delete']);
$router->post('/recruitment/job-postings/{id}/rounds', [RecruitmentJobPostingController::class, 'addRound']);
$router->post('/recruitment/job-postings/{id}/rounds/{roundId}/delete', [RecruitmentJobPostingController::class, 'deleteRound']);

$router->get('/recruitment/candidates', [RecruitmentCandidateController::class, 'index']);
$router->get('/recruitment/candidates/create', [RecruitmentCandidateController::class, 'create']);
$router->post('/recruitment/candidates', [RecruitmentCandidateController::class, 'store']);
$router->get('/recruitment/candidates/{id}', [RecruitmentCandidateController::class, 'show']);
$router->post('/recruitment/candidates/{id}/status', [RecruitmentCandidateController::class, 'updateStatus']);
$router->post('/recruitment/candidates/{id}/delete', [RecruitmentCandidateController::class, 'delete']);
$router->post('/recruitment/candidates/{id}/assessments', [RecruitmentCandidateController::class, 'addAssessment']);
$router->post('/recruitment/candidates/{id}/assessments/{assessmentId}/delete', [RecruitmentCandidateController::class, 'deleteAssessment']);
$router->get('/recruitment/candidates/{id}/files/{field}', [RecruitmentCandidateController::class, 'downloadFile']);
$router->get('/recruitment/candidates/{id}/rounds.json', [RecruitmentInterviewController::class, 'getRoundsForCandidate']);

$router->get('/recruitment/interviews', [RecruitmentInterviewController::class, 'index']);
$router->get('/recruitment/interviews/create', [RecruitmentInterviewController::class, 'create']);
$router->post('/recruitment/interviews', [RecruitmentInterviewController::class, 'store']);
$router->get('/recruitment/interviews/{id}', [RecruitmentInterviewController::class, 'show']);
$router->post('/recruitment/interviews/{id}/status', [RecruitmentInterviewController::class, 'updateStatus']);
$router->post('/recruitment/interviews/{id}/delete', [RecruitmentInterviewController::class, 'delete']);
$router->post('/recruitment/interviews/{id}/feedback', [RecruitmentInterviewController::class, 'addFeedback']);
$router->post('/recruitment/interviews/{id}/feedback/{feedbackId}/delete', [RecruitmentInterviewController::class, 'deleteFeedback']);

$router->get('/recruitment/offers', [RecruitmentOfferController::class, 'index']);
$router->get('/recruitment/offers/create', [RecruitmentOfferController::class, 'create']);
$router->post('/recruitment/offers', [RecruitmentOfferController::class, 'store']);
$router->get('/recruitment/offers/{id}', [RecruitmentOfferController::class, 'show']);
$router->post('/recruitment/offers/{id}/status', [RecruitmentOfferController::class, 'updateStatus']);
$router->post('/recruitment/offers/{id}/approval', [RecruitmentOfferController::class, 'updateApprovalStatus']);
$router->post('/recruitment/offers/{id}/delete', [RecruitmentOfferController::class, 'delete']);
$router->get('/recruitment/offers/{id}/convert', [RecruitmentOfferController::class, 'convertToEmployee']);
$router->post('/recruitment/offers/{id}/convert', [RecruitmentOfferController::class, 'convertToEmployeeStore']);

$router->get('/recruitment/offer-letter-templates', [RecruitmentOfferLetterTemplateController::class, 'index']);
$router->get('/recruitment/offer-letter-templates/create', [RecruitmentOfferLetterTemplateController::class, 'create']);
$router->post('/recruitment/offer-letter-templates', [RecruitmentOfferLetterTemplateController::class, 'store']);
$router->get('/recruitment/offer-letter-templates/{id}/edit', [RecruitmentOfferLetterTemplateController::class, 'edit']);
$router->post('/recruitment/offer-letter-templates/{id}', [RecruitmentOfferLetterTemplateController::class, 'update']);
$router->post('/recruitment/offer-letter-templates/{id}/delete', [RecruitmentOfferLetterTemplateController::class, 'delete']);

$router->get('/recruitment/onboarding-checklists', [RecruitmentOnboardingChecklistController::class, 'index']);
$router->get('/recruitment/onboarding-checklists/create', [RecruitmentOnboardingChecklistController::class, 'create']);
$router->post('/recruitment/onboarding-checklists', [RecruitmentOnboardingChecklistController::class, 'store']);
$router->get('/recruitment/onboarding-checklists/{id}', [RecruitmentOnboardingChecklistController::class, 'show']);
$router->get('/recruitment/onboarding-checklists/{id}/edit', [RecruitmentOnboardingChecklistController::class, 'edit']);
$router->post('/recruitment/onboarding-checklists/{id}', [RecruitmentOnboardingChecklistController::class, 'update']);
$router->post('/recruitment/onboarding-checklists/{id}/delete', [RecruitmentOnboardingChecklistController::class, 'delete']);
$router->post('/recruitment/onboarding-checklists/{id}/items', [RecruitmentOnboardingChecklistController::class, 'addItem']);
$router->post('/recruitment/onboarding-checklists/{id}/items/{itemId}/delete', [RecruitmentOnboardingChecklistController::class, 'deleteItem']);

$router->get('/recruitment/candidate-onboardings', [RecruitmentCandidateOnboardingController::class, 'index']);
$router->get('/recruitment/candidate-onboardings/create', [RecruitmentCandidateOnboardingController::class, 'create']);
$router->post('/recruitment/candidate-onboardings', [RecruitmentCandidateOnboardingController::class, 'store']);
$router->post('/recruitment/candidate-onboardings/{id}/status', [RecruitmentCandidateOnboardingController::class, 'updateStatus']);
$router->post('/recruitment/candidate-onboardings/{id}/delete', [RecruitmentCandidateOnboardingController::class, 'delete']);

// Recruitment: public careers portal (unauthenticated)
$router->get('/careers', [RecruitmentFrontendController::class, 'index']);
$router->get('/careers/track', [RecruitmentFrontendController::class, 'trackResult']);
$router->get('/careers/offer/{trackingId}/{offerId}', [RecruitmentFrontendController::class, 'offerShow']);
$router->post('/careers/offer/{trackingId}/{offerId}', [RecruitmentFrontendController::class, 'offerRespond']);
$router->get('/careers/{code}', [RecruitmentFrontendController::class, 'show']);
$router->post('/careers/{code}/apply', [RecruitmentFrontendController::class, 'apply']);

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