<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\HrmDesignation;
use App\Models\HrmEmployee;
use App\Models\HrmShift;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOfferLetterTemplate;
use App\Models\User;

class RecruitmentOfferController extends Controller
{
    private const STATUSES = ['Draft', 'Sent', 'Accepted', 'Negotiating', 'Declined', 'Expired'];

    private RecruitmentOffer $offers;
    private RecruitmentCandidate $candidates;
    private RecruitmentOfferLetterTemplate $templates;
    private HrmDepartment $departments;
    private HrmDesignation $designations;
    private HrmShift $shifts;
    private Branch $branches;
    private HrmEmployee $employees;
    private User $users;

    public function __construct()
    {
        $this->offers = new RecruitmentOffer();
        $this->candidates = new RecruitmentCandidate();
        $this->templates = new RecruitmentOfferLetterTemplate();
        $this->departments = new HrmDepartment();
        $this->designations = new HrmDesignation();
        $this->shifts = new HrmShift();
        $this->branches = new Branch();
        $this->employees = new HrmEmployee();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'offer_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->offers->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Offers',
            'offers' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/offers/index', $data);
            return;
        }
        $this->view('recruitment/offers/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $data = [
            'title' => 'Create Offer',
            'candidates' => $this->candidates->offerEligible(),
            'departments' => $this->departments->allDepartments(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/offers/create', $data);
            return;
        }
        $this->view('recruitment/offers/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/offers/create', [
                'title' => 'Create Offer',
                'candidates' => $this->candidates->offerEligible(),
                'departments' => $this->departments->allDepartments(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->offers->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        // Advance the candidate to Offer stage if they weren't already there.
        $candidate = $this->candidates->find($data['candidate_id']);
        if ($candidate && !in_array($candidate['status'], ['Offer', 'Hired'], true)) {
            $this->candidates->updateStatus($data['candidate_id'], 'Offer');
        }

        Audit::log('Create', 'Recruitment', 'Created offer #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Offer created.');
        }
        Session::flash('success', 'Offer created.');
        $this->redirect('/recruitment/offers/' . $id);
    }

    /** Candidate is fixed once an offer exists -- edit covers terms (salary, dates, benefits), not who it's for. Blocked once converted to an employee, since the offer is now historical record. */
    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $offer = $this->offers->find($id);
        if (!$offer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Offer not found.'], 404);
            }
            Session::flash('error', 'Offer not found.');
            $this->redirect('/recruitment/offers');
            return;
        }
        if ($offer['converted_to_employee']) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This offer has already been converted to an employee and can no longer be edited.'], 422);
            }
            Session::flash('error', 'This offer has already been converted to an employee and can no longer be edited.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }
        $data = [
            'title' => 'Edit Offer',
            'offer' => $offer,
            'departments' => $this->departments->allDepartments(),
            'old' => $offer,
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/offers/edit', $data);
            return;
        }
        $this->view('recruitment/offers/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers/' . $id . '/edit');
            return;
        }

        $offer = $this->offers->find($id);
        if (!$offer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Offer not found.'], 404);
            }
            Session::flash('error', 'Offer not found.');
            $this->redirect('/recruitment/offers');
            return;
        }
        if ($offer['converted_to_employee']) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This offer has already been converted to an employee and can no longer be edited.'], 422);
            }
            Session::flash('error', 'This offer has already been converted to an employee and can no longer be edited.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $salary = ($_POST['salary'] ?? '') !== '' ? (float) $_POST['salary'] : null;
        $startDate = $_POST['start_date'] ?? '';
        $errors = [];
        if ($salary === null) {
            $errors['salary'] = 'Salary is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        $data = [
            'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
            'offer_date' => $_POST['offer_date'] ?: date('Y-m-d'),
            'position' => trim($_POST['position'] ?? '') ?: $offer['position'],
            'salary' => $salary ?? 0,
            'bonus' => ($_POST['bonus'] ?? '') !== '' ? (float) $_POST['bonus'] : null,
            'equity' => trim($_POST['equity'] ?? '') ?: null,
            'benefits' => trim($_POST['benefits'] ?? '') ?: null,
            'start_date' => $startDate ?: null,
            'expiration_date' => !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
        ];

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/offers/edit', [
                'title' => 'Edit Offer',
                'offer' => $offer,
                'departments' => $this->departments->allDepartments(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $this->offers->updateRecord($id, $data);
        Audit::log('Update', 'Recruitment', 'Updated offer #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Offer updated.');
        }
        Session::flash('success', 'Offer updated.');
        $this->redirect('/recruitment/offers/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $offer = $this->offers->find($id);
        if (!$offer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Offer not found.'], 404);
            }
            Session::flash('error', 'Offer not found.');
            $this->redirect('/recruitment/offers');
            return;
        }
        $data = [
            'title' => 'Offer: ' . $offer['candidate_name'],
            'offer' => $offer,
            'templates' => $this->templates->activeTemplates(),
            'statuses' => self::STATUSES,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/offers/show', $data);
            return;
        }
        $this->view('recruitment/offers/show', $data);
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['status' => 'Invalid status.']);
            }
            Session::flash('error', 'Invalid status.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $update = ['status' => $status];
        if (in_array($status, ['Accepted', 'Declined'], true)) {
            $update['response_date'] = date('Y-m-d');
        }
        if ($status === 'Declined') {
            $update['decline_reason'] = trim($_POST['decline_reason'] ?? '') ?: null;
        }

        $this->offers->updateRecord($id, $update);
        Audit::log('Update', 'Recruitment', 'Updated offer #' . $id . ' status to ' . $status);

        if ($this->isAjax()) {
            $this->jsonSuccess('Offer status updated.', '/recruitment/offers/' . $id);
        }
        Session::flash('success', 'Offer status updated.');
        $this->redirect('/recruitment/offers/' . $id);
    }

    public function updateApprovalStatus(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $approval = $_POST['approval_status'] ?? '';
        if (!in_array($approval, ['Approved', 'Rejected'], true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['approval_status' => 'Invalid approval decision.']);
            }
            Session::flash('error', 'Invalid approval decision.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $this->offers->updateRecord($id, [
            'approval_status' => $approval,
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Recruitment', 'Offer #' . $id . ' approval ' . strtolower($approval));

        if ($this->isAjax()) {
            $this->jsonSuccess('Offer ' . strtolower($approval) . '.', '/recruitment/offers/' . $id);
        }
        Session::flash('success', 'Offer ' . strtolower($approval) . '.');
        $this->redirect('/recruitment/offers/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers');
            return;
        }

        $this->offers->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted offer #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Offer deleted.');
        }
        Session::flash('success', 'Offer deleted.');
        $this->redirect('/recruitment/offers');
    }

    public function convertToEmployee(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $offer = $this->offers->find($id);
        if (!$offer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Offer not found.'], 404);
            }
            Session::flash('error', 'Offer not found.');
            $this->redirect('/recruitment/offers');
            return;
        }
        if ($offer['converted_to_employee']) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This offer has already been converted to an employee.'], 422);
            }
            Session::flash('error', 'This offer has already been converted to an employee.');
            $this->redirect('/recruitment/offers/' . $id);
            return;
        }

        $candidate = $this->candidates->find((int) $offer['candidate_id']);
        $prefill = [
            'first_name' => $candidate['first_name'] ?? '',
            'last_name' => $candidate['last_name'] ?? '',
            'email' => $candidate['email'] ?? '',
            'phone' => $candidate['phone'] ?? '',
            'gender' => $candidate['gender'] ?? 'Male',
            'city' => $candidate['city'] ?? '',
            'region' => $candidate['region'] ?? '',
            'country' => $candidate['country'] ?? 'Namibia',
            'department_id' => $offer['department_id'] ?? '',
            'date_of_joining' => $offer['start_date'] ?? '',
            'basic_salary' => $offer['salary'] ?? '',
        ];

        $data = [
            'title' => 'Convert Offer to Employee',
            'offer' => $offer,
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'designations' => $this->designations->allDesignations(true),
            'shifts' => $this->shifts->allShifts(true),
            'availableUsers' => $this->users->paginated('', 'active'),
            'old' => $prefill,
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/offers/convert', $data);
            return;
        }
        $this->view('recruitment/offers/convert', $data);
    }

    public function convertToEmployeeStore(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offers/' . $id . '/convert');
            return;
        }

        $offer = $this->offers->find($id);
        if (!$offer || $offer['converted_to_employee']) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This offer cannot be converted.'], 422);
            }
            Session::flash('error', 'This offer cannot be converted.');
            $this->redirect('/recruitment/offers');
            return;
        }

        [$data, $errors] = $this->validateEmployee($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/offers/convert', [
                'title' => 'Convert Offer to Employee',
                'offer' => $offer,
                'branches' => $this->branches->all(),
                'departments' => $this->departments->allDepartments(true),
                'designations' => $this->designations->allDesignations(true),
                'shifts' => $this->shifts->allShifts(true),
                'availableUsers' => $this->users->paginated('', 'active'),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['employee_no'] = generate_reference('EMP');
        while ($this->employees->employeeNoExists($data['employee_no'])) {
            $data['employee_no'] = generate_reference('EMP');
        }
        $data['created_by'] = Auth::user()['id'] ?? null;

        $employeeId = $this->employees->create($data);

        $this->offers->updateRecord($id, [
            'converted_to_employee' => 1,
            'employee_id' => $employeeId,
        ]);

        $candidate = $this->candidates->find((int) $offer['candidate_id']);
        if ($candidate && $candidate['status'] === 'Offer') {
            $this->candidates->updateStatus((int) $offer['candidate_id'], 'Hired');
        }

        Audit::log('Convert', 'Recruitment', 'Converted offer #' . $id . ' to employee #' . $employeeId);

        if ($this->isAjax()) {
            $this->jsonSuccess('Candidate converted to employee.', null, ['redirect' => url('/hrm/employees/' . $employeeId)]);
        }
        Session::flash('success', 'Candidate converted to employee.');
        $this->redirect('/hrm/employees/' . $employeeId);
    }

    private function validate(array $post): array
    {
        $candidateId = !empty($post['candidate_id']) ? (int) $post['candidate_id'] : null;
        $salary = ($post['salary'] ?? '') !== '' ? (float) $post['salary'] : null;
        $startDate = $post['start_date'] ?? '';
        $offerDate = $post['offer_date'] ?? '';
        $errors = [];

        if (!$candidateId) {
            $errors['candidate_id'] = 'Candidate is required.';
        }
        if ($salary === null) {
            $errors['salary'] = 'Salary is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        $candidate = $candidateId ? $this->candidates->find($candidateId) : null;
        if ($candidateId && !$candidate) {
            $errors['candidate_id'] = 'Candidate not found.';
        }

        $data = [
            'candidate_id' => $candidateId,
            'job_id' => $candidate ? (int) $candidate['job_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'offer_date' => $offerDate ?: date('Y-m-d'),
            'position' => trim($post['position'] ?? '') ?: ($candidate['job_title'] ?? ''),
            'salary' => $salary ?? 0,
            'bonus' => ($post['bonus'] ?? '') !== '' ? (float) $post['bonus'] : null,
            'equity' => trim($post['equity'] ?? '') ?: null,
            'benefits' => trim($post['benefits'] ?? '') ?: null,
            'start_date' => $startDate ?: null,
            'expiration_date' => !empty($post['expiration_date']) ? $post['expiration_date'] : null,
        ];

        return [$data, $errors];
    }

    private function validateEmployee(array $post): array
    {
        $errors = [];
        $firstName = trim($post['first_name'] ?? '');
        $lastName = trim($post['last_name'] ?? '');
        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        $email = trim($post['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $userId = !empty($post['user_id']) ? (int) $post['user_id'] : null;
        if ($userId !== null && $this->employees->userIdInUse($userId, null)) {
            $errors['user_id'] = 'That system user is already linked to another employee.';
        }

        $data = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email ?: null,
            'phone' => trim($post['phone'] ?? '') ?: null,
            'date_of_birth' => trim($post['date_of_birth'] ?? '') ?: null,
            'gender' => in_array($post['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $post['gender'] : 'Male',
            'date_of_joining' => trim($post['date_of_joining'] ?? '') ?: null,
            'employment_type' => in_array($post['employment_type'] ?? '', ['Full-Time', 'Part-Time', 'Contract', 'Intern'], true)
                ? $post['employment_type'] : 'Full-Time',
            'status' => 'Active',
            'address_line_1' => trim($post['address_line_1'] ?? '') ?: null,
            'address_line_2' => trim($post['address_line_2'] ?? '') ?: null,
            'city' => trim($post['city'] ?? '') ?: null,
            'region' => trim($post['region'] ?? '') ?: null,
            'country' => trim($post['country'] ?? '') ?: 'Namibia',
            'postal_code' => trim($post['postal_code'] ?? '') ?: null,
            'emergency_contact_name' => trim($post['emergency_contact_name'] ?? '') ?: null,
            'emergency_contact_relationship' => trim($post['emergency_contact_relationship'] ?? '') ?: null,
            'emergency_contact_number' => trim($post['emergency_contact_number'] ?? '') ?: null,
            'bank_name' => trim($post['bank_name'] ?? '') ?: null,
            'account_holder_name' => trim($post['account_holder_name'] ?? '') ?: null,
            'account_number' => trim($post['account_number'] ?? '') ?: null,
            'branch_code' => trim($post['branch_code'] ?? '') ?: null,
            'tax_payer_id' => trim($post['tax_payer_id'] ?? '') ?: null,
            'basic_salary' => ($post['basic_salary'] ?? '') !== '' ? (float) $post['basic_salary'] : null,
            'rate_per_hour' => ($post['rate_per_hour'] ?? '') !== '' ? (float) $post['rate_per_hour'] : null,
            'hours_per_day' => ($post['hours_per_day'] ?? '') !== '' ? (float) $post['hours_per_day'] : 8,
            'days_per_week' => ($post['days_per_week'] ?? '') !== '' ? (float) $post['days_per_week'] : 5,
            'user_id' => $userId,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'designation_id' => !empty($post['designation_id']) ? (int) $post['designation_id'] : null,
            'shift_id' => !empty($post['shift_id']) ? (int) $post['shift_id'] : null,
        ];

        return [$data, $errors];
    }
}
