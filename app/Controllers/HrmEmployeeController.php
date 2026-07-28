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
use App\Models\User;

class HrmEmployeeController extends Controller
{
    private HrmEmployee $employees;
    private Branch $branches;
    private HrmDepartment $departments;
    private HrmDesignation $designations;
    private HrmShift $shifts;
    private User $users;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->branches = new Branch();
        $this->departments = new HrmDepartment();
        $this->designations = new HrmDesignation();
        $this->shifts = new HrmShift();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'branch_id' => $_GET['branch_id'] ?? '',
            'department_id' => $_GET['department_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim($_GET['search'] ?? ''),
        ];

        $this->view('hrm/employees/index', [
            'title' => 'Employees',
            'employees' => $this->employees->allEmployees($filters),
            'counts' => $this->employees->counts(),
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/employees/create', array_merge($this->formData(), [
            'title' => 'Add Employee',
            'old' => [],
            'errors' => [],
        ]));
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/employees/create', array_merge($this->formData(), [
                'title' => 'Add Employee',
                'old' => $_POST,
                'errors' => $errors,
            ]));
            return;
        }

        $data['employee_no'] = generate_reference('EMP');
        while ($this->employees->employeeNoExists($data['employee_no'])) {
            $data['employee_no'] = generate_reference('EMP');
        }
        $data['created_by'] = Auth::user()['id'] ?? null;

        $id = $this->employees->create($data);

        Audit::log('Create', 'HRM', 'Created employee #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Employee added.');
        $this->redirect('/hrm/employees/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }
        $this->view('hrm/employees/show', [
            'title' => $employee['first_name'] . ' ' . $employee['last_name'],
            'employee' => $employee,
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }
        $this->view('hrm/employees/edit', array_merge($this->formData(), [
            'title' => 'Edit Employee',
            'employee' => $employee,
            'errors' => [],
        ]));
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/' . $id . '/edit');
            return;
        }

        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/employees/edit', array_merge($this->formData(), [
                'title' => 'Edit Employee',
                'employee' => array_merge($employee, $_POST),
                'errors' => $errors,
            ]));
            return;
        }

        $this->employees->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated employee #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Employee updated.');
        $this->redirect('/hrm/employees/' . $id);
    }

    private function formData(): array
    {
        return [
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'designations' => $this->designations->allDesignations(true),
            'shifts' => $this->shifts->allShifts(true),
            'availableUsers' => $this->users->paginated('', 'active'),
        ];
    }

    private function validate(array $post, ?int $excludeId = null): array
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
        if ($userId !== null && $this->employees->userIdInUse($userId, $excludeId)) {
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
            'status' => in_array($post['status'] ?? '', ['Active', 'On Leave', 'Suspended', 'Terminated'], true)
                ? $post['status'] : 'Active',
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
            'basic_salary' => $post['basic_salary'] !== '' && isset($post['basic_salary']) ? (float) $post['basic_salary'] : null,
            'hours_per_day' => $post['hours_per_day'] !== '' && isset($post['hours_per_day']) ? (float) $post['hours_per_day'] : 8,
            'days_per_week' => $post['days_per_week'] !== '' && isset($post['days_per_week']) ? (float) $post['days_per_week'] : 5,
            'user_id' => $userId,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'designation_id' => !empty($post['designation_id']) ? (int) $post['designation_id'] : null,
            'shift_id' => !empty($post['shift_id']) ? (int) $post['shift_id'] : null,
        ];

        return [$data, $errors];
    }
}
