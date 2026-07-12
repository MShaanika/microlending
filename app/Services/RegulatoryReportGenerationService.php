<?php

namespace App\Services;

use App\Core\Database;
use App\Models\RegulatoryReport;
use App\Models\RegulatoryReportLine;
use App\Models\RegulatoryReportType;

/**
 * Snapshots one of the 8 seeded regulatory_report_types into a persisted
 * regulatory_reports header + regulatory_report_lines rows, so it can be
 * tracked through the Draft/Generated/Submitted/Approved/Rejected workflow
 * and exported -- the actual number-crunching for every report type
 * already exists and is already correct (LoanReportService /
 * RegulatoryReportService, built for the Reports module); this only maps
 * their output into the regulatory_report_lines shape and totals it up.
 */
class RegulatoryReportGenerationService
{
    public static function generate(string $reportTypeCode, string $periodStart, string $periodEnd, int $userId): int
    {
        $types = new RegulatoryReportType();
        $type = $types->findByCode($reportTypeCode);
        if (!$type) {
            throw new \RuntimeException('Unknown report type: ' . $reportTypeCode);
        }

        [$lines, $totals] = self::buildLinesAndTotals($reportTypeCode, $periodStart, $periodEnd);

        $db = Database::connection();
        $reports = new RegulatoryReport();
        $reportLines = new RegulatoryReportLine();

        $db->beginTransaction();
        try {
            $reportId = $reports->create(array_merge($totals, [
                'report_type_id' => (int) $type['id'],
                'report_no' => generate_reference('REG'),
                'report_period' => $periodStart . ' to ' . $periodEnd,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'Generated',
                'generated_by' => $userId,
                'generated_at' => date('Y-m-d H:i:s'),
            ]));

            $reportLines->insertLines($reportId, $lines);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $reportId;
    }

    /**
     * @return array{0: array<int, array>, 1: array}
     */
    private static function buildLinesAndTotals(string $code, string $start, string $end): array
    {
        return match ($code) {
            'LOAN_GENDER_QTR' => self::fromGenderBreakdown($start, $end),
            'LOAN_SIZE_GENDER_QTR' => self::fromSizeBreakdown($start, $end),
            'LOAN_SALARY_QTR' => self::fromSalaryBreakdown($start, $end),
            'BAD_DEBTS_QTR' => self::fromBadDebts($start, $end),
            'BAD_DEBT_RECOVERY_QTR' => self::fromBadDebtRecoveries($start, $end),
            'NAMFISA_LEVY_QTR' => self::fromNamfisaLevy($start, $end),
            'DUTY_STAMP_QTR' => self::fromDutyStamp($start, $end),
            'CURRENT_LOAN_QTR' => self::fromCurrentLoanStatus($end),
            default => throw new \RuntimeException('No line-generation logic for report type: ' . $code),
        };
    }

    private static function fromGenderBreakdown(string $start, string $end): array
    {
        $rows = LoanReportService::genderBreakdown($start, $end);
        $lines = [];
        $totalLoans = 0;
        $totalPrincipal = 0.0;

        foreach ($rows as $row) {
            $lines[] = [
                'line_category' => 'Loans by Gender',
                'gender' => in_array($row['gender'], ['Male', 'Female'], true) ? $row['gender'] : null,
                'line_description' => $row['gender'],
                'loan_count' => (int) $row['loan_count'],
                'principal_amount' => (float) $row['total_amount'],
            ];
            $totalLoans += (int) $row['loan_count'];
            $totalPrincipal += (float) $row['total_amount'];
        }

        return [$lines, ['total_loans' => $totalLoans, 'total_principal' => round($totalPrincipal, 2)]];
    }

    private static function fromSizeBreakdown(string $start, string $end): array
    {
        $rows = LoanReportService::sizeBreakdown($start, $end);
        $lines = [];
        $totalLoans = 0;
        $totalPrincipal = 0.0;

        foreach ($rows as $row) {
            foreach (['Male', 'Female'] as $gender) {
                $count = (int) $row[strtolower($gender) . '_payers'];
                $amount = (float) $row[strtolower($gender) . '_amount'];
                if ($count === 0 && $amount == 0.0) {
                    continue;
                }
                $lines[] = [
                    'line_category' => 'Loans by Size and Gender',
                    'loan_size_band' => $row['label'],
                    'gender' => $gender,
                    'loan_count' => $count,
                    'principal_amount' => $amount,
                ];
                $totalLoans += $count;
                $totalPrincipal += $amount;
            }
        }

        return [$lines, ['total_loans' => $totalLoans, 'total_principal' => round($totalPrincipal, 2)]];
    }

    private static function fromSalaryBreakdown(string $start, string $end): array
    {
        $rows = LoanReportService::salaryBreakdown($start, $end);
        $lines = [];
        $totalLoans = 0;

        foreach ($rows as $row) {
            foreach (['Male', 'Female'] as $gender) {
                $count = (int) $row[strtolower($gender) . '_count'];
                $salary = (float) $row[strtolower($gender) . '_salary'];
                if ($count === 0 && $salary == 0.0) {
                    continue;
                }
                $lines[] = [
                    'line_category' => 'Loans by Salary Band',
                    'salary_band' => $row['label'],
                    'gender' => $gender,
                    'loan_count' => $count,
                    'principal_amount' => $salary,
                ];
                $totalLoans += $count;
            }
        }

        return [$lines, ['total_loans' => $totalLoans]];
    }

    private static function fromBadDebts(string $start, string $end): array
    {
        $rows = LoanReportService::badDebtsBreakdown($start, $end);
        $lines = [];
        $totalLoans = 0;
        $totalBadDebts = 0.0;

        foreach ($rows as $row) {
            $lines[] = [
                'line_category' => 'Bad Debts - ' . $row['aging_bucket'] . ' days',
                'loan_count' => (int) $row['loan_count'],
                'bad_debt_amount' => (float) $row['total_outstanding'],
            ];
            $totalLoans += (int) $row['loan_count'];
            $totalBadDebts += (float) $row['total_outstanding'];
        }

        return [$lines, ['total_loans' => $totalLoans, 'total_bad_debts' => round($totalBadDebts, 2)]];
    }

    private static function fromBadDebtRecoveries(string $start, string $end): array
    {
        $rows = LoanReportService::badDebtRecoveries($start, $end);
        $lines = [];
        $totalRecoveries = 0.0;

        foreach ($rows as $row) {
            $lines[] = [
                'line_category' => 'Bad Debt Recovery',
                'line_description' => $row['loan_no'] . ' - ' . $row['borrower_name'] . ' (' . ($row['bad_debt_status'] ?? 'n/a') . '), recovered ' . $row['recovery_date'],
                'loan_count' => 1,
                'recovery_amount' => (float) $row['recovered_amount'],
            ];
            $totalRecoveries += (float) $row['recovered_amount'];
        }

        return [$lines, ['total_loans' => count($rows), 'total_recoveries' => round($totalRecoveries, 2)]];
    }

    private static function fromNamfisaLevy(string $start, string $end): array
    {
        $summary = RegulatoryReportService::namfisaLevySummary($start, $end);
        $lines = [];

        foreach ($summary['by_status'] as $row) {
            $lines[] = [
                'line_category' => 'NAMFISA Levy - ' . $row['status'],
                'loan_count' => (int) $row['txn_count'],
                'levy_amount' => (float) $row['total_amount'],
            ];
        }

        return [$lines, ['total_namfisa_levy' => round((float) $summary['total_amount'], 2)]];
    }

    private static function fromDutyStamp(string $start, string $end): array
    {
        $summary = RegulatoryReportService::dutyStampSummary($start, $end);
        $lines = [];

        foreach ($summary['by_status'] as $row) {
            $lines[] = [
                'line_category' => 'Duty Stamp - ' . $row['status'],
                'loan_count' => (int) $row['txn_count'],
                'duty_stamp_amount' => (float) $row['total_amount'],
            ];
        }

        return [$lines, ['total_duty_stamp' => round((float) $summary['total_amount'], 2)]];
    }

    private static function fromCurrentLoanStatus(string $asOfDate): array
    {
        $status = LoanReportService::activeLoanStatus($asOfDate);

        $lines = [
            [
                'line_category' => 'Current (On Track)',
                'loan_count' => (int) $status['current_count'],
                'outstanding_amount' => (float) $status['current_outstanding'],
            ],
            [
                'line_category' => 'In Arrears',
                'loan_count' => (int) $status['arrears_count'],
                'outstanding_amount' => (float) $status['arrears_outstanding'],
            ],
        ];

        return [$lines, ['total_loans' => (int) $status['total_count']]];
    }
}
