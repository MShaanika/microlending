<?php

namespace App\Services;

use App\Core\Database;

/**
 * Statutory-charge and write-off/recovery reporting for a given period (see
 * ReportPeriod). Same convention as LoanReportService/OperationalReportService.
 */
class RegulatoryReportService
{
    public static function namfisaLevySummary(string $start, string $end): array
    {
        $db = Database::connection();

        $byStatus = $db->prepare(
            "SELECT status, COUNT(*) AS txn_count, COALESCE(SUM(levy_amount),0) AS total_amount
             FROM namfisa_levy_transactions WHERE levy_date BETWEEN ? AND ?
             GROUP BY status ORDER BY FIELD(status, 'Posted','Submitted','Calculated','Reversed')"
        );
        $byStatus->execute([$start, $end]);

        $trend = $db->prepare(
            "SELECT DATE_FORMAT(levy_date, '%Y-%m') AS month_key, DATE_FORMAT(levy_date, '%M %Y') AS month_label,
                    COALESCE(SUM(levy_amount),0) AS total_amount
             FROM namfisa_levy_transactions WHERE levy_date BETWEEN ? AND ?
             GROUP BY month_key, month_label ORDER BY month_key"
        );
        $trend->execute([$start, $end]);

        $total = $db->prepare(
            "SELECT COALESCE(SUM(levy_amount),0) FROM namfisa_levy_transactions WHERE levy_date BETWEEN ? AND ?"
        );
        $total->execute([$start, $end]);

        return [
            'total_amount' => round((float) $total->fetchColumn(), 2),
            'by_status' => $byStatus->fetchAll(),
            'trend' => $trend->fetchAll(),
        ];
    }

    public static function dutyStampSummary(string $start, string $end): array
    {
        $db = Database::connection();

        $byStatus = $db->prepare(
            "SELECT status, COUNT(*) AS txn_count, COALESCE(SUM(stamp_amount),0) AS total_amount
             FROM duty_stamp_transactions WHERE stamp_date BETWEEN ? AND ?
             GROUP BY status ORDER BY FIELD(status, 'Posted','Submitted','Calculated','Reversed')"
        );
        $byStatus->execute([$start, $end]);

        $trend = $db->prepare(
            "SELECT DATE_FORMAT(stamp_date, '%Y-%m') AS month_key, DATE_FORMAT(stamp_date, '%M %Y') AS month_label,
                    COALESCE(SUM(stamp_amount),0) AS total_amount
             FROM duty_stamp_transactions WHERE stamp_date BETWEEN ? AND ?
             GROUP BY month_key, month_label ORDER BY month_key"
        );
        $trend->execute([$start, $end]);

        $total = $db->prepare(
            "SELECT COALESCE(SUM(stamp_amount),0) FROM duty_stamp_transactions WHERE stamp_date BETWEEN ? AND ?"
        );
        $total->execute([$start, $end]);

        return [
            'total_amount' => round((float) $total->fetchColumn(), 2),
            'by_status' => $byStatus->fetchAll(),
            'trend' => $trend->fetchAll(),
        ];
    }

    /**
     * Posted payments grouped by payment_source (Cash/Debit Order/Bank
     * Transfer/Wallet/Manual Adjustment/Other) -- not payment_method_id,
     * which the current write paths (Payment::recordAndAllocate()) never
     * populate.
     */
    public static function paymentMethodSummary(string $start, string $end): array
    {
        $db = Database::connection();

        $byMethod = $db->prepare(
            "SELECT payment_source, COUNT(*) AS txn_count, COALESCE(SUM(amount_received),0) AS total_amount
             FROM payments WHERE status = 'Posted' AND payment_date BETWEEN ? AND ?
             GROUP BY payment_source ORDER BY total_amount DESC"
        );
        $byMethod->execute([$start, $end]);

        $trend = $db->prepare(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_key, DATE_FORMAT(payment_date, '%M %Y') AS month_label,
                    payment_source, COALESCE(SUM(amount_received),0) AS total_amount
             FROM payments WHERE status = 'Posted' AND payment_date BETWEEN ? AND ?
             GROUP BY month_key, month_label, payment_source ORDER BY month_key"
        );
        $trend->execute([$start, $end]);

        $total = $db->prepare(
            "SELECT COALESCE(SUM(amount_received),0) FROM payments WHERE status = 'Posted' AND payment_date BETWEEN ? AND ?"
        );
        $total->execute([$start, $end]);

        return [
            'total_amount' => round((float) $total->fetchColumn(), 2),
            'by_method' => $byMethod->fetchAll(),
            'trend' => $trend->fetchAll(),
        ];
    }

    public static function badDebtWriteOffSummary(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT status, aging_bucket, COUNT(*) AS loan_count, COALESCE(SUM(outstanding_balance),0) AS total_outstanding
             FROM bad_debts WHERE identified_date BETWEEN ? AND ?
             GROUP BY status, aging_bucket
             ORDER BY FIELD(aging_bucket, '31-60','61-90','91-180','180+')"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    public static function recoverySummary(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS recovery_count, COALESCE(SUM(recovered_amount),0) AS total_recovered
             FROM loan_recoveries WHERE status = 'Posted' AND recovery_date BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetch() ?: ['recovery_count' => 0, 'total_recovered' => 0.0];
    }
}
