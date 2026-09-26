<?php

/**
 * Builds (or rebuilds) the sales-demo dataset: wipes every non-configuration
 * table and reseeds a DesertLedger-branded company, staff login, borrowers,
 * loans in every lifecycle state, payments, arrears and accounting -- all
 * fictional. Loan/journal/payment records are created through the app's own
 * models and services so every screen and report stays consistent.
 *
 * SAFETY: refuses to run unless demo mode is on AND the connected database's
 * name contains "demo", so it can never touch a live database.
 *
 *   MLS_DEMO_MODE=1 DEMO_PASSWORD='...' OWNER_PASSWORD='...' php bin/demo_seed.php
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\StatutoryCharge;
use App\Services\LoanScheduleService;
use App\Support\DemoMode;

if (!DemoMode::enabled()) {
    fwrite(STDERR, "Refusing to run: demo mode is not enabled.\n");
    exit(1);
}

$db = Database::connection();
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (stripos($dbName, 'demo') === false) {
    fwrite(STDERR, "Refusing to run: database \"{$dbName}\" does not look like a demo database.\n");
    exit(1);
}

$demoPassword = getenv('DEMO_PASSWORD') ?: '';
$ownerPassword = getenv('OWNER_PASSWORD') ?: '';
if (strlen($demoPassword) < 8 || strlen($ownerPassword) < 12) {
    fwrite(STDERR, "Set DEMO_PASSWORD (8+ chars, shared with prospects) and OWNER_PASSWORD (12+ chars, private).\n");
    exit(1);
}

mt_srand(2026);
$today = new DateTimeImmutable('today');

// ---------------------------------------------------------------------------
// 1. Wipe every table that is not configuration/reference data
// ---------------------------------------------------------------------------
$keep = array_flip([
    'accounting_accounts', 'accounting_event_rules', 'accounting_fiscal_years', 'accounting_periods',
    'accounting_tax_rates', 'accounting_settings', 'application_upload_requirements', 'approval_policies',
    'asset_categories', 'dashboard_widgets', 'data_quality_rules', 'document_template_categories',
    'document_template_fields', 'document_templates', 'duty_stamp_settings', 'expense_categories',
    'feature_flags', 'hrm_leave_types', 'hrm_staff_loan_types', 'loan_breakdown_size_bands', 'loan_plans',
    'loan_products', 'namfisa_levy_settings', 'notification_templates', 'pay_cycle_settings',
    'payment_methods', 'permissions', 'regulatory_report_types', 'report_definitions', 'retention_policies',
    'role_permissions', 'roles', 'salary_breakdown_bands', 'schema_migrations', 'security_rules',
    'social_analytics_settings', 'system_settings', 'segregation_of_duty_rules', 'intake_field_mappings',
    // Integration credentials the owner enters once through the owner login;
    // they must survive every reseed and nightly reset.
    'collexia_settings', 'creditinfo_settings', 'creditinfo_public_default_settings',
    'notification_settings', 'hrm_zoom_settings', 'intake_sources',
]);

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
    if (!isset($keep[$table])) {
        $db->exec("TRUNCATE TABLE `{$table}`");
    }
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Wiped operational tables.\n";

// ---------------------------------------------------------------------------
// 2. Rebrand any hard-coded live-company wording in the kept reference data
// ---------------------------------------------------------------------------
$replacements = [
    'Solid Desert Cash Loan Express cc' => 'DesertLedger Finance',
    'Solid Desert Cash Loan Express CC' => 'DesertLedger Finance',
    'Solid Desert' => 'DesertLedger',
    'solid-desert.com' => 'desertledger.example',
];
foreach (array_keys($keep) as $table) {
    $cols = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE IN ('varchar','text','mediumtext','longtext')");
    $cols->execute([$table]);
    foreach ($cols->fetchAll(PDO::FETCH_COLUMN) as $col) {
        foreach ($replacements as $from => $to) {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE `{$col}` LIKE ?");
            $stmt->execute([$from, $to, '%' . $from . '%']);
        }
    }
}
$db->exec("UPDATE system_settings SET setting_value = 'friendly' WHERE setting_key = 'error_display_mode'");
echo "Rebranded reference data.\n";

// ---------------------------------------------------------------------------
// 3. Company, branches, staff login, bank account, opening capital
// ---------------------------------------------------------------------------
$db->prepare("INSERT INTO companies (id, company_name, brand_name, registration_no, namfisa_license_no, tax_no, email, phone, address, footer_tagline, is_active, working_days)
              VALUES (1, 'DesertLedger Finance (Pty) Ltd', 'DesertLedger', 'CC/2021/00000', 'NAMFISA/ML/DEMO', 'TIN 0000000-00-0', 'demo@desertledger.example', '+264 61 000 0000', '1 Independence Avenue, Windhoek, Namibia', 'Microlending, simplified.', 1, 'Mon-Fri')")
   ->execute();

$db->exec("INSERT INTO branches (id, company_id, branch_name, branch_code, phone, email, address, is_active) VALUES
    (1, 1, 'Head Office - Windhoek', 'HO', '+264 61 000 0001', 'windhoek@desertledger.example', '1 Independence Avenue, Windhoek', 1),
    (2, 1, 'Ongwediva Branch', 'ONG', '+264 65 000 0002', 'ongwediva@desertledger.example', 'Main Road, Ongwediva', 1)");

$db->prepare("INSERT INTO users (id, branch_id, name, username, email, password, phone, user_type, is_active)
              VALUES (1, 1, 'Demo Administrator', 'demo', 'demo@desertledger.example', ?, NULL, 'Super Admin', 1)")
   ->execute([password_hash($demoPassword, PASSWORD_BCRYPT)]);
$db->prepare("INSERT INTO users (id, branch_id, name, username, email, password, phone, user_type, is_active)
              VALUES (2, 1, 'Demo Owner', 'owner', 'owner@desertledger.example', ?, NULL, 'Super Admin', 1)")
   ->execute([password_hash($ownerPassword, PASSWORD_BCRYPT)]);
$db->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1), (2, 1)");
$userId = 1;

$accounts = new AccountingAccount();
$journal = new AccountingJournal();
$bankAccounts = new BankAccount();
$statutory = new StatutoryCharge();
$loans = new Loan();
$payments = new Payment();

$bankGl = $accounts->idByCode('1010');
$bankAccountId = $bankAccounts->create([
    'account_name' => 'DesertLedger Operating Account',
    'bank_name' => 'FNB Namibia',
    'account_number' => '62000000001',
    'branch' => 'Windhoek Main',
    'branch_code' => '282672',
    'swift_code' => 'FIRNNANX',
    'account_id' => $bankGl,
    'opening_balance' => 0,
    'is_active' => 1,
]);
$bankAccount = $bankAccounts->find($bankAccountId);

$fyStart = (new DateTimeImmutable($db->query('SELECT start_date FROM accounting_fiscal_years ORDER BY id LIMIT 1')->fetchColumn()))->format('Y-m-d');
$journal->post('MANUAL', 'accounting_journal_entries', null, 'OPEN-CAPITAL', 'Owner capital introduced to fund the loan book', [
    ['account_id' => $bankGl, 'debit' => 1500000, 'credit' => 0, 'description' => 'Capital received into operating account'],
    ['account_id' => $accounts->idByCode('3010'), 'debit' => 0, 'credit' => 1500000, 'description' => 'Owner capital'],
], $userId, $fyStart, 'Manual');
echo "Company, branches, login and opening capital created.\n";

// ---------------------------------------------------------------------------
// 4. Namibian public holidays (drives the pay-cycle / collection-date tooling)
// ---------------------------------------------------------------------------
$holidays = [
    ['2026-01-01', "New Year's Day"], ['2026-03-21', 'Independence Day'], ['2026-04-03', 'Good Friday'],
    ['2026-04-06', 'Easter Monday'], ['2026-05-01', "Workers' Day"], ['2026-05-04', 'Cassinga Day'],
    ['2026-05-14', 'Ascension Day'], ['2026-05-25', 'Africa Day'], ['2026-08-26', "Heroes' Day"],
    ['2026-12-10', 'Human Rights Day'], ['2026-12-25', 'Christmas Day'], ['2026-12-26', 'Family Day'],
    ['2027-01-01', "New Year's Day"], ['2027-03-22', 'Independence Day (observed)'], ['2027-03-26', 'Good Friday'],
    ['2027-03-29', 'Easter Monday'], ['2027-05-04', 'Cassinga Day'], ['2027-05-06', 'Ascension Day'],
    ['2027-05-25', 'Africa Day'], ['2027-08-26', "Heroes' Day"], ['2027-12-10', 'Human Rights Day'],
];
$holidayStmt = $db->prepare("INSERT INTO public_holidays (holiday_date, holiday_name, year, is_active, source, created_by) VALUES (?, ?, ?, 1, 'Manual', ?)");
foreach ($holidays as [$date, $name]) {
    $holidayStmt->execute([$date, $name, (int) substr($date, 0, 4), $userId]);
}

// ---------------------------------------------------------------------------
// 5. Borrowers
// ---------------------------------------------------------------------------
$firstNames = [
    ['Male', 'Johannes'], ['Female', 'Maria'], ['Male', 'Petrus'], ['Female', 'Selma'], ['Male', 'Tuyeni'],
    ['Female', 'Ndapewa'], ['Male', 'Elias'], ['Female', 'Hilma'], ['Male', 'Festus'], ['Female', 'Rauna'],
    ['Male', 'Simeon'], ['Female', 'Loide'], ['Male', 'Fillemon'], ['Female', 'Anna'], ['Male', 'Hendrik'],
    ['Female', 'Magdalena'], ['Male', 'Immanuel'], ['Female', 'Saara'], ['Male', 'Ludwig'], ['Female', 'Julia'],
    ['Male', 'Mathews'], ['Female', 'Eveline'], ['Male', 'Gideon'], ['Female', 'Bertha'], ['Male', 'Kandjii'],
    ['Female', 'Vetu'], ['Male', 'Abner'], ['Female', 'Christine'], ['Male', 'Naftal'], ['Female', 'Leena'],
    ['Male', 'Toivo'], ['Female', 'Rosalia'],
];
$lastNames = [
    'Shikongo', 'Amukoto', 'Nangolo', 'Haufiku', 'Iipinge', 'Kandjimi', 'Nghidinwa', 'Shilongo', 'Hamutenya',
    'Namupala', 'Tjivikua', 'Ekandjo', 'Nekongo', 'Uushona', 'Kaulinge', 'Mbumba', 'Katjiuanjo', 'Hangula',
    'Ndeitunga', 'Shivute', 'Amakali', 'Hishekwa', 'Nakale', 'Kapofi', 'Auala', 'Nujoma', 'Tjiueza', 'Garoeb',
    'Beukes', 'Witbooi', 'Goagoseb', 'Kavari',
];
$employers = [
    ['Ministry of Education, Arts and Culture', 'Teacher', 18500, 25],
    ['Ministry of Health and Social Services', 'Registered Nurse', 21000, 25],
    ['Namibian Police Force', 'Police Officer', 19500, 25],
    ['City of Windhoek', 'Municipal Clerk', 16500, 25],
    ['NamPower', 'Technician', 24000, 25],
    ['Telecom Namibia', 'Customer Care Agent', 14500, 28],
    ['Namibia Breweries', 'Production Operator', 15500, 27],
    ['Shoprite Checkers Namibia', 'Store Supervisor', 12500, 30],
    ['Ministry of Works and Transport', 'Administrative Officer', 20000, 25],
    ['Namport', 'Port Operations Clerk', 22500, 26],
];
$banks = [['FNB Namibia', '282672'], ['Bank Windhoek', '483872'], ['Standard Bank Namibia', '087373'], ['Nedbank Namibia', '461609']];
$towns = ['Windhoek', 'Katutura', 'Khomasdal', 'Ongwediva', 'Oshakati', 'Rundu', 'Swakopmund', 'Walvis Bay', 'Ondangwa', 'Katima Mulilo'];

$borrowerIds = [];
$borrowerCount = 32;
for ($i = 0; $i < $borrowerCount; $i++) {
    [$gender, $first] = $firstNames[$i];
    $last = $lastNames[$i];
    $employer = $employers[$i % count($employers)];
    $bank = $banks[$i % count($banks)];
    $dob = (new DateTimeImmutable('today'))->modify('-' . mt_rand(26, 58) . ' years')->modify('-' . mt_rand(0, 300) . ' days');
    $idNumber = $dob->format('ymd') . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
    $gross = $employer[2] + mt_rand(-15, 30) * 100;
    $branchId = $i % 4 === 3 ? 2 : 1;
    $town = $towns[$i % count($towns)];
    $phone = '081 555 ' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);

    $borrowerIds[$i] = (new App\Models\Borrower())->createFull(
        [
            'branch_id' => $branchId,
            'borrower_no' => generate_reference('BRW'),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'date_of_birth' => $dob->format('Y-m-d'),
            'id_number' => $idNumber,
            'marital_status' => ['Single', 'Married', 'Single', 'Divorced'][$i % 4],
            'nationality' => 'Namibian',
            'phone' => $phone,
            'email' => strtolower($first . '.' . $last) . '@mail.example',
            'physical_address' => (100 + $i * 7) . ' ' . $last . ' Street, ' . $town,
            'status' => 'Approved',
            'created_by' => $userId,
            'approved_by' => $userId,
            'approved_at' => $today->modify('-' . mt_rand(60, 300) . ' days')->format('Y-m-d H:i:s'),
        ],
        [
            'bank_name' => $bank[0],
            'account_name' => $first . ' ' . $last,
            'account_number' => '62' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
            'account_type' => 'Cheque',
            'branch_name' => $town,
            'branch_code' => $bank[1],
            'is_primary' => 1,
        ],
        [
            'employer_name' => $employer[0],
            'employee_no' => 'EMP' . str_pad((string) mt_rand(1000, 99999), 5, '0', STR_PAD_LEFT),
            'job_title' => $employer[1],
            'employment_type' => 'Permanent',
            'employment_start_date' => $today->modify('-' . mt_rand(2, 14) . ' years')->format('Y-m-d'),
            'gross_salary' => $gross,
            'net_salary' => round($gross * 0.78, 2),
            'payment_day' => $employer[3],
            'employer_phone' => '+264 61 200 0000',
            'is_current' => 1,
        ],
        [[
            'contact_type' => 'Next of Kin',
            'full_name' => $lastNames[($i + 5) % count($lastNames)] . ' (relative)',
            'relationship' => 'Sibling',
            'phone' => '085 555 ' . str_pad((string) (200 + $i), 4, '0', STR_PAD_LEFT),
        ]]
    );
}
echo count($borrowerIds) . " borrowers created.\n";

// ---------------------------------------------------------------------------
// 6. Loans across the lifecycle, with payments
// ---------------------------------------------------------------------------
$productsById = [];
foreach ($db->query('SELECT * FROM loan_products')->fetchAll() as $p) {
    $productsById[(int) $p['id']] = $p;
}
$plansById = [];
foreach ($db->query('SELECT * FROM loan_plans')->fetchAll() as $p) {
    $plansById[(int) $p['id']] = $p;
}

$paymentSources = ['Debit Order', 'Debit Order', 'Debit Order', 'Bank Transfer', 'Cash'];

/**
 * @param string $mode 'pending' | 'approved' | 'active' (released + paid up to date)
 *                     | 'arrears' (released, last $missed due installments unpaid)
 *                     | 'completed' (released, everything paid)
 */
$makeLoan = function (int $borrowerIdx, int $planId, float $principal, string $startDate, string $mode, int $missed = 0) use (
    $db, $loans, $payments, $statutory, $accounts, $journal, $bankAccount, $userId, $today, $productsById, $plansById, $borrowerIds, $paymentSources
) {
    $borrower = (new App\Models\Borrower())->find($borrowerIds[$borrowerIdx]);
    $plan = $plansById[$planId];
    $product = $productsById[(int) $plan['product_id']];
    $employment = $db->query('SELECT payment_day FROM borrower_employment WHERE borrower_id = ' . (int) $borrower['id'])->fetchColumn();
    $paymentDay = $employment ? (int) $employment : 25;

    $levyRate = $statutory->namfisaLevyRateAsOf($startDate);
    $stamp = $statutory->dutyStampAmountAsOf($startDate);
    $schedule = LoanScheduleService::generate(
        $principal, (int) $plan['months'], (float) $plan['interest_rate'], (float) $plan['admin_fee'],
        $product['interest_method'], $startDate, $levyRate, $stamp, $paymentDay
    );

    $loanId = $loans->create([
        'branch_id' => (int) $borrower['branch_id'],
        'borrower_id' => (int) $borrower['id'],
        'product_id' => (int) $product['id'],
        'plan_id' => $planId,
        'loan_no' => generate_reference('LN'),
        'loan_type' => 'New Loan',
        'principal_amount' => $principal,
        'interest_amount' => $schedule['interest_amount'],
        'admin_fee' => $schedule['admin_fee'],
        'total_payable' => $schedule['total_payable'],
        'installment_amount' => $schedule['installment_amount'],
        'term_months' => (int) $plan['months'],
        'interest_rate' => (float) $plan['interest_rate'],
        'interest_recognition_method' => $product['interest_method'] === 'Fixed Fee' ? 'Upfront' : 'Progressive',
        'penalty_rate' => (float) $plan['penalty_rate'],
        'purpose' => ['School fees', 'Medical expenses', 'Home improvements', 'Vehicle repairs', 'Family event', 'Debt consolidation'][mt_rand(0, 5)],
        'loan_reason_code' => 'O',
        'payment_day' => $paymentDay,
        'loan_status' => 'Pending Approval',
        'approval_status' => 'Pending',
        'start_date' => $startDate,
        'maturity_date' => end($schedule['rows'])['due_date'] ?? null,
        'created_by' => $userId,
    ]);
    $loans->insertScheduleRows($loanId, $schedule['rows']);
    $loans->logStatus($loanId, null, 'Pending Approval', $userId, 'Loan created with amortization schedule.');

    if ($schedule['namfisa_levy'] > 0) {
        $statutory->recordNamfisaLevy([
            'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'branch_id' => (int) $borrower['branch_id'],
            'levy_date' => $startDate, 'levy_rate' => $levyRate, 'basis_amount' => $principal,
            'levy_amount' => $schedule['namfisa_levy'], 'status' => 'Calculated',
        ]);
    }
    if ($schedule['duty_stamp'] > 0) {
        $statutory->recordDutyStamp([
            'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'branch_id' => (int) $borrower['branch_id'],
            'stamp_date' => $startDate, 'basis_amount' => $principal,
            'stamp_amount' => $schedule['duty_stamp'], 'status' => 'Calculated',
        ]);
    }

    if ($mode === 'pending') {
        return $loanId;
    }

    $approvedAt = $startDate . ' 09:15:00';
    $loans->updateFields($loanId, ['loan_status' => 'Approved', 'approval_status' => 'Approved', 'approved_by' => $userId, 'approved_at' => $approvedAt]);
    $loans->logStatus($loanId, 'Pending Approval', 'Approved', $userId);
    if ($mode === 'approved') {
        return $loanId;
    }

    // Disbursement accounting, same entry LoanController::release() posts,
    // dated on the loan's start date rather than "today".
    $loan = $loans->find($loanId);
    $levy = $schedule['namfisa_levy'];
    $lines = [
        ['account_id' => $accounts->idByCode('1020'), 'debit' => round($principal, 2), 'credit' => 0, 'description' => 'Loan receivable for ' . $loan['loan_no']],
        ['account_id' => (int) $bankAccount['account_id'], 'debit' => 0, 'credit' => $principal, 'description' => 'Loan disbursed from ' . $bankAccount['bank_name'] . ' for ' . $loan['loan_no']],
    ];
    if ($levy > 0) {
        $lines[] = ['account_id' => $accounts->idByCode('1051'), 'debit' => $levy, 'credit' => 0, 'description' => 'NAMFISA levy receivable for ' . $loan['loan_no']];
        $lines[] = ['account_id' => $accounts->idByCode('2030'), 'debit' => 0, 'credit' => $levy, 'description' => 'NAMFISA levy withheld for ' . $loan['loan_no']];
    }
    if ($schedule['duty_stamp'] > 0) {
        $lines[] = ['account_id' => $accounts->idByCode('1060'), 'debit' => $schedule['duty_stamp'], 'credit' => 0, 'description' => 'Stamp duty receivable for ' . $loan['loan_no']];
        $lines[] = ['account_id' => $accounts->idByCode('2040'), 'debit' => 0, 'credit' => $schedule['duty_stamp'], 'description' => 'Duty stamp withheld for ' . $loan['loan_no']];
    }
    $journalId = $journal->post('LOAN_RELEASED', 'loans', $loanId, $loan['loan_no'], 'Loan disbursed: ' . $loan['loan_no'], $lines, $userId, $startDate);
    if ($statutory->findNamfisaLevyByLoan($loanId)) {
        $statutory->markNamfisaLevyPosted($loanId, $journalId);
    }
    if ($statutory->findDutyStampByLoan($loanId)) {
        $statutory->markDutyStampPosted($loanId, $journalId);
    }

    $loans->updateFields($loanId, ['loan_status' => 'Active', 'released_by' => $userId, 'released_at' => $startDate . ' 10:30:00']);
    $loans->logStatus($loanId, 'Approved', 'Active', $userId, 'Loan released / disbursed.');
    $loans->createDisbursement([
        'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'disbursement_no' => generate_reference('DSB'),
        'disbursement_date' => $startDate, 'disbursement_method' => 'Bank Transfer', 'bank_account_id' => $bankAccount['id'],
        'amount' => $principal, 'status' => 'Disbursed', 'approved_by' => $userId, 'approved_at' => $approvedAt,
        'disbursed_by' => $userId, 'disbursed_at' => $startDate . ' 10:30:00', 'created_by' => $userId,
    ]);

    // Payments: every installment already due, except the most recent $missed
    $dueRows = $db->prepare('SELECT installment_no, due_date, total_due FROM loan_schedules WHERE loan_id = ? AND due_date <= ? ORDER BY installment_no');
    $dueRows->execute([$loanId, $today->format('Y-m-d')]);
    $rows = $dueRows->fetchAll();
    if ($mode === 'arrears' && $missed > 0) {
        $rows = array_slice($rows, 0, max(0, count($rows) - $missed));
    }
    foreach ($rows as $row) {
        $payDate = (new DateTimeImmutable($row['due_date']))->modify('+' . mt_rand(0, 2) . ' days');
        if ($payDate > $today) {
            $payDate = $today;
        }
        $fresh = $loans->find($loanId);
        $payments->recordAndAllocate($fresh, (float) $row['total_due'], [
            'payment_date' => $payDate->format('Y-m-d'),
            'payment_source' => $paymentSources[mt_rand(0, count($paymentSources) - 1)],
            'bank_account_id' => $bankAccount['id'],
            'reference_no' => 'PAY' . mt_rand(100000, 999999),
            'payer_name' => $borrower['first_name'] . ' ' . $borrower['last_name'],
            'notes' => null,
            'user_id' => $userId,
        ]);
    }
    return $loanId;
};

$m = fn (int $months, int $day = 0) => $today->modify("-{$months} months")->modify($day ? "-{$day} days" : 'now')->format('Y-m-d');

// [borrower index, plan id, principal, start date, mode, missed installments]
$scenarios = [
    // Completed loans
    [0, 2, 3000, $m(8), 'completed'], [1, 2, 5000, $m(9), 'completed'], [2, 3, 8000, $m(10), 'completed'],
    [3, 2, 2500, $m(7), 'completed'], [4, 5, 1500, $m(6), 'completed'], [5, 2, 4000, $m(8, 10), 'completed'],
    [6, 3, 10000, $m(11), 'completed'], [7, 5, 2000, $m(5), 'completed'],
    // Active, up to date
    [8, 3, 6000, $m(4), 'active'], [9, 4, 12000, $m(3), 'active'], [10, 2, 3500, $m(2), 'active'],
    [11, 3, 9000, $m(3, 5), 'active'], [12, 4, 15000, $m(2, 8), 'active'], [13, 3, 7500, $m(4, 12), 'active'],
    [14, 2, 2800, $m(1, 10), 'active'], [15, 3, 5500, $m(2), 'active'], [16, 4, 20000, $m(3), 'active'],
    [17, 5, 1800, $m(0, 20), 'active'], [18, 3, 8500, $m(1, 5), 'active'], [19, 2, 4500, $m(2, 2), 'active'],
    // In arrears
    [20, 3, 6500, $m(5), 'arrears', 2], [21, 4, 14000, $m(5, 6), 'arrears', 3],
    [22, 3, 5000, $m(4), 'arrears', 1], [23, 2, 3000, $m(3), 'arrears', 2], [24, 3, 9500, $m(4, 15), 'arrears', 2],
    // Awaiting approval / release
    [25, 3, 6000, $today->format('Y-m-d'), 'pending'], [26, 4, 11000, $today->format('Y-m-d'), 'pending'],
    [27, 2, 3000, $today->format('Y-m-d'), 'approved'],
];
foreach ($scenarios as $s) {
    $makeLoan($s[0], $s[1], (float) $s[2], $s[3], $s[4], $s[5] ?? 0);
}
echo count($scenarios) . " loans created (with schedules, disbursements and payments).\n";

// ---------------------------------------------------------------------------
// 7. Loan applications in the pipeline
// ---------------------------------------------------------------------------
$applicants = [
    ['Submitted', 'Tangeni', 'Nauyoma', 'Female', 4500, 3, 'Online'],
    ['Submitted', 'Kaarina', 'Shapumba', 'Female', 8000, 6, 'Online'],
    ['Screening', 'Uazuva', 'Kaviri', 'Male', 12000, 6, 'Back Office'],
    ['Documents Required', 'Emmanuel', 'Kaunapawa', 'Male', 3000, 3, 'Online'],
    ['Approved', 'Lydia', 'Nghifikwa', 'Female', 6500, 6, 'Online'],
    ['Rejected', 'Danny', 'Steenkamp', 'Male', 25000, 12, 'Online'],
];
foreach ($applicants as $n => [$status, $first, $last, $gender, $amount, $term, $source]) {
    $employer = $employers[$n % count($employers)];
    $appId = (new App\Models\LoanApplication())->create([
        'branch_id' => 1,
        'application_no' => generate_reference('APP'),
        'application_source' => $source,
        'application_type' => 'New Loan',
        'requested_amount' => $amount,
        'requested_term_months' => $term,
        'requested_purpose' => 'Personal expenses',
        'applicant_first_name' => $first,
        'applicant_last_name' => $last,
        'applicant_id_number' => $today->modify('-' . (30 + $n) . ' years')->format('ymd') . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
        'applicant_phone' => '081 666 ' . str_pad((string) (300 + $n), 4, '0', STR_PAD_LEFT),
        'applicant_email' => strtolower($first . '.' . $last) . '@mail.example',
        'applicant_gender' => $gender,
        'applicant_address' => (10 + $n) . ' Demo Street, Windhoek',
        'employer_name' => $employer[0],
        'gross_salary' => $employer[2],
        'net_salary' => round($employer[2] * 0.78, 2),
        'payment_day' => $employer[3],
        'bank_name' => 'FNB Namibia',
        'bank_account_name' => $first . ' ' . $last,
        'bank_account_number' => '62' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
        'status' => $status,
        'rejection_reason' => $status === 'Rejected' ? 'Requested amount exceeds affordability based on submitted payslips.' : null,
    ]);
    (new App\Models\LoanApplication())->addStatusHistory($appId, null, 'Submitted', $userId, 'Application received.');
}
echo count($applicants) . " applications created.\n";

// ---------------------------------------------------------------------------
// 8. Public-form intake source (demo apply form posts here)
// ---------------------------------------------------------------------------
$db->prepare("INSERT INTO intake_sources (id, source_code, source_name, api_token, allowed_origin, is_active) VALUES (1, 'demo', 'DesertLedger Demo Apply Form', ?, NULL, 1) ON DUPLICATE KEY UPDATE api_token = VALUES(api_token), is_active = 1")
   ->execute(['e7cf32450b29a9c76f78834c053c5c398fcb4f5c']); // public by design: also embedded in public/apply/index.html
echo "Intake source 'demo' ensured (token stored in intake_sources).\n";

// ---------------------------------------------------------------------------
// 9. HR
// ---------------------------------------------------------------------------
$db->exec("INSERT INTO hrm_departments (id, department_name, branch_id, is_active, created_by) VALUES
    (1, 'Operations', 1, 1, 1), (2, 'Finance', 1, 1, 1), (3, 'Collections', 1, 1, 1), (4, 'Customer Service', 1, 1, 1)");
$db->exec("INSERT INTO hrm_designations (id, designation_name, branch_id, department_id, is_active, created_by) VALUES
    (1, 'Branch Manager', 1, 1, 1, 1), (2, 'Loan Officer', 1, 1, 1, 1), (3, 'Accountant', 1, 2, 1, 1),
    (4, 'Collections Officer', 1, 3, 1, 1), (5, 'Customer Service Agent', 1, 4, 1, 1)");
$staff = [
    ['Hilaria', 'Amupolo', 'Female', 1, 1, 32000], ['Tobias', 'Nashandi', 'Male', 2, 1, 16500],
    ['Rachel', 'Mwandingi', 'Female', 3, 2, 21000], ['Josef', 'Uirab', 'Male', 4, 3, 15500],
    ['Petronella', 'Haindongo', 'Female', 5, 4, 12500],
];
$empStmt = $db->prepare("INSERT INTO hrm_employees (employee_no, first_name, last_name, email, phone, gender, date_of_joining, employment_type, status, basic_salary, branch_id, department_id, designation_id, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'Full-Time', 'Active', ?, 1, ?, ?, 1)");
foreach ($staff as $n => [$first, $last, $gender, $desig, $dept, $salary]) {
    $empStmt->execute(['DL-' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), $first, $last, strtolower($first . '.' . $last) . '@desertledger.example',
        '081 777 ' . str_pad((string) (400 + $n), 4, '0', STR_PAD_LEFT), $gender, $today->modify('-' . (6 + $n * 5) . ' months')->format('Y-m-d'), $salary, $dept, $desig]);
}
echo "HR departments, designations and staff created.\n";

echo "\nDemo seed complete. Shared login: demo / DEMO_PASSWORD. Private login: owner / OWNER_PASSWORD.\n";
