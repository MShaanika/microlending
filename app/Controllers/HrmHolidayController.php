<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmHoliday;
use DateTime;

class HrmHolidayController extends Controller
{
    private HrmHoliday $holidays;

    public function __construct()
    {
        $this->holidays = new HrmHoliday();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $year = !empty($_GET['year']) ? (int) $_GET['year'] : null;
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'start_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->holidays->paginated($year, $search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Holidays',
            'holidays' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'year' => $year,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/holidays/index', $data);
            return;
        }
        $this->view('hrm/holidays/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = ['title' => 'Add Holiday', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/holidays/create', $data);
            return;
        }
        $this->view('hrm/holidays/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/holidays/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/holidays/create', [
                'title' => 'Add Holiday',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->holidays->create($data);

        Audit::log('Create', 'HRM', 'Created holiday #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Holiday added.');
        }
        Session::flash('success', 'Holiday added.');
        $this->redirect('/hrm/holidays');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $holiday = $this->holidays->find($id);
        if (!$holiday) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Holiday not found.'], 404);
            }
            Session::flash('error', 'Holiday not found.');
            $this->redirect('/hrm/holidays');
            return;
        }
        $data = ['title' => 'Edit Holiday', 'holiday' => $holiday, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/holidays/edit', $data);
            return;
        }
        $this->view('hrm/holidays/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/holidays/' . $id . '/edit');
            return;
        }

        $holiday = $this->holidays->find($id);
        if (!$holiday) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Holiday not found.'], 404);
            }
            Session::flash('error', 'Holiday not found.');
            $this->redirect('/hrm/holidays');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/holidays/edit', [
                'title' => 'Edit Holiday',
                'holiday' => array_merge($holiday, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->holidays->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated holiday #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Holiday updated.');
        }
        Session::flash('success', 'Holiday updated.');
        $this->redirect('/hrm/holidays');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/holidays');
            return;
        }

        $holiday = $this->holidays->find($id);
        if ($holiday) {
            $this->holidays->delete($id);
            Audit::log('Delete', 'HRM', 'Deleted holiday #' . $id . ' - ' . $holiday['name']);
            Session::flash('success', 'Holiday deleted.');
        }

        $this->redirect('/hrm/holidays');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $name = trim($post['name'] ?? '');
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '') ?: $startDate;

        if ($name === '') {
            $errors['name'] = 'Holiday name is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        } elseif ($endDate !== '' && (new DateTime($endDate)) < (new DateTime($startDate))) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        }

        $holidayType = in_array($post['holiday_type'] ?? '', ['Public', 'Company', 'Optional'], true)
            ? $post['holiday_type'] : 'Public';

        $data = [
            'name' => $name,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'holiday_type' => $holidayType,
            'description' => trim($post['description'] ?? '') ?: null,
            'is_paid' => !empty($post['is_paid']) ? 1 : 0,
        ];

        return [$data, $errors];
    }
}
