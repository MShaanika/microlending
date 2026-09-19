<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PayCyclePolicy;

/**
 * Employer-level pay-cycle policies ("Government Payroll": pay day 20,
 * roll to previous business day) plus the employer_name -> policy
 * mapping, so an admin assigns a policy to an employer ONCE rather than
 * to every borrower under that employer individually.
 */
class PayCyclePolicyController extends Controller
{
    private PayCyclePolicy $policies;

    public function __construct()
    {
        $this->policies = new PayCyclePolicy();
    }

    public function index(): void
    {
        Auth::authorize('pay_cycle_policies.manage');
        $this->view('pay_cycle_policies/index', [
            'title' => 'Pay-Cycle Policies',
            'policies' => $this->policies->allPolicies(),
            'employerMappings' => $this->policies->employerMappings(),
            'unmappedEmployerNames' => $this->unmappedEmployerNames(),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('pay_cycle_policies.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $name = trim($_POST['policy_name'] ?? '');
        $payDay = (int) ($_POST['normal_pay_day'] ?? 0);
        $rule = $_POST['non_business_day_rule'] ?? 'NONE';

        if ($name === '' || $payDay < 1 || $payDay > 31 || !in_array($rule, ['PREVIOUS_BUSINESS_DAY', 'NEXT_BUSINESS_DAY', 'NONE'], true)) {
            Session::flash('error', 'Enter a policy name, a pay day between 1 and 31, and a valid rule.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $id = $this->policies->create([
            'policy_name' => $name,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'normal_pay_day' => $payDay,
            'non_business_day_rule' => $rule,
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Collections', 'Created pay-cycle policy #' . $id . ' - ' . $name);
        Session::flash('success', 'Pay-cycle policy created.');
        $this->redirect('/pay-cycle-policies');
    }

    public function update(string $id): void
    {
        Auth::authorize('pay_cycle_policies.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $payDay = (int) ($_POST['normal_pay_day'] ?? 0);
        $rule = $_POST['non_business_day_rule'] ?? 'NONE';
        if ($payDay < 1 || $payDay > 31 || !in_array($rule, ['PREVIOUS_BUSINESS_DAY', 'NEXT_BUSINESS_DAY', 'NONE'], true)) {
            Session::flash('error', 'Enter a pay day between 1 and 31 and a valid rule.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $this->policies->updateRecord((int) $id, [
            'normal_pay_day' => $payDay,
            'non_business_day_rule' => $rule,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Collections', 'Updated pay-cycle policy #' . $id);
        Session::flash('success', 'Policy updated.');
        $this->redirect('/pay-cycle-policies');
    }

    public function mapEmployer(): void
    {
        Auth::authorize('pay_cycle_policies.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $employerName = trim($_POST['employer_name'] ?? '');
        $policyId = (int) ($_POST['pay_cycle_policy_id'] ?? 0);
        if ($employerName === '' || $policyId < 1) {
            Session::flash('error', 'Select an employer and a policy.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        try {
            $id = $this->policies->mapEmployer($employerName, $policyId, Auth::user()['id'] ?? null);
        } catch (\PDOException $e) {
            Session::flash('error', 'This employer already has a mapping -- deactivate it first if you want to change it.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        Audit::log('Create', 'Collections', 'Mapped employer "' . $employerName . '" to pay-cycle policy #' . $policyId);
        Session::flash('success', 'Every borrower currently captured under "' . $employerName . '" now inherits this policy automatically.');
        $this->redirect('/pay-cycle-policies');
    }

    public function toggleEmployerMapping(string $id): void
    {
        Auth::authorize('pay_cycle_policies.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/pay-cycle-policies');
            return;
        }

        $active = ($_POST['active'] ?? '1') === '1';
        $this->policies->setMappingActive((int) $id, !$active);
        Audit::log('Update', 'Collections', ($active ? 'Deactivated' : 'Activated') . ' employer mapping #' . $id);
        Session::flash('success', 'Employer mapping updated.');
        $this->redirect('/pay-cycle-policies');
    }

    /** Employer names already in use that have no policy mapping yet -- surfaced so an admin sees exactly who still needs mapping instead of guessing. */
    private function unmappedEmployerNames(): array
    {
        $inUse = $this->policies->distinctEmployerNamesInUse();
        $mapped = array_column($this->policies->employerMappings(), 'employer_name');
        return array_values(array_diff($inUse, $mapped));
    }
}
