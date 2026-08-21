<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentInterviewType;

class RecruitmentInterviewTypeController extends Controller
{
    private RecruitmentInterviewType $types;

    public function __construct()
    {
        $this->types = new RecruitmentInterviewType();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->types->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/interview-types/index', [
            'title' => 'Interview Types',
            'types' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/interview-types/create', [
            'title' => 'Add Interview Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interview-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/interview-types/create', [
                'title' => 'Add Interview Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created interview type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Interview type created.');
        $this->redirect('/recruitment/interview-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Interview type not found.');
            $this->redirect('/recruitment/interview-types');
            return;
        }
        $this->view('recruitment/interview-types/edit', [
            'title' => 'Edit Interview Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interview-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Interview type not found.');
            $this->redirect('/recruitment/interview-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/interview-types/edit', [
                'title' => 'Edit Interview Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated interview type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Interview type updated.');
        $this->redirect('/recruitment/interview-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interview-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This interview type has interviews assigned to it and cannot be deleted.');
            $this->redirect('/recruitment/interview-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted interview type #' . $id);
        Session::flash('success', 'Interview type deleted.');
        $this->redirect('/recruitment/interview-types');
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
