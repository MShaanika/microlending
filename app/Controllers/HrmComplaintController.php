<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmComplaint;
use App\Models\HrmComplaintType;
use App\Models\HrmEmployee;

class HrmComplaintController extends Controller
{
    private const STATUSES = ['Pending', 'In Review', 'Assigned', 'In Progress', 'Resolved'];

    private HrmComplaint $complaints;
    private HrmEmployee $employees;
    private HrmComplaintType $complaintTypes;

    public function __construct()
    {
        $this->complaints = new HrmComplaint();
        $this->employees = new HrmEmployee();
        $this->complaintTypes = new HrmComplaintType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];

        $this->view('hrm/complaints/index', [
            'title' => 'Complaints',
            'complaints' => $this->complaints->allComplaints($filters),
            'employees' => $this->employees->allEmployees(),
            'statuses' => self::STATUSES,
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/complaints/create', [
            'title' => 'File a Complaint',
            'employees' => $this->employees->allEmployees(),
            'complaintTypes' => $this->complaintTypes->allTypes(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/complaints/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/complaints/create', [
                'title' => 'File a Complaint',
                'employees' => $this->employees->allEmployees(),
                'complaintTypes' => $this->complaintTypes->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->complaints->create($data);

        Audit::log('Create', 'HRM', 'Complaint #' . $id . ' filed against employee handling');
        Session::flash('success', 'Complaint filed.');
        $this->redirect('/hrm/complaints');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $complaint = $this->complaints->find($id);
        if (!$complaint) {
            Session::flash('error', 'Complaint not found.');
            $this->redirect('/hrm/complaints');
            return;
        }
        $this->view('hrm/complaints/show', [
            'title' => 'Complaint',
            'complaint' => $complaint,
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/complaints/' . $id);
            return;
        }

        $complaint = $this->complaints->find($id);
        if (!$complaint) {
            Session::flash('error', 'Complaint not found.');
            $this->redirect('/hrm/complaints');
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/hrm/complaints/' . $id);
            return;
        }

        $update = [
            'status' => $status,
            'resolved_by' => Auth::user()['id'] ?? null,
        ];
        if ($status === 'Resolved') {
            $update['resolution_date'] = date('Y-m-d');
        }

        $this->complaints->updateRecord($id, $update);

        Audit::log('Update', 'HRM', 'Complaint #' . $id . ' status set to ' . $status);
        Session::flash('success', 'Complaint status updated.');
        $this->redirect('/hrm/complaints/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/complaints');
            return;
        }

        $this->complaints->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted complaint #' . $id);
        Session::flash('success', 'Complaint deleted.');
        $this->redirect('/hrm/complaints');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $subject = trim($post['subject'] ?? '');
        $complaintDate = trim($post['complaint_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select the complainant.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if ($complaintDate === '') {
            $errors['complaint_date'] = 'Complaint date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'against_employee_id' => !empty($post['against_employee_id']) ? (int) $post['against_employee_id'] : null,
            'complaint_type_id' => !empty($post['complaint_type_id']) ? (int) $post['complaint_type_id'] : null,
            'subject' => $subject,
            'description' => trim($post['description'] ?? '') ?: null,
            'complaint_date' => $complaintDate ?: null,
        ];

        return [$data, $errors];
    }
}
