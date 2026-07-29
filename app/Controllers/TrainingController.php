<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\HrmEmployee;
use App\Models\Trainer;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\TrainingFeedback;
use App\Models\TrainingTask;
use App\Models\TrainingType;

class TrainingController extends Controller
{
    private const STATUSES = ['Scheduled', 'Ongoing', 'Completed', 'Cancelled'];

    private Training $trainings;
    private TrainingType $types;
    private Trainer $trainers;
    private HrmDepartment $departments;
    private Branch $branches;
    private HrmEmployee $employees;
    private TrainingEnrollment $enrollments;
    private TrainingTask $tasks;
    private TrainingFeedback $feedback;

    public function __construct()
    {
        $this->trainings = new Training();
        $this->types = new TrainingType();
        $this->trainers = new Trainer();
        $this->departments = new HrmDepartment();
        $this->branches = new Branch();
        $this->employees = new HrmEmployee();
        $this->enrollments = new TrainingEnrollment();
        $this->tasks = new TrainingTask();
        $this->feedback = new TrainingFeedback();
    }

    public function index(): void
    {
        Auth::authorize('training.view');
        $filters = [
            'status' => $_GET['status'] ?? null,
            'training_type_id' => $_GET['training_type_id'] ?? null,
            'department_id' => $_GET['department_id'] ?? null,
        ];
        $this->view('training/trainings/index', [
            'title' => 'Trainings',
            'trainings' => $this->trainings->allTrainings($filters),
            'types' => $this->types->allTypes(),
            'departments' => $this->departments->allDepartments(),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('training.manage');
        $this->view('training/trainings/create', [
            'title' => 'Schedule Training',
            'types' => $this->types->allTypes(),
            'trainers' => $this->trainers->activeTrainers(),
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'statuses' => self::STATUSES,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('training/trainings/create', [
                'title' => 'Schedule Training',
                'types' => $this->types->allTypes(),
                'trainers' => $this->trainers->activeTrainers(),
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'statuses' => self::STATUSES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->trainings->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Training', 'Scheduled training #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Training scheduled.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('training.view');
        $training = $this->trainings->find($id);
        if (!$training) {
            Session::flash('error', 'Training not found.');
            $this->redirect('/training/trainings');
            return;
        }

        $enrolledIds = array_column($this->enrollments->forTraining($id), 'employee_id');
        $this->view('training/trainings/show', [
            'title' => $training['title'],
            'training' => $training,
            'enrollments' => $this->enrollments->forTraining($id),
            'tasks' => $this->tasks->forTraining($id),
            'feedback' => $this->feedback->forTraining($id),
            'availableEmployees' => $this->employees->allEmployees(['status' => 'Active']),
            'enrolledIds' => $enrolledIds,
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('training.manage');
        $training = $this->trainings->find($id);
        if (!$training) {
            Session::flash('error', 'Training not found.');
            $this->redirect('/training/trainings');
            return;
        }
        $this->view('training/trainings/edit', [
            'title' => 'Edit Training',
            'training' => $training,
            'types' => $this->types->allTypes(),
            'trainers' => $this->trainers->activeTrainers(),
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'statuses' => self::STATUSES,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id . '/edit');
            return;
        }

        $training = $this->trainings->find($id);
        if (!$training) {
            Session::flash('error', 'Training not found.');
            $this->redirect('/training/trainings');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('training/trainings/edit', [
                'title' => 'Edit Training',
                'training' => array_merge($training, $_POST),
                'types' => $this->types->allTypes(),
                'trainers' => $this->trainers->activeTrainers(),
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'statuses' => self::STATUSES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->trainings->updateRecord($id, $data);

        Audit::log('Update', 'Training', 'Updated training #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Training updated.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings');
            return;
        }

        $this->trainings->delete($id);
        Audit::log('Delete', 'Training', 'Deleted training #' . $id);
        Session::flash('success', 'Training deleted.');
        $this->redirect('/training/trainings');
    }

    public function enroll(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $employeeIds = array_filter(array_map('intval', $_POST['employee_ids'] ?? []));
        $enrolled = 0;
        foreach ($employeeIds as $employeeId) {
            if (!$this->enrollments->exists($id, $employeeId)) {
                $this->enrollments->create([
                    'training_id' => $id,
                    'employee_id' => $employeeId,
                    'status' => 'Enrolled',
                    'created_by' => Auth::user()['id'] ?? null,
                ]);
                $enrolled++;
            }
        }

        Audit::log('Enroll', 'Training', 'Enrolled ' . $enrolled . ' employee(s) into training #' . $id);
        Session::flash('success', $enrolled . ' employee(s) enrolled.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function completeEnrollment(int $id, int $enrollmentId): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->enrollments->updateRecord($enrollmentId, [
            'status' => 'Completed',
            'completed_at' => date('Y-m-d'),
        ]);

        Session::flash('success', 'Enrollment marked as completed.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function deleteEnrollment(int $id, int $enrollmentId): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->enrollments->delete($enrollmentId);
        Session::flash('success', 'Employee unenrolled.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function addTask(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            Session::flash('error', 'Task title is required.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->tasks->create([
            'training_id' => $id,
            'title' => $title,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
            'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
            'status' => 'Pending',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Session::flash('success', 'Task added.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function completeTask(int $id, int $taskId): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->tasks->updateRecord($taskId, ['status' => 'Completed']);
        Session::flash('success', 'Task marked as completed.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function deleteTask(int $id, int $taskId): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->tasks->delete($taskId);
        Session::flash('success', 'Task deleted.');
        $this->redirect('/training/trainings/' . $id);
    }

    public function addFeedback(int $id, int $taskId): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $task = $this->tasks->find($taskId);
        if (!$task || empty($task['assigned_to'])) {
            Session::flash('error', 'This task has no assigned employee to leave feedback about.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            Session::flash('error', 'Rating must be between 1 and 5.');
            $this->redirect('/training/trainings/' . $id);
            return;
        }

        $this->feedback->create([
            'training_task_id' => $taskId,
            'employee_id' => $task['assigned_to'],
            'rating' => $rating,
            'comments' => trim($_POST['comments'] ?? '') ?: null,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Session::flash('success', 'Feedback recorded.');
        $this->redirect('/training/trainings/' . $id);
    }

    private function validate(array $post): array
    {
        $title = trim($post['title'] ?? '');
        $startDate = $post['start_date'] ?? '';
        $endDate = $post['end_date'] ?? '';
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        } elseif ($startDate !== '' && $endDate < $startDate) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        }

        $data = [
            'title' => $title,
            'description' => trim($post['description'] ?? '') ?: null,
            'training_type_id' => !empty($post['training_type_id']) ? (int) $post['training_type_id'] : null,
            'trainer_id' => !empty($post['trainer_id']) ? (int) $post['trainer_id'] : null,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'start_time' => trim($post['start_time'] ?? '') ?: null,
            'end_time' => trim($post['end_time'] ?? '') ?: null,
            'location' => trim($post['location'] ?? '') ?: null,
            'max_participants' => !empty($post['max_participants']) ? (int) $post['max_participants'] : null,
            'cost' => ($post['cost'] ?? '') !== '' ? (float) $post['cost'] : null,
            'status' => in_array($post['status'] ?? '', self::STATUSES, true) ? $post['status'] : 'Scheduled',
        ];

        return [$data, $errors];
    }
}
