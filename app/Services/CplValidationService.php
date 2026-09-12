<?php

namespace App\Services;

/**
 * Pre-submission validator (item 13) -- runs every applicable CPLv1.1 rule
 * against one already-built field map (CplExporter::buildFields()'s output)
 * before a batch can be approved for submission. Pure/DB-free by design, so
 * it can be unit-tested the same way CplRecordBuilder/CplExporter are.
 *
 * Classifications, per CPLv1-1.pdf: a BLOCKING error is a rule the spec
 * states will cause the Credit Bureaus to reject the record outright
 * (missing mandatory field, an account-type/status mismatch); a WARNING is
 * a rule the Regulations "should also be submitted" but do not by
 * themselves cause a rejection (address/telephone/employer/income
 * completeness -- see "Credit Bureau Regulations Requirements", p.23); an
 * INFORMATION note flags something worth a human glance but is never a
 * data-quality problem (loan_reason_code defaulted to Other).
 *
 * Deliberately narrower than the full 312-page spec -- covers the mandatory-
 * field and cross-field rules that apply to account types M and P
 * specifically (the only two this system builds), not every account type's
 * own rule set.
 */
class CplValidationService
{
    /** name => max width, for the handful of text fields most likely to overflow and get silently truncated by CplRecordBuilder. */
    private const FIELD_WIDTHS = [
        'surname' => 25, 'forename1' => 14, 'forename2' => 14,
        'employer_detail' => 60, 'occupation' => 20, 'branch_code' => 8,
        'account_no' => 25, 'title' => 5,
    ];

    /**
     * @return array<int, array{severity: string, field: string, message: string}>
     */
    public function validate(array $fields): array
    {
        $issues = [];
        $add = function (string $severity, string $field, string $message) use (&$issues): void {
            $issues[] = ['severity' => $severity, 'field' => $field, 'message' => $message];
        };

        $naId = (string) ($fields['na_id'] ?? '');
        $nonNaId = (string) ($fields['non_na_id'] ?? '');
        if (trim($naId) === '' && trim($nonNaId) === '') {
            $add('BLOCKING', 'na_id', 'No NA ID or Non-NA ID captured -- at least one is mandatory (CPLv1-1.pdf p.23).');
        }
        if (trim($nonNaId) !== '' && trim($naId) === '' && ($fields['date_of_birth'] ?? '') === '') {
            $add('BLOCKING', 'date_of_birth', 'Date of Birth is mandatory when a Non-NA ID is supplied instead of an NA ID.');
        }

        if (trim((string) ($fields['surname'] ?? '')) === '') {
            $add('BLOCKING', 'surname', 'Surname is mandatory.');
        }
        if (trim((string) ($fields['forename1'] ?? '')) === '') {
            $add('BLOCKING', 'forename1', 'At least one forename/initial is mandatory.');
        }

        if (trim((string) ($fields['account_no'] ?? '')) === '') {
            $add('BLOCKING', 'account_no', 'Account Number is mandatory.');
        }

        $accountType = (string) ($fields['type_of_account'] ?? '');
        if (!in_array($accountType, ['M', 'P'], true)) {
            $add('BLOCKING', 'type_of_account', "Account Type must resolve to M or P, got '$accountType'.");
        }

        if (empty($fields['date_account_opened']) || $fields['date_account_opened'] === '00000000') {
            $add('BLOCKING', 'date_account_opened', 'Date Account Opened is mandatory.');
        }

        if (!isset($fields['opening_balance']) || $fields['opening_balance'] === '') {
            $add('BLOCKING', 'opening_balance', 'Opening Balance is mandatory.');
        }

        $hasStatus = !empty($fields['status_code']);
        if (!$hasStatus) {
            if ($fields['current_balance'] === null || $fields['current_balance'] === '') {
                $add('BLOCKING', 'current_balance', 'Current Balance is mandatory unless a status code is supplied.');
            }
            if ($fields['instalment_amount'] === null || $fields['instalment_amount'] === '') {
                $add('BLOCKING', 'instalment_amount', 'Instalment Amount is mandatory unless a status code is supplied.');
            }
        }

        if ($accountType === 'M' && (int) ($fields['terms'] ?? 0) !== 0) {
            $add('BLOCKING', 'terms', 'Account Type M must always report Terms as 0000 (one-month loan).');
        }
        if ($accountType === 'P' && (int) ($fields['terms'] ?? 0) <= 0) {
            $add('BLOCKING', 'terms', 'Account Type P must report the actual agreed Terms (> 0).');
        }

        $monthsInArrears = (int) ($fields['months_in_arrears'] ?? 0);
        $amountOverdue = (int) ($fields['amount_overdue'] ?? 0);
        if ($monthsInArrears > 0 && $amountOverdue <= 0) {
            $add('BLOCKING', 'amount_overdue', 'Months in Arrears is greater than 0 but Amount Overdue is not populated.');
        }

        $income = (int) ($fields['income'] ?? 0);
        if ($income > 0 && trim((string) ($fields['income_frequency'] ?? '')) === '') {
            $add('WARNING', 'income_frequency', 'Income Frequency is mandatory once Income is populated.');
        }

        $hasAnyAddress = trim((string) ($fields['residential_line1'] ?? '')) !== ''
            || trim((string) ($fields['postal_line1'] ?? '')) !== '';
        if (!$hasAnyAddress) {
            $add('WARNING', 'residential_line1', 'No residential or postal address captured -- the Regulations expect one where available.');
        }

        $hasAnyPhone = trim((string) ($fields['home_telephone'] ?? '')) !== ''
            || trim((string) ($fields['cellular_telephone'] ?? '')) !== ''
            || trim((string) ($fields['work_telephone'] ?? '')) !== '';
        if (!$hasAnyPhone) {
            $add('WARNING', 'cellular_telephone', 'No telephone number captured -- the Regulations expect one where available.');
        }

        if (trim((string) ($fields['employer_detail'] ?? '')) === '' && trim((string) ($fields['occupation'] ?? '')) === '') {
            $add('WARNING', 'employer_detail', 'No employer/occupation captured -- the Regulations expect this where the consumer is employed.');
        }

        if (($fields['loan_reason_code'] ?? 'O') === 'O') {
            $add('INFORMATION', 'loan_reason_code', "Loan Reason Code defaulted to 'O' (Other) -- no specific reason was captured from the applicant.");
        }

        foreach (self::FIELD_WIDTHS as $field => $maxWidth) {
            $value = (string) ($fields[$field] ?? '');
            if (strlen($value) > $maxWidth) {
                $add('WARNING', $field, "Value is " . strlen($value) . " characters, longer than the field's $maxWidth-character width -- it will be truncated on submission.");
            }
        }

        return $issues;
    }

    /** Highest severity present, or 'Valid' if the list is empty -- drives cpl_monthly_snapshots.validation_status. */
    public function overallStatus(array $issues): string
    {
        $severities = array_column($issues, 'severity');
        if (in_array('BLOCKING', $severities, true)) {
            return 'Blocking Error';
        }
        if (in_array('WARNING', $severities, true)) {
            return 'Warning';
        }
        return 'Valid';
    }
}
