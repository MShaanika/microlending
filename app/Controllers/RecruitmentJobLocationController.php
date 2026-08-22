<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentJobLocation;

class RecruitmentJobLocationController extends Controller
{
    private RecruitmentJobLocation $locations;

    public function __construct()
    {
        $this->locations = new RecruitmentJobLocation();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->locations->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Job Locations',
            'locations' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/job-locations/index', $data);
            return;
        }
        $this->view('recruitment/job-locations/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $data = ['title' => 'Add Job Location', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/job-locations/create', $data);
            return;
        }
        $this->view('recruitment/job-locations/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-locations/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/job-locations/create', [
                'title' => 'Add Job Location',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->locations->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created job location #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Job location created.');
        }
        Session::flash('success', 'Job location created.');
        $this->redirect('/recruitment/job-locations');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $location = $this->locations->find($id);
        if (!$location) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Job location not found.'], 404);
            }
            Session::flash('error', 'Job location not found.');
            $this->redirect('/recruitment/job-locations');
            return;
        }
        $data = ['title' => 'Edit Job Location', 'location' => $location, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/job-locations/edit', $data);
            return;
        }
        $this->view('recruitment/job-locations/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-locations/' . $id . '/edit');
            return;
        }

        $location = $this->locations->find($id);
        if (!$location) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Job location not found.'], 404);
            }
            Session::flash('error', 'Job location not found.');
            $this->redirect('/recruitment/job-locations');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/job-locations/edit', [
                'title' => 'Edit Job Location',
                'location' => array_merge($location, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->locations->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated job location #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Job location updated.');
        }
        Session::flash('success', 'Job location updated.');
        $this->redirect('/recruitment/job-locations');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-locations');
            return;
        }

        if ($this->locations->inUseCount($id) > 0) {
            Session::flash('error', 'This location has job postings assigned to it and cannot be deleted.');
            $this->redirect('/recruitment/job-locations');
            return;
        }

        $this->locations->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted job location #' . $id);
        Session::flash('success', 'Job location deleted.');
        $this->redirect('/recruitment/job-locations');
    }

    private function validate(array $post): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $data = [
            'name' => $name,
            'remote_work' => !empty($post['remote_work']) ? 1 : 0,
            'address' => trim($post['address'] ?? '') ?: null,
            'city' => trim($post['city'] ?? '') ?: null,
            'region' => trim($post['region'] ?? '') ?: null,
            'country' => trim($post['country'] ?? '') ?: null,
            'postal_code' => trim($post['postal_code'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
