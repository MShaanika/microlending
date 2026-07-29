<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOnboarding;
use App\Models\RecruitmentOnboardingChecklist;

class RecruitmentCandidateOnboardingController extends Controller
{
    private const STATUSES = ['Pending', 'In Progress', 'Completed'];

    private RecruitmentCandidateOnboarding $onboardings;
    private RecruitmentCandidate $candidates;
    private RecruitmentOnboardingChecklist $checklists;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->onboardings = new RecruitmentCandidateOnboarding();
        $this->candidates = new RecruitmentCandidate();
        $this->checklists = new RecruitmentOnboardingChecklist();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $this->view('recruitment/candidate-onboardings/index', [
            'title' => 'Candidate Onboarding',
            'onboardings' => $this->onboardings->allOnboardings(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/candidate-onboardings/create', [
            'title' => 'Start Onboarding',
            'candidates' => $this->candidates->hiredWithoutOnboarding(),
            'checklists' => $this->checklists->activeChecklists(),
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-onboardings/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/candidate-onboardings/create', [
                'title' => 'Start Onboarding',
                'candidates' => $this->candidates->hiredWithoutOnboarding(),
                'checklists' => $this->checklists->activeChecklists(),
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->onboardings->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Started onboarding #' . $id);
        Session::flash('success', 'Onboarding started.');
        $this->redirect('/recruitment/candidate-onboardings');
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }

        $this->onboardings->updateRecord($id, ['status' => $status]);
        Audit::log('Update', 'Recruitment', 'Updated onboarding #' . $id . ' status to ' . $status);
        Session::flash('success', 'Onboarding status updated.');
        $this->redirect('/recruitment/candidate-onboardings');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }

        $this->onboardings->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted onboarding #' . $id);
        Session::flash('success', 'Onboarding deleted.');
        $this->redirect('/recruitment/candidate-onboardings');
    }

    private function validate(array $post): array
    {
        $candidateId = !empty($post['candidate_id']) ? (int) $post['candidate_id'] : null;
        $startDate = $post['start_date'] ?? '';
        $errors = [];

        if (!$candidateId) {
            $errors['candidate_id'] = 'Candidate is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        $data = [
            'candidate_id' => $candidateId,
            'checklist_id' => !empty($post['checklist_id']) ? (int) $post['checklist_id'] : null,
            'start_date' => $startDate ?: null,
            'buddy_employee_id' => !empty($post['buddy_employee_id']) ? (int) $post['buddy_employee_id'] : null,
            'status' => 'Pending',
        ];

        return [$data, $errors];
    }
}
