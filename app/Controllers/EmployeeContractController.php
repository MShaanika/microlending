<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\EmployeeContract;
use App\Models\EmploymentContractTemplate;
use App\Models\HrmEmployee;
use App\Services\EmploymentContractService;

/**
 * Employment Contract generation for an existing employee (contract
 * renewals, not Recruitment's pre-hire Offer Letters, which is a
 * separate, unrelated screen for candidates). One real .docx template,
 * filled per employee by EmploymentContractService -- see that class's
 * own docblock for why Duties resolves automatically from the employee's
 * designation while everything else is direct manual/record-based input.
 */
class EmployeeContractController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['docx'];

    private HrmEmployee $employees;
    private EmploymentContractTemplate $templates;
    private EmployeeContract $contracts;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->templates = new EmploymentContractTemplate();
        $this->contracts = new EmployeeContract();
    }

    public function templateIndex(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/employment_contract_templates/index', [
            'title' => 'Employment Contract Templates',
            'templates' => $this->templates->allTemplates(),
        ]);
    }

    public function replaceTemplate(int $id): void
    {
        Auth::authorize('hrm.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employment-contract-templates');
            return;
        }

        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/hrm/employment-contract-templates');
            return;
        }

        $file = $_FILES['template_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose a .docx file to upload.');
            $this->redirect('/hrm/employment-contract-templates');
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'The file failed to upload. Please try again.');
            $this->redirect('/hrm/employment-contract-templates');
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            Session::flash('error', 'Only .docx files are allowed.');
            $this->redirect('/hrm/employment-contract-templates');
            return;
        }

        $targetDir = STORAGE_PATH . '/employment_contract_templates';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'employment_agreement_renewal_' . date('YmdHis') . '.docx';
        move_uploaded_file($file['tmp_name'], $targetDir . '/' . $filename);

        $this->templates->updateRecord($id, ['file_path' => 'employment_contract_templates/' . $filename]);
        Audit::log('Update', 'HRM', 'Replaced employment contract template #' . $id . ' file');
        Session::flash('success', 'Template file replaced.');
        $this->redirect('/hrm/employment-contract-templates');
    }

    public function generate(int $employeeId): void
    {
        Auth::authorize('hrm.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/' . $employeeId);
            return;
        }

        $templateId = (int) ($_POST['template_id'] ?? 0);
        $contractDate = trim((string) ($_POST['contract_date'] ?? ''));
        $termStartDate = trim((string) ($_POST['term_start_date'] ?? ''));
        $termEndDate = trim((string) ($_POST['term_end_date'] ?? ''));

        if (strtotime($contractDate) === false || strtotime($termStartDate) === false || strtotime($termEndDate) === false) {
            Session::flash('error', 'Please provide valid contract, term start and term end dates.');
            $this->redirect('/hrm/employees/' . $employeeId);
            return;
        }

        try {
            $contractId = (new EmploymentContractService())->generate(
                $employeeId,
                $templateId,
                $contractDate,
                $termStartDate,
                $termEndDate,
                (int) (Auth::user()['id'] ?? 0)
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/hrm/employees/' . $employeeId);
            return;
        }

        Audit::log('Create', 'HRM', 'Generated employment contract #' . $contractId . ' for employee #' . $employeeId);
        Session::flash('success', 'Employment contract generated.');
        $this->redirect('/hrm/employees/' . $employeeId);
    }

    public function download(int $employeeId, int $contractId): void
    {
        Auth::authorize('hrm.view');
        $contract = $this->contracts->find($contractId);
        if (!$contract || (int) $contract['employee_id'] !== $employeeId) {
            Session::flash('error', 'Contract not found.');
            $this->redirect('/hrm/employees/' . $employeeId);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $contract['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/hrm/employees/' . $employeeId);
            return;
        }

        $employee = $this->employees->find($employeeId);
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', ($employee['first_name'] ?? 'employee') . '_' . ($employee['last_name'] ?? '') . '_contract') . '.docx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
