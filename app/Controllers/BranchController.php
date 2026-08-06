<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\Company;

class BranchController extends Controller
{
    private Branch $branches;
    private Company $companies;

    public function __construct()
    {
        $this->branches = new Branch();
        $this->companies = new Company();
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('branches/index', [
            'title' => 'Branches',
            'branches' => $this->branches->allBranches(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('branches/create', [
            'title' => 'Add Branch',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/branches/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('branches/create', [
                'title' => 'Add Branch',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $company = $this->companies->primary();
        $data['company_id'] = (int) ($company['id'] ?? 1);
        $data['is_active'] = 1;

        $id = $this->branches->create($data);

        Audit::log('Create', 'Admin', 'Created branch #' . $id . ' - ' . $data['branch_name']);
        Session::flash('success', 'Branch created.');
        $this->redirect('/branches');
    }

    public function edit(string $id): void
    {
        Auth::authorize('admin.system_settings');
        $branch = $this->branches->find((int) $id);
        if (!$branch) {
            Session::flash('error', 'Branch not found.');
            $this->redirect('/branches');
            return;
        }
        $this->view('branches/edit', [
            'title' => 'Edit Branch',
            'branch' => $branch,
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('admin.system_settings');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/branches/' . $id . '/edit');
            return;
        }

        $branch = $this->branches->find($id);
        if (!$branch) {
            Session::flash('error', 'Branch not found.');
            $this->redirect('/branches');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('branches/edit', [
                'title' => 'Edit Branch',
                'branch' => array_merge($branch, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->branches->updateRecord($id, $data);

        Audit::log('Update', 'Admin', 'Updated branch #' . $id . ' - ' . $data['branch_name']);
        Session::flash('success', 'Branch updated.');
        $this->redirect('/branches');
    }

    public function toggleActive(string $id): void
    {
        Auth::authorize('admin.system_settings');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/branches');
            return;
        }

        $branch = $this->branches->find($id);
        if (!$branch) {
            Session::flash('error', 'Branch not found.');
            $this->redirect('/branches');
            return;
        }

        $newState = (int) $branch['is_active'] === 1 ? 0 : 1;
        $this->branches->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'Admin', ($newState ? 'Activated' : 'Deactivated') . ' branch #' . $id . ' - ' . $branch['branch_name']);
        Session::flash('success', 'Branch ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/branches');
    }

    /**
     * @return array{0: array, 1: array} [validated data ready for insert/update, field => error message]
     */
    private function validate(array $post, ?int $excludeId = null): array
    {
        $errors = [];

        $name = trim($post['branch_name'] ?? '');
        if ($name === '') {
            $errors['branch_name'] = 'Branch name is required.';
        } elseif ($this->branches->nameExists($name, $excludeId)) {
            $errors['branch_name'] = 'A branch with this name already exists.';
        }

        $code = trim($post['branch_code'] ?? '');
        if ($code !== '' && $this->branches->codeExists($code, $excludeId)) {
            $errors['branch_code'] = 'A branch with this code already exists.';
        }

        $data = [
            'branch_name' => $name,
            'branch_code' => $code !== '' ? $code : null,
            'phone' => trim($post['phone'] ?? '') ?: null,
            'email' => trim($post['email'] ?? '') ?: null,
            'address' => trim($post['address'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
