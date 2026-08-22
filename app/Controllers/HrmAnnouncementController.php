<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAnnouncement;
use App\Models\HrmAnnouncementCategory;
use App\Models\HrmDepartment;
use DateTime;

class HrmAnnouncementController extends Controller
{
    private const STATUSES = ['Draft', 'Active', 'Inactive'];

    private HrmAnnouncement $announcements;
    private HrmAnnouncementCategory $categories;
    private HrmDepartment $departments;

    public function __construct()
    {
        $this->announcements = new HrmAnnouncement();
        $this->categories = new HrmAnnouncementCategory();
        $this->departments = new HrmDepartment();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'status' => $_GET['status'] ?? '',
            'department_id' => $_GET['department_id'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'start_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->announcements->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Announcements',
            'announcements' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'departments' => $this->departments->allDepartments(true),
            'statuses' => self::STATUSES,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcements/index', $data);
            return;
        }
        $this->view('hrm/announcements/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'New Announcement',
            'categories' => $this->categories->allCategories(),
            'departments' => $this->departments->allDepartments(true),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcements/create', $data);
            return;
        }
        $this->view('hrm/announcements/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcements/create');
            return;
        }

        [$data, $departmentIds, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/announcements/create', [
                'title' => 'New Announcement',
                'categories' => $this->categories->allCategories(),
                'departments' => $this->departments->allDepartments(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Draft';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->announcements->create($data);
        $this->announcements->syncDepartments($id, $departmentIds);

        Audit::log('Create', 'HRM', 'Announcement #' . $id . ' created - ' . $data['title']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Announcement created as Draft.');
        }
        Session::flash('success', 'Announcement created as Draft.');
        $this->redirect('/hrm/announcements');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $announcement = $this->announcements->find($id);
        if (!$announcement) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Announcement not found.'], 404);
            }
            Session::flash('error', 'Announcement not found.');
            $this->redirect('/hrm/announcements');
            return;
        }
        $data = [
            'title' => 'Announcement',
            'announcement' => $announcement,
            'departmentNames' => $this->announcements->departmentNamesFor($id),
            'statuses' => self::STATUSES,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/announcements/show', $data);
            return;
        }
        $this->view('hrm/announcements/show', $data);
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcements/' . $id);
            return;
        }

        $announcement = $this->announcements->find($id);
        if (!$announcement) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Announcement not found.'], 404);
            }
            Session::flash('error', 'Announcement not found.');
            $this->redirect('/hrm/announcements');
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['status' => 'Invalid status.']);
            }
            Session::flash('error', 'Invalid status.');
            $this->redirect('/hrm/announcements/' . $id);
            return;
        }

        $this->announcements->updateRecord($id, [
            'status' => $status,
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'HRM', 'Announcement #' . $id . ' status set to ' . $status);

        if ($this->isAjax()) {
            $this->jsonSuccess('Announcement status updated.', '/hrm/announcements/' . $id);
        }
        Session::flash('success', 'Announcement status updated.');
        $this->redirect('/hrm/announcements/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/announcements');
            return;
        }

        $this->announcements->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted announcement #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Announcement deleted.');
        }
        Session::flash('success', 'Announcement deleted.');
        $this->redirect('/hrm/announcements');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $title = trim($post['title'] ?? '');
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '');
        $departmentIds = array_filter(array_map('intval', $post['department_ids'] ?? []));

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        } elseif ($startDate !== '' && new DateTime($endDate) < new DateTime($startDate)) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        }
        if (empty($departmentIds)) {
            $errors['department_ids'] = 'Select at least one department.';
        }

        $data = [
            'title' => $title,
            'announcement_category_id' => !empty($post['announcement_category_id']) ? (int) $post['announcement_category_id'] : null,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'priority' => in_array($post['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $post['priority'] : 'Low',
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $departmentIds, $errors];
    }
}
