<?php

namespace App\Services;

use App\Models\EmployeeContract;
use App\Models\EmploymentContractTemplate;
use App\Models\HrmDesignation;
use App\Models\HrmEmployee;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Fills the one real employment-contract .docx template
 * (App\Models\EmploymentContractTemplate) for one employee and saves the
 * result -- the HR equivalent of DocumentGenerationService, but
 * deliberately its own class rather than extending that one or
 * DocumentFieldResolver, since those are both built entirely around
 * Borrower/Loan/RefundClaim context and have no concept of an employee.
 *
 * Every field except Duties comes from direct manual input (contract
 * date, term start/end) or the employee's own record (name, position,
 * salary); Duties is resolved automatically from the employee's
 * designation's standard_duties -- the one clause real contracts showed
 * genuinely differs by role rather than being fill-in-the-blank. A
 * designation with no standard_duties set yet still generates (falls
 * back to a placeholder line asking HR to add duties for that role) --
 * never silently produces a contract with a blank Section 4.
 */
class EmploymentContractService
{
    private HrmEmployee $employees;
    private HrmDesignation $designations;
    private EmploymentContractTemplate $templates;
    private EmployeeContract $contracts;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->designations = new HrmDesignation();
        $this->templates = new EmploymentContractTemplate();
        $this->contracts = new EmployeeContract();
    }

    /**
     * @throws \RuntimeException if the employee/template is missing or the term dates are invalid
     * @return int the new employee_contracts row id
     */
    public function generate(int $employeeId, int $templateId, string $contractDate, string $termStartDate, string $termEndDate, int $userId): int
    {
        $employee = $this->employees->find($employeeId);
        if (!$employee) {
            throw new \RuntimeException('Employee not found.');
        }
        $template = $this->templates->find($templateId);
        if (!$template || !$template['is_active']) {
            throw new \RuntimeException('Contract template not found or inactive.');
        }
        if (strtotime($termEndDate) <= strtotime($termStartDate)) {
            throw new \RuntimeException('Term end date must be after the term start date.');
        }

        $designation = $employee['designation_id'] ? $this->designations->find((int) $employee['designation_id']) : null;
        $duties = trim((string) ($designation['standard_duties'] ?? ''));
        if ($duties === '') {
            $duties = 'Duties for this role have not yet been added to its Designation -- update "' . ($employee['designation_name'] ?? 'this designation') . '" under HRM > Designations before relying on this contract.';
        }

        $templatePath = STORAGE_PATH . '/' . $template['file_path'];
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Template file is missing from storage: ' . $template['file_path']);
        }

        $processor = new TemplateProcessor($templatePath);
        $processor->setValue('CONTRACT_DATE', $this->formatDate($contractDate));
        $processor->setValue('EMPLOYEE_NAME', $this->escapeXml($employee['first_name'] . ' ' . $employee['last_name']));
        $processor->setValue('POSITION', $this->escapeXml($employee['designation_name'] ?? ''));
        $processor->setValue('TERM_START_DATE', $this->formatDate($termStartDate));
        $processor->setValue('TERM_END_DATE', $this->formatDate($termEndDate));
        $processor->setValue('SALARY', 'N$' . number_format((float) $employee['basic_salary'], 2));
        $processor->setValue('DUTIES', $this->dutiesAsLineBreaks($duties));

        $targetDir = STORAGE_PATH . '/employee_contracts';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $employee['employee_no'] . '_' . $termStartDate) . '.docx';
        $processor->saveAs($targetDir . '/' . $filename);

        return $this->contracts->create([
            'employee_id' => $employeeId,
            'template_id' => $templateId,
            'contract_date' => $contractDate,
            'term_start_date' => $termStartDate,
            'term_end_date' => $termEndDate,
            'position_at_time' => $employee['designation_name'] ?? '',
            'salary_at_time' => $employee['basic_salary'],
            'duties_at_time' => $duties,
            'file_path' => 'employee_contracts/' . $filename,
            'generated_by' => $userId,
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function formatDate(string $date): string
    {
        $ts = strtotime($date);
        return $ts ? date('d F Y', $ts) : $date;
    }

    /**
     * TemplateProcessor::setValue() does a raw string substitution into
     * the XML, so a literal "\n" never breaks a line in Word -- inserting
     * a real <w:br/> tag directly into the replacement string does,
     * since it's substituted at the XML level, not as escaped text. Each
     * duty (one per input line) becomes its own bulleted line within the
     * ${DUTIES} paragraph.
     */
    private function dutiesAsLineBreaks(string $duties): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $duties)), fn ($l) => $l !== ''));
        $bulleted = array_map(fn ($l) => $this->escapeXml('• ' . $l), $lines);
        return implode('</w:t><w:br/><w:t xml:space="preserve">', $bulleted);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
