<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PerformanceEmployeeReview;
use App\Models\PerformanceReviewCycle;

class PerformanceReviewCycleController extends Controller
{
    private const FREQUENCIES = ['Monthly', 'Quarterly', 'Semi-Annual', 'Annual'];

    private PerformanceReviewCycle $cycles;

    public function __construct()
    {
        $this->cycles = new PerformanceReviewCycle();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->cycles->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Review Cycles',
            'cycles' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('performance/review-cycles/index', $data);
            return;
        }
        $this->view('performance/review-cycles/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $data = ['title' => 'Add Review Cycle', 'frequencies' => self::FREQUENCIES, 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('performance/review-cycles/create', $data);
            return;
        }
        $this->view('performance/review-cycles/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/review-cycles/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('performance/review-cycles/create', [
                'title' => 'Add Review Cycle',
                'frequencies' => self::FREQUENCIES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->cycles->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Performance', 'Created review cycle #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Review cycle created.');
        }
        Session::flash('success', 'Review cycle created.');
        $this->redirect('/performance/review-cycles');
    }

    public function show(int $id): void
    {
        Auth::authorize('performance.view');
        $cycle = $this->cycles->find($id);
        if (!$cycle) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Review cycle not found.'], 404);
            }
            Session::flash('error', 'Review cycle not found.');
            $this->redirect('/performance/review-cycles');
            return;
        }
        $data = [
            'title' => $cycle['name'],
            'cycle' => $cycle,
            'reviews' => (new PerformanceEmployeeReview())->allReviews(['review_cycle_id' => $id]),
        ];

        if ($this->isAjax()) {
            $this->fragment('performance/review-cycles/show', $data);
            return;
        }
        $this->view('performance/review-cycles/show', $data);
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $cycle = $this->cycles->find($id);
        if (!$cycle) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Review cycle not found.'], 404);
            }
            Session::flash('error', 'Review cycle not found.');
            $this->redirect('/performance/review-cycles');
            return;
        }
        $data = ['title' => 'Edit Review Cycle', 'cycle' => $cycle, 'frequencies' => self::FREQUENCIES, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('performance/review-cycles/edit', $data);
            return;
        }
        $this->view('performance/review-cycles/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/review-cycles/' . $id . '/edit');
            return;
        }

        $cycle = $this->cycles->find($id);
        if (!$cycle) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Review cycle not found.'], 404);
            }
            Session::flash('error', 'Review cycle not found.');
            $this->redirect('/performance/review-cycles');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('performance/review-cycles/edit', [
                'title' => 'Edit Review Cycle',
                'cycle' => array_merge($cycle, $_POST),
                'frequencies' => self::FREQUENCIES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->cycles->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated review cycle #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Review cycle updated.');
        }
        Session::flash('success', 'Review cycle updated.');
        $this->redirect('/performance/review-cycles');
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/review-cycles');
            return;
        }

        if ($this->cycles->inUseCount($id) > 0) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This review cycle has employee reviews attached and cannot be deleted.'], 422);
            }
            Session::flash('error', 'This review cycle has employee reviews attached and cannot be deleted.');
            $this->redirect('/performance/review-cycles');
            return;
        }

        $this->cycles->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted review cycle #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Review cycle deleted.');
        }
        Session::flash('success', 'Review cycle deleted.');
        $this->redirect('/performance/review-cycles');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->cycles->nameExists($name, $excludeId)) {
            $errors['name'] = 'A review cycle with this name already exists.';
        }

        $data = [
            'name' => $name,
            'frequency' => in_array($post['frequency'] ?? '', self::FREQUENCIES, true) ? $post['frequency'] : 'Annual',
            'description' => trim($post['description'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
