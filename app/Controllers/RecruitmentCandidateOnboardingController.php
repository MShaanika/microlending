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
use App\Models\RecruitmentChecklistItem;
use App\Models\RecruitmentOnboardingChecklist;

class RecruitmentCandidateOnboardingController extends Controller
{
    private const STATUSES = ['Pending', 'In Progress', 'Completed'];

    private RecruitmentCandidateOnboarding $onboardings;
    private RecruitmentCandidate $candidates;
    private RecruitmentOnboardingChecklist $checklists;
    private RecruitmentChecklistItem $checklistItems;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->onboardings = new RecruitmentCandidateOnboarding();
        $this->candidates = new RecruitmentCandidate();
        $this->checklists = new RecruitmentOnboardingChecklist();
        $this->checklistItems = new RecruitmentChecklistItem();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'start_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->onboardings->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/candidate-onboardings/index', [
            'title' => 'Candidate Onboarding',
            'onboardings' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $onboarding = $this->onboardings->find($id);
        if (!$onboarding) {
            Session::flash('error', 'Onboarding record not found.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }
        $this->view('recruitment/candidate-onboardings/show', [
            'title' => 'Onboarding: ' . $onboarding['candidate_name'],
            'onboarding' => $onboarding,
            'checklistItems' => $onboarding['checklist_id'] ? $this->checklistItems->forChecklist((int) $onboarding['checklist_id']) : [],
            'statuses' => self::STATUSES,
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $onboarding = $this->onboardings->find($id);
        if (!$onboarding) {
            Session::flash('error', 'Onboarding record not found.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }
        $this->view('recruitment/candidate-onboardings/edit', [
            'title' => 'Edit Onboarding',
            'onboarding' => $onboarding,
            'checklists' => $this->checklists->activeChecklists(),
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'old' => $onboarding,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-onboardings/' . $id . '/edit');
            return;
        }

        $onboarding = $this->onboardings->find($id);
        if (!$onboarding) {
            Session::flash('error', 'Onboarding record not found.');
            $this->redirect('/recruitment/candidate-onboardings');
            return;
        }

        $startDate = $_POST['start_date'] ?? '';
        $errors = [];
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        if (!empty($errors)) {
            $this->view('recruitment/candidate-onboardings/edit', [
                'title' => 'Edit Onboarding',
                'onboarding' => $onboarding,
                'checklists' => $this->checklists->activeChecklists(),
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $this->onboardings->updateRecord($id, [
            'checklist_id' => !empty($_POST['checklist_id']) ? (int) $_POST['checklist_id'] : null,
            'start_date' => $startDate,
            'buddy_employee_id' => !empty($_POST['buddy_employee_id']) ? (int) $_POST['buddy_employee_id'] : null,
        ]);

        Audit::log('Update', 'Recruitment', 'Updated onboarding #' . $id);
        Session::flash('success', 'Onboarding updated.');
        $this->redirect('/recruitment/candidate-onboardings/' . $id);
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

        // Posted from both the list's inline dropdown and the detail page --
        // return wherever the request came from rather than always the list.
        $backTo = str_contains($_SERVER['HTTP_REFERER'] ?? '', '/candidate-onboardings/' . $id)
            ? '/recruitment/candidate-onboardings/' . $id
            : '/recruitment/candidate-onboardings';

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($backTo);
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect($backTo);
            return;
        }

        $this->onboardings->updateRecord($id, ['status' => $status]);
        Audit::log('Update', 'Recruitment', 'Updated onboarding #' . $id . ' status to ' . $status);
        Session::flash('success', 'Onboarding status updated.');
        $this->redirect($backTo);
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
