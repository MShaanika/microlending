<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\PerformanceEmployeeGoal;
use App\Models\PerformanceGoalType;
use DateTime;

class PerformanceEmployeeGoalController extends Controller
{
    private const STATUSES = ['Not Started', 'In Progress', 'Completed', 'Overdue'];

    private PerformanceEmployeeGoal $goals;
    private PerformanceGoalType $goalTypes;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->goals = new PerformanceEmployeeGoal();
        $this->goalTypes = new PerformanceGoalType();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'goal_type_id' => $_GET['goal_type_id'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];

        $this->view('performance/employee-goals/index', [
            'title' => 'Employee Goals',
            'goals' => $this->goals->allGoals($filters),
            'employees' => $this->employees->allEmployees(),
            'goalTypes' => $this->goalTypes->allTypes(),
            'statuses' => self::STATUSES,
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $this->view('performance/employee-goals/create', [
            'title' => 'New Employee Goal',
            'employees' => $this->employees->allEmployees(),
            'goalTypes' => $this->goalTypes->activeTypes(),
            'statuses' => self::STATUSES,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-goals/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/employee-goals/create', [
                'title' => 'New Employee Goal',
                'employees' => $this->employees->allEmployees(),
                'goalTypes' => $this->goalTypes->activeTypes(),
                'statuses' => self::STATUSES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->goals->create($data);

        Audit::log('Create', 'Performance', 'Employee goal #' . $id . ' created - ' . $data['title']);
        Session::flash('success', 'Employee goal created.');
        $this->redirect('/performance/employee-goals');
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $goal = $this->goals->find($id);
        if (!$goal) {
            Session::flash('error', 'Employee goal not found.');
            $this->redirect('/performance/employee-goals');
            return;
        }
        $this->view('performance/employee-goals/edit', [
            'title' => 'Edit Employee Goal',
            'goal' => $goal,
            'employees' => $this->employees->allEmployees(),
            'goalTypes' => $this->goalTypes->activeTypes(),
            'statuses' => self::STATUSES,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-goals/' . $id . '/edit');
            return;
        }

        $goal = $this->goals->find($id);
        if (!$goal) {
            Session::flash('error', 'Employee goal not found.');
            $this->redirect('/performance/employee-goals');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/employee-goals/edit', [
                'title' => 'Edit Employee Goal',
                'goal' => array_merge($goal, $_POST),
                'employees' => $this->employees->allEmployees(),
                'goalTypes' => $this->goalTypes->activeTypes(),
                'statuses' => self::STATUSES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->goals->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated employee goal #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Employee goal updated.');
        $this->redirect('/performance/employee-goals');
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-goals');
            return;
        }

        $this->goals->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted employee goal #' . $id);
        Session::flash('success', 'Employee goal deleted.');
        $this->redirect('/performance/employee-goals');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $title = trim($post['title'] ?? '');
        $description = trim($post['description'] ?? '');
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '');
        $target = trim($post['target'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($description === '') {
            $errors['description'] = 'Description is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        } elseif ($startDate !== '' && new DateTime($endDate) < new DateTime($startDate)) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        }
        if ($target === '') {
            $errors['target'] = 'Target is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'goal_type_id' => !empty($post['goal_type_id']) ? (int) $post['goal_type_id'] : null,
            'title' => $title,
            'description' => $description,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'target' => $target,
            'progress' => isset($post['progress']) && $post['progress'] !== '' ? (float) $post['progress'] : 0,
            'status' => in_array($post['status'] ?? '', self::STATUSES, true) ? $post['status'] : 'Not Started',
        ];

        return [$data, $errors];
    }
}
