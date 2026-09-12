<?php

namespace App\Services;

/**
 * "CREDIT BUREAU & CPL READINESS" panel logic for the application screening
 * page. Deliberately separate from anything Creditinfo/CBS-enquiry related
 * -- this only ever answers "is enough demographic/financial data captured
 * to eventually populate a CPL record for this applicant", never whether a
 * credit bureau enquiry has been run. Pure function of the application row
 * (+ its decoded extra_data) -- no DB access, so it's unit-testable in
 * isolation the same way CplExporter's pure methods are.
 */
class CplReadinessService
{
    /**
     * @param array $application One loan_applications row (raw, as returned by LoanApplication::find()).
     */
    public function assess(array $application): array
    {
        $extra = !empty($application['extra_data']) ? (json_decode($application['extra_data'], true) ?: []) : [];

        $identity = !empty($application['applicant_first_name'])
            && !empty($application['applicant_last_name'])
            && (!empty($application['applicant_id_number']) || !empty($extra['passport_no']));

        $dateOfBirth = !empty($extra['dob']);

        $contact = !empty($application['applicant_phone']);

        $address = !empty($extra['residential_line1'])
            && !empty($extra['residential_line3'])
            && !empty($extra['residential_ownership']);

        $employment = !empty($application['employer_name']);

        $income = !empty($application['gross_salary'])
            && (float) $application['gross_salary'] > 0
            && !empty($extra['income_frequency']);

        $cplReady = $identity && $dateOfBirth && $contact && $address && $employment && $income;

        $missing = [];
        if (!$identity) {
            $missing[] = 'Applicant Identity';
        }
        if (!$dateOfBirth) {
            $missing[] = 'Date of Birth';
        }
        if (!$contact) {
            $missing[] = 'Contact Information';
        }
        if (!$address) {
            $missing[] = 'Residential Address';
        }
        if (!$employment) {
            $missing[] = 'Employment';
        }
        if (!$income) {
            $missing[] = 'Income';
        }

        return [
            'identity' => $identity,
            'date_of_birth' => $dateOfBirth,
            'contact' => $contact,
            'address' => $address,
            'employment' => $employment,
            'income' => $income,
            'cpl_ready' => $cplReady,
            'missing' => $missing,
        ];
    }
}
