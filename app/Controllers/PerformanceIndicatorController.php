<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PerformanceIndicator;
use App\Models\PerformanceIndicatorCategory;

class PerformanceIndicatorController extends Controller
{
    private PerformanceIndicator $indicators;
    private PerformanceIndicatorCategory $categories;

    public function __construct()
    {
        $this->indicators = new PerformanceIndicator();
        $this->categories = new PerformanceIndicatorCategory();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');
        $this->view('performance/indicators/index', [
            'title' => 'Performance Indicators',
            'indicators' => $this->indicators->allIndicators(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $this->view('performance/indicators/create', [
            'title' => 'Add Performance Indicator',
            'categories' => $this->categories->allCategories(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicators/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/indicators/create', [
                'title' => 'Add Performance Indicator',
                'categories' => $this->categories->allCategories(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->indicators->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Performance', 'Created performance indicator #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Performance indicator created.');
        $this->redirect('/performance/indicators');
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $indicator = $this->indicators->find($id);
        if (!$indicator) {
            Session::flash('error', 'Performance indicator not found.');
            $this->redirect('/performance/indicators');
            return;
        }
        $this->view('performance/indicators/edit', [
            'title' => 'Edit Performance Indicator',
            'indicator' => $indicator,
            'categories' => $this->categories->allCategories(),
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicators/' . $id . '/edit');
            return;
        }

        $indicator = $this->indicators->find($id);
        if (!$indicator) {
            Session::flash('error', 'Performance indicator not found.');
            $this->redirect('/performance/indicators');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/indicators/edit', [
                'title' => 'Edit Performance Indicator',
                'indicator' => array_merge($indicator, $_POST),
                'categories' => $this->categories->allCategories(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->indicators->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated performance indicator #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Performance indicator updated.');
        $this->redirect('/performance/indicators');
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/indicators');
            return;
        }

        $this->indicators->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted performance indicator #' . $id);
        Session::flash('success', 'Performance indicator deleted.');
        $this->redirect('/performance/indicators');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $name = trim($post['name'] ?? '');
        $categoryId = !empty($post['category_id']) ? (int) $post['category_id'] : null;
        $measurementUnit = trim($post['measurement_unit'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!$categoryId) {
            $errors['category_id'] = 'Select a category.';
        }
        if ($measurementUnit === '') {
            $errors['measurement_unit'] = 'Measurement unit is required.';
        }

        $data = [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'measurement_unit' => $measurementUnit ?: null,
            'target_value' => trim($post['target_value'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
