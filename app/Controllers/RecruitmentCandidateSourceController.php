<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentCandidateSource;

class RecruitmentCandidateSourceController extends Controller
{
    private RecruitmentCandidateSource $sources;

    public function __construct()
    {
        $this->sources = new RecruitmentCandidateSource();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->sources->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Candidate Sources',
            'sources' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/candidate-sources/index', $data);
            return;
        }
        $this->view('recruitment/candidate-sources/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $data = ['title' => 'Add Candidate Source', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/candidate-sources/create', $data);
            return;
        }
        $this->view('recruitment/candidate-sources/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-sources/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/candidate-sources/create', [
                'title' => 'Add Candidate Source',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->sources->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created candidate source #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Candidate source created.');
        }
        Session::flash('success', 'Candidate source created.');
        $this->redirect('/recruitment/candidate-sources');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $source = $this->sources->find($id);
        if (!$source) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Candidate source not found.'], 404);
            }
            Session::flash('error', 'Candidate source not found.');
            $this->redirect('/recruitment/candidate-sources');
            return;
        }
        $data = ['title' => 'Edit Candidate Source', 'source' => $source, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/candidate-sources/edit', $data);
            return;
        }
        $this->view('recruitment/candidate-sources/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-sources/' . $id . '/edit');
            return;
        }

        $source = $this->sources->find($id);
        if (!$source) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Candidate source not found.'], 404);
            }
            Session::flash('error', 'Candidate source not found.');
            $this->redirect('/recruitment/candidate-sources');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/candidate-sources/edit', [
                'title' => 'Edit Candidate Source',
                'source' => array_merge($source, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->sources->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated candidate source #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Candidate source updated.');
        }
        Session::flash('success', 'Candidate source updated.');
        $this->redirect('/recruitment/candidate-sources');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidate-sources');
            return;
        }

        if ($this->sources->inUseCount($id) > 0) {
            Session::flash('error', 'This source has candidates assigned to it and cannot be deleted.');
            $this->redirect('/recruitment/candidate-sources');
            return;
        }

        $this->sources->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted candidate source #' . $id);
        Session::flash('success', 'Candidate source deleted.');
        $this->redirect('/recruitment/candidate-sources');
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
            'description' => trim($post['description'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
