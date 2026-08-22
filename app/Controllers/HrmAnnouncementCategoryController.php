<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAnnouncementCategory;

class HrmAnnouncementCategoryController extends Controller
{
    private HrmAnnouncementCategory $categories;

    public function __construct()
    {
        $this->categories = new HrmAnnouncementCategory();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->categories->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Announcement Categories',
            'categories' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcement-categories/index', $data);
            return;
        }
        $this->view('hrm/announcement-categories/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = ['title' => 'Add Announcement Category', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcement-categories/create', $data);
            return;
        }
        $this->view('hrm/announcement-categories/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcement-categories/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/announcement-categories/create', [
                'title' => 'Add Announcement Category',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->categories->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created announcement category #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Announcement category created.');
        }
        Session::flash('success', 'Announcement category created.');
        $this->redirect('/hrm/announcement-categories');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $category = $this->categories->find($id);
        if (!$category) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Announcement category not found.'], 404);
            }
            Session::flash('error', 'Announcement category not found.');
            $this->redirect('/hrm/announcement-categories');
            return;
        }
        $data = ['title' => 'Edit Announcement Category', 'category' => $category, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcement-categories/edit', $data);
            return;
        }
        $this->view('hrm/announcement-categories/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcement-categories/' . $id . '/edit');
            return;
        }

        $category = $this->categories->find($id);
        if (!$category) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Announcement category not found.'], 404);
            }
            Session::flash('error', 'Announcement category not found.');
            $this->redirect('/hrm/announcement-categories');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/announcement-categories/edit', [
                'title' => 'Edit Announcement Category',
                'category' => array_merge($category, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->categories->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated announcement category #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Announcement category updated.');
        }
        Session::flash('success', 'Announcement category updated.');
        $this->redirect('/hrm/announcement-categories');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcement-categories');
            return;
        }

        if ($this->categories->inUseCount($id) > 0) {
            Session::flash('error', 'This category is used by existing announcements and cannot be deleted.');
            $this->redirect('/hrm/announcement-categories');
            return;
        }

        $this->categories->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted announcement category #' . $id);
        Session::flash('success', 'Announcement category deleted.');
        $this->redirect('/hrm/announcement-categories');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->categories->nameExists($name, $excludeId)) {
            $errors['name'] = 'An announcement category with this name already exists.';
        }

        $data = ['name' => $name];

        return [$data, $errors];
    }
}
