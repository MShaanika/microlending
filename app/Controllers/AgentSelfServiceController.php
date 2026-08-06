<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AgentCommission;
use App\Models\AgentCommissionEntry;
use App\Models\Branch;
use App\Models\HrmEmployee;
use App\Models\LoanApplication;
use App\Support\PhoneNumberNormalizer;

/**
 * Marketing agent self-service: submit a new borrower referral and see
 * their own running commission totals. Every action is gated by
 * Auth::requireLogin() + a linked, commission-eligible employee record --
 * mirrors EmployeeSelfServiceController::resolveEmployee() exactly, and
 * never accepts an employee/agent id from the request.
 */
class AgentSelfServiceController extends Controller
{
    private HrmEmployee $employees;
    private LoanApplication $applications;
    private AgentCommission $commissions;
    private AgentCommissionEntry $commissionEntries;
    private Branch $branches;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->applications = new LoanApplication();
        $this->commissions = new AgentCommission();
        $this->commissionEntries = new AgentCommissionEntry();
        $this->branches = new Branch();
    }

    private function resolveAgent(): ?array
    {
        Auth::requireLogin();
        $employee = $this->employees->findByUserId((int) (Auth::user()['id'] ?? 0));
        if (!$employee || (int) $employee['is_commission_agent'] !== 1) {
            Session::flash('error', 'Your account is not set up as a marketing agent.');
            $this->redirect('/dashboard');
            return null;
        }
        return $employee;
    }

    public function dashboard(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $rows = $this->commissions->allForAgent((int) $agent['id']);
        $earned = array_sum(array_column($rows, 'earned_amount'));
        $paid = array_sum(array_column($rows, 'paid_amount'));

        $this->view('my/agent/dashboard', [
            'title' => 'My Referrals',
            'agent' => $agent,
            'referralLink' => $agent['referral_code']
                ? public_site_url('/apply-dg.php?refId=' . urlencode((string) $agent['referral_code']))
                : null,
            'totals' => [
                'total_commission' => array_sum(array_column($rows, 'total_commission_amount')),
                'earned' => $earned,
                'paid' => $paid,
                'outstanding' => round($earned - $paid, 2),
            ],
            'recentCommissions' => array_slice($rows, 0, 5),
        ]);
    }

    public function referralCreate(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/referral_create', [
            'title' => 'New Referral',
            'agent' => $agent,
            'branches' => empty($agent['branch_id']) ? $this->branches->all() : [],
            'old' => [],
            'errors' => [],
        ]);
    }

    public function referralStore(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/my/referrals/create');
            return;
        }

        [$data, $errors] = $this->validateReferral($_POST, $agent);

        if (!empty($errors)) {
            $this->view('my/agent/referral_create', [
                'title' => 'New Referral',
                'agent' => $agent,
                'branches' => empty($agent['branch_id']) ? $this->branches->all() : [],
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $applicationNo = generate_reference('APP');
        $data['application_no'] = $applicationNo;
        $data['application_source'] = 'Back Office';
        $data['application_type'] = 'New Loan';
        $data['status'] = 'Submitted';
        $data['agent_id'] = (int) $agent['id'];

        $applicationId = $this->applications->create($data);
        $this->applications->addStatusHistory(
            $applicationId,
            null,
            'Submitted',
            Auth::user()['id'] ?? null,
            'Submitted by marketing agent ' . $agent['first_name'] . ' ' . $agent['last_name'] . '.'
        );

        Audit::log('Create', 'Commissions', 'Application ' . $applicationNo . ' referred by agent #' . $agent['id']);
        Session::flash('success', 'Referral submitted as application ' . $applicationNo . '. Staff will review and process it.');
        $this->redirect('/my/referrals');
    }

    public function referralIndex(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/referral_index', [
            'title' => 'My Referrals',
            'agent' => $agent,
            'applications' => $this->applications->allForAgent((int) $agent['id']),
        ]);
    }

    public function commissions(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/commissions', [
            'title' => 'My Commissions',
            'agent' => $agent,
            'rows' => $this->commissions->allForAgent((int) $agent['id']),
        ]);
    }

    public function commissionShow(string $id): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $commission = $this->commissions->find((int) $id);
        if (!$commission || (int) $commission['agent_employee_id'] !== (int) $agent['id']) {
            Session::flash('error', 'Commission record not found.');
            $this->redirect('/my/commissions');
            return;
        }

        $this->view('my/agent/commission_show', [
            'title' => 'Commission Detail',
            'agent' => $agent,
            'commission' => $commission,
            'entries' => $this->commissionEntries->forCommission((int) $commission['id']),
        ]);
    }

    /**
     * @return array{0: array, 1: array} [canonical loan_applications data, field => error message]
     */
    private function validateReferral(array $post, array $agent): array
    {
        $errors = [];

        $firstName = trim($post['applicant_first_name'] ?? '');
        $lastName = trim($post['applicant_last_name'] ?? '');
        $idNumber = trim($post['applicant_id_number'] ?? '');
        $phone = trim($post['applicant_phone'] ?? '');
        // An agent tied to a branch always refers into that same branch --
        // no picker needed. Only an unassigned agent sees/uses one.
        $branchId = !empty($agent['branch_id']) ? (int) $agent['branch_id'] : (!empty($post['branch_id']) ? (int) $post['branch_id'] : null);
        $requestedAmount = (float) ($post['requested_amount'] ?? 0);

        if ($firstName === '') {
            $errors['applicant_first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['applicant_last_name'] = 'Last name is required.';
        }
        if ($idNumber === '') {
            $errors['applicant_id_number'] = 'ID number is required.';
        }
        if ($phone === '') {
            $errors['applicant_phone'] = 'Phone number is required.';
        }
        if (!$branchId) {
            $errors['branch_id'] = 'Select a branch.';
        }
        if ($requestedAmount <= 0) {
            $errors['requested_amount'] = 'Enter the amount the client wants to borrow.';
        }

        $data = [
            'branch_id' => $branchId,
            'applicant_first_name' => $firstName,
            'applicant_last_name' => $lastName,
            'applicant_id_number' => $idNumber,
            'applicant_phone' => $phone !== '' ? PhoneNumberNormalizer::toE164($phone) : '',
            'applicant_email' => trim($post['applicant_email'] ?? '') ?: null,
            'applicant_gender' => in_array($post['applicant_gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $post['applicant_gender'] : null,
            'applicant_address' => trim($post['applicant_address'] ?? '') ?: null,
            'employer_name' => trim($post['employer_name'] ?? '') ?: null,
            'gross_salary' => (float) ($post['gross_salary'] ?? 0),
            'net_salary' => (float) ($post['net_salary'] ?? 0),
            'requested_amount' => $requestedAmount,
            'requested_term_months' => (int) ($post['requested_term_months'] ?? 1),
            'requested_purpose' => trim($post['requested_purpose'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
