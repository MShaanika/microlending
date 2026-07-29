<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PerformanceIndicatorCategory;

class PerformanceIndicatorCategoryController extends Controller
{
    private PerformanceIndicatorCategory $categories;

    public function __construct()
    {
        $this->categories = new PerformanceIndicatorCategory();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');
        $this->view('performance/indicator-categories/index', [
            'title' => 'Indicator Categories',
            'categories' => $this->categories->allCategories(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $this->view('performance/indicator-categories/create', [
            'title' => 'Add Indicator Category',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicator-categories/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/indicator-categories/create', [
                'title' => 'Add Indicator Category',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->categories->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Performance', 'Created indicator category #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Indicator category created.');
        $this->redirect('/performance/indicator-categories');
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $category = $this->categories->find($id);
        if (!$category) {
            Session::flash('error', 'Indicator category not found.');
            $this->redirect('/performance/indicator-categories');
            return;
        }
        $this->view('performance/indicator-categories/edit', [
            'title' => 'Edit Indicator Category',
            'category' => $category,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicator-categories/' . $id . '/edit');
            return;
        }

        $category = $this->categories->find($id);
        if (!$category) {
            Session::flash('error', 'Indicator category not found.');
            $this->redirect('/performance/indicator-categories');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('performance/indicator-categories/edit', [
                'title' => 'Edit Indicator Category',
                'category' => array_merge($category, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->categories->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated indicator category #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Indicator category updated.');
        $this->redirect('/performance/indicator-categories');
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicator-categories');
            return;
        }

        if ($this->categories->inUseCount($id) > 0) {
            Session::flash('error', 'This category has indicators assigned to it and cannot be deleted.');
            $this->redirect('/performance/indicator-categories');
            return;
        }

        $this->categories->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted indicator category #' . $id);
        Session::flash('success', 'Indicator category deleted.');
        $this->redirect('/performance/indicator-categories');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->categories->nameExists($name, $excludeId)) {
            $errors['name'] = 'An indicator category with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
