<?php

/**
 * Builds (or rebuilds) the Solid Desert sandbox database.
 *
 *  1. Copies the real business CONFIGURATION from production (company,
 *     branches, loan product/plans, chart of accounts, roles/permissions,
 *     templates, settings...) -- an explicit allow-list; any table not named
 *     here is left EMPTY, so nothing personal is copied by accident.
 *  2. Copies the people tables (users, HR employees) MASKED: names, contact
 *     details, bank details, salaries and password hashes are replaced with
 *     fictional values before they are ever written to the sandbox.
 *  3. Borrows fiscal-year/period/holiday reference rows from the demo
 *     database (production has none yet) and its integration settings
 *     (Collexia UAT etc., same encryption key).
 *  4. Adds fictional borrowers, loans, payments and applications so there is
 *     something to test with, plus a private "sandbox" login.
 *
 * SAFETY: refuses to run unless sandbox mode is on and the connected database
 * name contains "sandbox". Production is only ever READ.
 *
 *   MLS_SANDBOX_MODE=1 SANDBOX_PASSWORD='...' php bin/sandbox_build.php
 * Optional: SOURCE_CONFIG (production database.php), DEMO_CONFIG (demo database.php).
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\StatutoryCharge;
use App\Services\InterestAccrualService;
use App\Services\LoanScheduleService;
use App\Support\DemoMode;

if (!DemoMode::sandbox()) {
    fwrite(STDERR, "Refusing to run: sandbox mode is not enabled.\n");
    exit(1);
}
$target = Database::connection();
$targetName = (string) $target->query('SELECT DATABASE()')->fetchColumn();
if (stripos($targetName, 'sandbox') === false) {
    fwrite(STDERR, "Refusing to run: database \"{$targetName}\" does not look like a sandbox database.\n");
    exit(1);
}
$sandboxPassword = getenv('SANDBOX_PASSWORD') ?: '';
if (strlen($sandboxPassword) < 12) {
    fwrite(STDERR, "Set SANDBOX_PASSWORD (12+ characters) for the private sandbox login.\n");
    exit(1);
}

$home = getenv('HOME') ?: '';
$connect = function (string $configFile): PDO {
    $c = require $configFile;
    return new PDO(
        "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$c['charset']}",
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
};
$sourceConfig = getenv('SOURCE_CONFIG') ?: $home . '/public_html/mls/config/database.php';
$demoConfig = getenv('DEMO_CONFIG') ?: $home . '/demo_app/config/database.php';
$source = $connect($sourceConfig);
$demo = $connect($demoConfig);
if ((string) $source->query('SELECT DATABASE()')->fetchColumn() === $targetName) {
    fwrite(STDERR, "Refusing to run: source and target are the same database.\n");
    exit(1);
}

mt_srand(2026);
$today = new DateTimeImmutable('today');

function copyRows(PDO $from, PDO $to, string $table, ?string $where = null, ?callable $transform = null): int
{
    $rows = $from->query("SELECT * FROM `{$table}`" . ($where ? " WHERE {$where}" : ''))->fetchAll();
    $n = 0;
    foreach ($rows as $row) {
        if ($transform) {
            $row = $transform($row);
            if ($row === null) {
                continue;
            }
        }
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $to->prepare($sql)->execute(array_values($row));
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// 1. Wipe the sandbox
// ---------------------------------------------------------------------------
$target->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($target->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
    $target->exec("TRUNCATE TABLE `{$table}`");
}
echo "Wiped sandbox.\n";

// ---------------------------------------------------------------------------
// 2. Configuration copied from production (explicit allow-list)
// ---------------------------------------------------------------------------
$copyFromProduction = [
    'accounting_accounts', 'approval_policies', 'asset_categories', 'branches', 'companies', 'cpl_settings',
    'dashboard_widgets', 'data_quality_rules', 'document_templates', 'document_template_categories',
    'document_template_fields', 'duty_stamp_settings', 'expense_categories', 'hrm_departments',
    'hrm_designations', 'hrm_holidays', 'hrm_leave_types', 'hrm_shifts', 'loan_breakdown_size_bands',
    'loan_plans', 'loan_products', 'namfisa_levy_settings', 'notification_templates', 'pay_cycle_policies',
    'pay_cycle_settings', 'payment_methods', 'performance_goal_types', 'performance_indicator_categories',
    'permissions', 'regulatory_report_types', 'report_definitions', 'retention_policies', 'role_permissions',
    'roles', 'salary_breakdown_bands', 'schema_migrations', 'security_rules', 'social_analytics_settings',
    'user_roles',
];
$sourceTables = $source->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($copyFromProduction as $table) {
    if (!in_array($table, $sourceTables, true)) {
        echo sprintf("  %-34s (not in source, skipped)\n", $table);
        continue;
    }
    $n = copyRows($source, $target, $table);
    echo sprintf("  %-34s %d\n", $table, $n);
}
copyRows($source, $target, 'system_settings', "setting_key <> 'security_alert_recipient_email'");
$target->exec("UPDATE system_settings SET setting_value = 'friendly' WHERE setting_key = 'error_display_mode'");

$target->prepare("INSERT INTO intake_sources (id, source_code, source_name, api_token, is_active) VALUES (1, 'sandbox', 'Sandbox intake', ?, 1)")
    ->execute([bin2hex(random_bytes(20))]);
$firstSource = (int) $source->query('SELECT MIN(intake_source_id) FROM intake_field_mappings')->fetchColumn();
copyRows($source, $target, 'intake_field_mappings', 'intake_source_id = ' . $firstSource, function ($r) {
    unset($r['id']);
    $r['intake_source_id'] = 1;
    return $r;
});
echo "Copied configuration from production.\n";

// ---------------------------------------------------------------------------
// 3. People tables, MASKED before they touch the sandbox
// ---------------------------------------------------------------------------
$firstNames = ['Johannes', 'Maria', 'Petrus', 'Selma', 'Tuyeni', 'Ndapewa', 'Elias', 'Hilma', 'Festus', 'Rauna', 'Simeon', 'Loide'];
$lastNames = ['Shikongo', 'Amukoto', 'Nangolo', 'Haufiku', 'Iipinge', 'Kandjimi', 'Nghidinwa', 'Shilongo', 'Hamutenya', 'Namupala', 'Tjivikua', 'Ekandjo'];

copyRows($source, $target, 'users', null, function ($r) {
    $id = (int) $r['id'];
    return [
        'id' => $id,
        'branch_id' => $r['branch_id'] ?? null,
        'name' => 'Sandbox User ' . $id,
        'username' => 'user' . $id,
        'email' => 'user' . $id . '@sandbox.example',
        'password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT),
        'phone' => null,
        'user_type' => $r['user_type'],
        'is_active' => $r['is_active'] ?? 1,
        'bypass_ip_restriction' => 0,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
});

$target->prepare("INSERT INTO users (id, branch_id, name, username, email, password, user_type, is_active) VALUES (1000, 1, 'Sandbox Owner', 'sandbox', 'sandbox@sandbox.example', ?, 'Super Admin', 1)")
    ->execute([password_hash($sandboxPassword, PASSWORD_BCRYPT)]);
$target->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1000, 1)");

$employeeCount = copyRows($source, $target, 'hrm_employees', null, function ($r) use ($firstNames, $lastNames) {
    $id = (int) $r['id'];
    $first = $firstNames[$id % count($firstNames)];
    $last = $lastNames[($id * 7) % count($lastNames)];
    return [
        'id' => $id,
        'employee_no' => $r['employee_no'],
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower($first . '.' . $last . $id) . '@sandbox.example',
        'phone' => '081 555 ' . str_pad((string) (500 + $id), 4, '0', STR_PAD_LEFT),
        'date_of_birth' => date('Y-m-d', strtotime('1985-01-01 +' . ($id * 173) . ' days')),
        'gender' => $r['gender'] ?? null,
        'date_of_joining' => $r['date_of_joining'] ?? null,
        'employment_type' => $r['employment_type'] ?? 'Full-Time',
        'status' => $r['status'] ?? 'Active',
        'is_commission_agent' => $r['is_commission_agent'] ?? 0,
        'referral_code' => !empty($r['referral_code']) ? 'SBX' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) : null,
        'address_line_1' => (10 + $id) . ' Sandbox Street',
        'city' => 'Windhoek',
        'country' => 'Namibia',
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_number' => '085 555 ' . str_pad((string) (600 + $id), 4, '0', STR_PAD_LEFT),
        'bank_name' => 'FNB Namibia',
        'account_holder_name' => $first . ' ' . $last,
        'account_number' => '62' . str_pad((string) ($id * 1234567 % 1000000000), 9, '0', STR_PAD_LEFT),
        'branch_code' => '282672',
        'tax_payer_id' => 'TIN' . str_pad((string) $id, 7, '0', STR_PAD_LEFT),
        'basic_salary' => 9000 + (($id * 1750) % 14000),
        'hours_per_day' => $r['hours_per_day'] ?? 8,
        'days_per_week' => $r['days_per_week'] ?? 5,
        'rate_per_hour' => $r['rate_per_hour'] ?? 0,
        'user_id' => $r['user_id'] ?? null,
        'branch_id' => $r['branch_id'] ?? null,
        'department_id' => $r['department_id'] ?? null,
        'designation_id' => $r['designation_id'] ?? null,
        'shift_id' => $r['shift_id'] ?? null,
        'created_by' => $r['created_by'] ?? null,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
});
echo "Masked users and {$employeeCount} HR employees.\n";

// ---------------------------------------------------------------------------
// 4. Reference rows production does not have yet (from the demo database)
// ---------------------------------------------------------------------------
foreach (['accounting_fiscal_years', 'accounting_periods', 'public_holidays', 'collexia_settings', 'creditinfo_settings'] as $table) {
    copyRows($demo, $target, $table);
}
copyRows($demo, $target, 'notification_settings', "setting_key LIKE 'OPENAI\\_%'");
$target->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Copied fiscal periods, holidays and test-environment integration settings from the demo.\n";

// ---------------------------------------------------------------------------
// 5. Fictional operational data
// ---------------------------------------------------------------------------
$accounts = new AccountingAccount();
$journal = new AccountingJournal();
$bankAccounts = new BankAccount();
$statutory = new StatutoryCharge();
$loans = new Loan();
$payments = new Payment();
$userId = 1000;

$bankGl = $accounts->idByCode('1010');
$bankAccountId = $bankAccounts->create([
    'account_name' => 'Sandbox Operating Account', 'bank_name' => 'FNB Namibia', 'account_number' => '62000000002',
    'branch' => 'Windhoek Main', 'branch_code' => '282672', 'swift_code' => 'FIRNNANX',
    'account_id' => $bankGl, 'opening_balance' => 0, 'is_active' => 1,
]);
$bankAccount = $bankAccounts->find($bankAccountId);

$fyStart = (string) $target->query('SELECT MIN(start_date) FROM accounting_fiscal_years')->fetchColumn();
$journal->post('MANUAL', 'accounting_journal_entries', null, 'OPEN-CAPITAL', 'Owner capital introduced for sandbox testing', [
    ['account_id' => $bankGl, 'debit' => 1500000, 'credit' => 0, 'description' => 'Capital received'],
    ['account_id' => $accounts->idByCode('3010'), 'debit' => 0, 'credit' => 1500000, 'description' => 'Owner capital'],
], $userId, $fyStart, 'Manual');

$branchIds = array_map('intval', $target->query('SELECT id FROM branches ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN));
$product = $target->query('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch();
$plansByMonths = [];
foreach ($target->query('SELECT * FROM loan_plans WHERE product_id = ' . (int) $product['id'] . ' AND is_active = 1')->fetchAll() as $p) {
    $plansByMonths[(int) $p['months']] = $p;
}
ksort($plansByMonths);
$monthsAvail = array_keys($plansByMonths);
$upfront = $product['interest_method'] === 'Fixed Fee';

$borrowerNames = [
    ['Male', 'Johannes'], ['Female', 'Maria'], ['Male', 'Petrus'], ['Female', 'Selma'], ['Male', 'Tuyeni'],
    ['Female', 'Ndapewa'], ['Male', 'Elias'], ['Female', 'Hilma'], ['Male', 'Festus'], ['Female', 'Rauna'],
    ['Male', 'Simeon'], ['Female', 'Loide'], ['Male', 'Fillemon'], ['Female', 'Anna'], ['Male', 'Hendrik'],
    ['Female', 'Magdalena'], ['Male', 'Immanuel'], ['Female', 'Saara'], ['Male', 'Ludwig'], ['Female', 'Julia'],
    ['Male', 'Mathews'], ['Female', 'Eveline'], ['Male', 'Gideon'], ['Female', 'Bertha'], ['Male', 'Kandjii'],
    ['Female', 'Vetu'], ['Male', 'Abner'], ['Female', 'Christine'],
];
$borrowerLast = [
    'Shikongo', 'Amukoto', 'Nangolo', 'Haufiku', 'Iipinge', 'Kandjimi', 'Nghidinwa', 'Shilongo', 'Hamutenya', 'Namupala',
    'Tjivikua', 'Ekandjo', 'Nekongo', 'Uushona', 'Kaulinge', 'Mbumba', 'Katjiuanjo', 'Hangula', 'Ndeitunga', 'Shivute',
    'Amakali', 'Hishekwa', 'Nakale', 'Kapofi', 'Auala', 'Nujoma', 'Tjiueza', 'Garoeb',
];
$employers = [
    ['Sandbox Ministry of Education', 'Teacher', 18500, 25], ['Sandbox Health Services', 'Nurse', 21000, 25],
    ['Sandbox Police', 'Officer', 19500, 25], ['Sandbox Municipality', 'Clerk', 16500, 25],
    ['Sandbox Power Utility', 'Technician', 24000, 25], ['Sandbox Retail Group', 'Supervisor', 12500, 30],
];

$borrowerIds = [];
foreach ($borrowerNames as $i => [$gender, $first]) {
    $last = $borrowerLast[$i];
    $employer = $employers[$i % count($employers)];
    $dob = $today->modify('-' . mt_rand(26, 58) . ' years')->modify('-' . mt_rand(0, 300) . ' days');
    $borrowerIds[$i] = (new App\Models\Borrower())->createFull(
        [
            'branch_id' => $branchIds[$i % count($branchIds)], 'borrower_no' => generate_reference('BRW'),
            'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => $dob->format('Y-m-d'),
            'id_number' => $dob->format('ymd') . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
            'marital_status' => ['Single', 'Married'][$i % 2], 'nationality' => 'Namibian',
            'phone' => '081 555 ' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
            'email' => strtolower($first . '.' . $last) . '@mail.example',
            'physical_address' => (100 + $i * 7) . ' ' . $last . ' Street', 'status' => 'Approved',
            'created_by' => $userId, 'approved_by' => $userId,
            'approved_at' => $today->modify('-' . mt_rand(60, 300) . ' days')->format('Y-m-d H:i:s'),
        ],
        [
            'bank_name' => 'FNB Namibia', 'account_name' => $first . ' ' . $last,
            'account_number' => '62' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
            'account_type' => 'Cheque', 'branch_name' => 'Windhoek', 'branch_code' => '282672', 'is_primary' => 1,
        ],
        [
            'employer_name' => $employer[0], 'employee_no' => 'EMP' . str_pad((string) mt_rand(1000, 99999), 5, '0', STR_PAD_LEFT),
            'job_title' => $employer[1], 'employment_type' => 'Permanent',
            'employment_start_date' => $today->modify('-' . mt_rand(2, 14) . ' years')->format('Y-m-d'),
            'gross_salary' => $employer[2], 'net_salary' => round($employer[2] * 0.78, 2), 'payment_day' => $employer[3], 'is_current' => 1,
        ],
        []
    );
}

$closestMonths = function (int $want) use ($monthsAvail): int {
    $best = $monthsAvail[0];
    foreach ($monthsAvail as $m) {
        if (abs($m - $want) < abs($best - $want)) {
            $best = $m;
        }
    }
    return $best;
};
$paymentSources = ['Debit Order', 'Debit Order', 'Bank Transfer', 'Cash'];

$makeLoan = function (int $idx, int $wantMonths, float $principal, string $startDate, string $mode, int $missed = 0) use (
    $target, $loans, $payments, $statutory, $accounts, $journal, $bankAccount, $userId, $today, $product, $plansByMonths,
    $closestMonths, $borrowerIds, $paymentSources, $upfront
) {
    $plan = $plansByMonths[$closestMonths($wantMonths)];
    $borrower = (new App\Models\Borrower())->find($borrowerIds[$idx]);
    $paymentDay = 25;
    $levyRate = $statutory->namfisaLevyRateAsOf($startDate);
    $stamp = $statutory->dutyStampAmountAsOf($startDate);
    $schedule = LoanScheduleService::generate(
        $principal, (int) $plan['months'], (float) $plan['interest_rate'], (float) $plan['admin_fee'],
        $product['interest_method'], $startDate, $levyRate, $stamp, $paymentDay
    );

    $loanId = $loans->create([
        'branch_id' => (int) $borrower['branch_id'], 'borrower_id' => (int) $borrower['id'], 'product_id' => (int) $product['id'],
        'plan_id' => (int) $plan['id'], 'loan_no' => generate_reference('LN'), 'loan_type' => 'New Loan', 'principal_amount' => $principal,
        'interest_amount' => $schedule['interest_amount'], 'admin_fee' => $schedule['admin_fee'], 'total_payable' => $schedule['total_payable'],
        'installment_amount' => $schedule['installment_amount'], 'term_months' => (int) $plan['months'], 'interest_rate' => (float) $plan['interest_rate'],
        'interest_recognition_method' => $upfront ? 'Upfront' : 'Progressive', 'penalty_rate' => (float) ($plan['penalty_rate'] ?? 0),
        'purpose' => ['School fees', 'Medical expenses', 'Home improvements', 'Vehicle repairs'][mt_rand(0, 3)], 'loan_reason_code' => 'O',
        'payment_day' => $paymentDay, 'loan_status' => 'Pending Approval', 'approval_status' => 'Pending', 'start_date' => $startDate,
        'maturity_date' => end($schedule['rows'])['due_date'] ?? null, 'created_by' => $userId,
    ]);
    $loans->insertScheduleRows($loanId, $schedule['rows']);
    $loans->logStatus($loanId, null, 'Pending Approval', $userId, 'Loan created with amortization schedule.');
    if ($schedule['namfisa_levy'] > 0) {
        $statutory->recordNamfisaLevy([
            'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'branch_id' => (int) $borrower['branch_id'], 'levy_date' => $startDate,
            'levy_rate' => $levyRate, 'basis_amount' => $principal, 'levy_amount' => $schedule['namfisa_levy'], 'status' => 'Calculated',
        ]);
    }
    if ($schedule['duty_stamp'] > 0) {
        $statutory->recordDutyStamp([
            'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'branch_id' => (int) $borrower['branch_id'], 'stamp_date' => $startDate,
            'basis_amount' => $principal, 'stamp_amount' => $schedule['duty_stamp'], 'status' => 'Calculated',
        ]);
    }
    if ($mode === 'pending') {
        return;
    }
    $loans->updateFields($loanId, ['loan_status' => 'Approved', 'approval_status' => 'Approved', 'approved_by' => $userId, 'approved_at' => $startDate . ' 09:15:00']);
    $loans->logStatus($loanId, 'Pending Approval', 'Approved', $userId);
    if ($mode === 'approved') {
        return;
    }

    $loan = $loans->find($loanId);
    $levy = $schedule['namfisa_levy'];
    $lines = [
        ['account_id' => $accounts->idByCode('1020'), 'debit' => round($principal, 2), 'credit' => 0, 'description' => 'Loan receivable for ' . $loan['loan_no']],
        ['account_id' => (int) $bankAccount['account_id'], 'debit' => 0, 'credit' => $principal, 'description' => 'Loan disbursed for ' . $loan['loan_no']],
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
    if ($upfront) {
        InterestAccrualService::recognizeUpfront($loanId, $startDate, $userId);
    }
    $loans->updateFields($loanId, ['loan_status' => 'Active', 'released_by' => $userId, 'released_at' => $startDate . ' 10:30:00']);
    $loans->logStatus($loanId, 'Approved', 'Active', $userId, 'Loan released / disbursed.');
    $loans->createDisbursement([
        'loan_id' => $loanId, 'borrower_id' => (int) $borrower['id'], 'disbursement_no' => generate_reference('DSB'), 'disbursement_date' => $startDate,
        'disbursement_method' => 'Bank Transfer', 'bank_account_id' => $bankAccount['id'], 'amount' => $principal, 'status' => 'Disbursed',
        'approved_by' => $userId, 'approved_at' => $startDate . ' 09:15:00', 'disbursed_by' => $userId, 'disbursed_at' => $startDate . ' 10:30:00', 'created_by' => $userId,
    ]);

    $dueRows = $target->prepare('SELECT installment_no, due_date, total_due FROM loan_schedules WHERE loan_id = ? AND due_date <= ? ORDER BY installment_no');
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
        $payments->recordAndAllocate($loans->find($loanId), (float) $row['total_due'], [
            'payment_date' => $payDate->format('Y-m-d'), 'payment_source' => $paymentSources[mt_rand(0, count($paymentSources) - 1)],
            'bank_account_id' => $bankAccount['id'], 'reference_no' => 'PAY' . mt_rand(100000, 999999),
            'payer_name' => $borrower['first_name'] . ' ' . $borrower['last_name'], 'notes' => null, 'user_id' => $userId,
        ]);
    }
};

$m = fn (int $months, int $day = 0) => $today->modify("-{$months} months")->modify($day ? "-{$day} days" : 'now')->format('Y-m-d');
$now = $today->format('Y-m-d');
// [borrower, plan months wanted, principal, start date, mode, missed installments]
$scenarios = [
    [0, 3, 3000, $m(5), 'completed'], [1, 3, 5000, $m(6), 'completed'], [2, 5, 8000, $m(8), 'completed'],
    [3, 2, 2500, $m(4), 'completed'], [4, 1, 1500, $m(3), 'completed'], [5, 3, 4000, $m(6, 10), 'completed'],
    [6, 4, 6000, $m(3), 'active'], [7, 5, 9000, $m(3, 5), 'active'], [8, 3, 3500, $m(2), 'active'],
    [9, 5, 12000, $m(2, 8), 'active'], [10, 4, 7500, $m(3, 12), 'active'], [11, 2, 2800, $m(1, 10), 'active'],
    [12, 3, 5500, $m(2), 'active'], [13, 5, 15000, $m(2), 'active'], [14, 1, 1800, $m(0, 20), 'active'],
    [15, 3, 8500, $m(1, 5), 'active'],
    [16, 4, 6500, $m(4), 'arrears', 2], [17, 5, 10000, $m(4, 6), 'arrears', 3],
    [18, 3, 5000, $m(3), 'arrears', 1], [19, 3, 3000, $m(3, 3), 'arrears', 2],
    [20, 3, 6000, $now, 'pending'], [21, 4, 11000, $now, 'pending'], [22, 2, 3000, $now, 'approved'],
];
foreach ($scenarios as $s) {
    $makeLoan($s[0], $s[1], (float) $s[2], $s[3], $s[4], $s[5] ?? 0);
}
echo count($scenarios) . " fictional loans created.\n";

$applicants = [
    ['Submitted', 'Tangeni', 'Nauyoma', 'Female', 4500, 3], ['Submitted', 'Kaarina', 'Shapumba', 'Female', 8000, 4],
    ['Screening', 'Uazuva', 'Kaviri', 'Male', 12000, 5], ['Documents Required', 'Emmanuel', 'Kaunapawa', 'Male', 3000, 3],
    ['Approved', 'Lydia', 'Nghifikwa', 'Female', 6500, 4], ['Rejected', 'Danny', 'Steenkamp', 'Male', 25000, 5],
];
foreach ($applicants as $n => [$status, $first, $last, $gender, $amount, $term]) {
    $employer = $employers[$n % count($employers)];
    $appId = (new App\Models\LoanApplication())->create([
        'branch_id' => $branchIds[0], 'application_no' => generate_reference('APP'), 'application_source' => 'Back Office', 'application_type' => 'New Loan',
        'requested_amount' => $amount, 'requested_term_months' => $term, 'requested_purpose' => 'Personal expenses',
        'applicant_first_name' => $first, 'applicant_last_name' => $last,
        'applicant_id_number' => $today->modify('-' . (30 + $n) . ' years')->format('ymd') . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
        'applicant_phone' => '081 666 ' . str_pad((string) (300 + $n), 4, '0', STR_PAD_LEFT),
        'applicant_email' => strtolower($first . '.' . $last) . '@mail.example',
        'applicant_gender' => $gender, 'applicant_address' => (10 + $n) . ' Sandbox Street', 'employer_name' => $employer[0],
        'gross_salary' => $employer[2], 'net_salary' => round($employer[2] * 0.78, 2), 'payment_day' => $employer[3],
        'bank_name' => 'FNB Namibia', 'bank_account_name' => $first . ' ' . $last,
        'bank_account_number' => '62' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
        'status' => $status, 'rejection_reason' => $status === 'Rejected' ? 'Requested amount exceeds affordability.' : null,
    ]);
    (new App\Models\LoanApplication())->addStatusHistory($appId, null, 'Submitted', $userId, 'Application received.');
}
echo count($applicants) . " fictional applications created.\n";
echo "\nSandbox build complete. Private login: sandbox / SANDBOX_PASSWORD.\n";
