<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\PerformanceEmployeeReview;
use App\Models\PerformanceIndicator;
use App\Models\PerformanceReviewCycle;
use App\Models\User;

class PerformanceEmployeeReviewController extends Controller
{
    private const STATUSES = ['Pending', 'In Progress', 'Completed', 'Cancelled'];

    private PerformanceEmployeeReview $reviews;
    private HrmEmployee $employees;
    private User $users;
    private PerformanceReviewCycle $cycles;
    private PerformanceIndicator $indicators;

    public function __construct()
    {
        $this->reviews = new PerformanceEmployeeReview();
        $this->employees = new HrmEmployee();
        $this->users = new User();
        $this->cycles = new PerformanceReviewCycle();
        $this->indicators = new PerformanceIndicator();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'review_cycle_id' => $_GET['review_cycle_id'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'review_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->reviews->paginated($filters, $search, $sort, $dir, $page, $perPage);

        $this->view('performance/employee-reviews/index', [
            'title' => 'Employee Reviews',
            'reviews' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'employees' => $this->employees->allEmployees(),
            'cycles' => $this->cycles->allCycles(),
            'statuses' => self::STATUSES,
            'filters' => $filters,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $this->view('performance/employee-reviews/create', [
            'title' => 'Schedule Employee Review',
            'employees' => $this->employees->allEmployees(),
            'reviewers' => $this->users->allActive(),
            'cycles' => $this->cycles->activeCycles(),
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
            $this->redirect('/performance/employee-reviews/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/employee-reviews/create', [
                'title' => 'Schedule Employee Review',
                'employees' => $this->employees->allEmployees(),
                'reviewers' => $this->users->allActive(),
                'cycles' => $this->cycles->activeCycles(),
                'statuses' => self::STATUSES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->reviews->create($data);

        Audit::log('Create', 'Performance', 'Employee review #' . $id . ' scheduled');
        Session::flash('success', 'Employee review scheduled.');
        $this->redirect('/performance/employee-reviews/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('performance.view');
        $review = $this->reviews->find($id);
        if (!$review) {
            Session::flash('error', 'Employee review not found.');
            $this->redirect('/performance/employee-reviews');
            return;
        }
        $this->view('performance/employee-reviews/show', [
            'title' => 'Employee Review',
            'review' => $review,
            'ratings' => PerformanceEmployeeReview::ratingsMap($review),
            'averageRating' => PerformanceEmployeeReview::averageRating($review),
            'indicatorsByCategory' => $this->indicators->activeGroupedByCategory(),
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $review = $this->reviews->find($id);
        if (!$review) {
            Session::flash('error', 'Employee review not found.');
            $this->redirect('/performance/employee-reviews');
            return;
        }
        $this->view('performance/employee-reviews/edit', [
            'title' => 'Edit Employee Review',
            'review' => $review,
            'employees' => $this->employees->allEmployees(),
            'reviewers' => $this->users->allActive(),
            'cycles' => $this->cycles->activeCycles(),
            'statuses' => self::STATUSES,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-reviews/' . $id . '/edit');
            return;
        }

        $review = $this->reviews->find($id);
        if (!$review) {
            Session::flash('error', 'Employee review not found.');
            $this->redirect('/performance/employee-reviews');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/employee-reviews/edit', [
                'title' => 'Edit Employee Review',
                'review' => array_merge($review, $_POST),
                'employees' => $this->employees->allEmployees(),
                'reviewers' => $this->users->allActive(),
                'cycles' => $this->cycles->activeCycles(),
                'statuses' => self::STATUSES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->reviews->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated employee review #' . $id);
        Session::flash('success', 'Employee review updated.');
        $this->redirect('/performance/employee-reviews/' . $id);
    }

    public function conduct(int $id): void
    {
        Auth::authorize('performance.manage');
        $review = $this->reviews->find($id);
        if (!$review) {
            Session::flash('error', 'Employee review not found.');
            $this->redirect('/performance/employee-reviews');
            return;
        }
        $this->view('performance/employee-reviews/conduct', [
            'title' => 'Conduct Review',
            'review' => $review,
            'existingRatings' => PerformanceEmployeeReview::ratingsMap($review),
            'indicatorsByCategory' => $this->indicators->activeGroupedByCategory(),
        ]);
    }

    public function conductStore(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-reviews/' . $id . '/conduct');
            return;
        }

        $review = $this->reviews->find($id);
        if (!$review) {
            Session::flash('error', 'Employee review not found.');
            $this->redirect('/performance/employee-reviews');
            return;
        }

        $ratings = [];
        foreach (($_POST['ratings'] ?? []) as $indicatorId => $value) {
            $value = (int) $value;
            if ($value >= 1 && $value <= 5) {
                $ratings[(int) $indicatorId] = $value;
            }
        }

        $this->reviews->updateRecord($id, [
            'rating' => json_encode($ratings),
            'pros' => trim($_POST['pros'] ?? '') ?: null,
            'cons' => trim($_POST['cons'] ?? '') ?: null,
            'status' => 'Completed',
            'completion_date' => date('Y-m-d'),
        ]);

        Audit::log('Update', 'Performance', 'Conducted employee review #' . $id);
        Session::flash('success', 'Review submitted.');
        $this->redirect('/performance/employee-reviews/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/employee-reviews');
            return;
        }

        $this->reviews->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted employee review #' . $id);
        Session::flash('success', 'Employee review deleted.');
        $this->redirect('/performance/employee-reviews');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $reviewerId = !empty($post['reviewer_id']) ? (int) $post['reviewer_id'] : null;
        $reviewDate = trim($post['review_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if (!$reviewerId) {
            $errors['reviewer_id'] = 'Select a reviewer.';
        }
        if ($reviewDate === '') {
            $errors['review_date'] = 'Review date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'reviewer_id' => $reviewerId,
            'review_cycle_id' => !empty($post['review_cycle_id']) ? (int) $post['review_cycle_id'] : null,
            'review_date' => $reviewDate ?: null,
            'status' => in_array($post['status'] ?? '', self::STATUSES, true) ? $post['status'] : 'Pending',
        ];

        return [$data, $errors];
    }
}
