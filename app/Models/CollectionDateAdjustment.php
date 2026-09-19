<?php

namespace App\Models;

use App\Core\Model;

/**
 * Data access for collection_date_adjustment_batches /
 * debit_order_collection_adjustments. Deliberately thin -- computation
 * (BusinessCalendarService/PayCycleResolverService) and orchestration
 * (CollectionDateAdjustmentService) live elsewhere, matching how
 * ApprovalRequest relates to ApprovalService.
 */
class CollectionDateAdjustment extends Model
{
    // --- Batches ---

    public function createBatch(?int $generatedBy): int
    {
        return $this->insert('collection_date_adjustment_batches', ['generated_by' => $generatedBy]);
    }

    public function findBatch(int $id): ?array
    {
        return $this->one("SELECT * FROM collection_date_adjustment_batches WHERE id = ?", [$id]);
    }

    public function updateBatch(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $this->query("UPDATE collection_date_adjustment_batches SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    /** Mirrors ApprovalRequest::findPendingByResource -- the same lookup pattern LoanWriteOffController uses. */
    public function findPendingApprovalRequestForBatch(int $batchId): ?array
    {
        return (new ApprovalRequest())->findPendingByResource('Collections', 'collection_date_adjustment_batch', $batchId);
    }

    // --- Adjustment rows ---

    public function createAdjustment(array $data): int
    {
        return $this->insert('debit_order_collection_adjustments', $data);
    }

    /** The idempotency check generateRollingPreview() relies on -- see pay_cycle_module.sql's comment on why the unique index alone isn't enough for split_leg IS NULL rows. */
    public function activeAdjustmentExists(int $debitOrderId, ?int $splitLegId, int $loanScheduleId): bool
    {
        if ($splitLegId === null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM debit_order_collection_adjustments WHERE debit_order_id = ? AND debit_order_split_leg_id IS NULL AND loan_schedule_id = ? AND status = 'ACTIVE'",
                [$debitOrderId, $loanScheduleId]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM debit_order_collection_adjustments WHERE debit_order_id = ? AND debit_order_split_leg_id = ? AND loan_schedule_id = ? AND status = 'ACTIVE'",
            [$debitOrderId, $splitLegId, $loanScheduleId]
        );
    }

    public function supersedeActiveFor(int $debitOrderId, ?int $splitLegId, int $loanScheduleId): void
    {
        if ($splitLegId === null) {
            $this->query(
                "UPDATE debit_order_collection_adjustments SET status = 'SUPERSEDED' WHERE debit_order_id = ? AND debit_order_split_leg_id IS NULL AND loan_schedule_id = ? AND status = 'ACTIVE'",
                [$debitOrderId, $loanScheduleId]
            );
            return;
        }
        $this->query(
            "UPDATE debit_order_collection_adjustments SET status = 'SUPERSEDED' WHERE debit_order_id = ? AND debit_order_split_leg_id = ? AND loan_schedule_id = ? AND status = 'ACTIVE'",
            [$debitOrderId, $splitLegId, $loanScheduleId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM debit_order_collection_adjustments WHERE id = ?", [$id]);
    }

    public function forBatch(int $batchId): array
    {
        return $this->all(
            "SELECT a.*, b.borrower_no, CONCAT(b.first_name, ' ', b.last_name) AS borrower_name, l.loan_no
             FROM debit_order_collection_adjustments a
             JOIN borrowers b ON b.id = a.borrower_id
             JOIN loan_schedules ls ON ls.id = a.loan_schedule_id
             JOIN loans l ON l.id = ls.loan_id
             WHERE a.batch_id = ?
             ORDER BY a.contractual_due_date, b.borrower_no",
            [$batchId]
        );
    }

    public function updateAdjustment(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $this->query("UPDATE debit_order_collection_adjustments SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    /**
     * Dashboard listing with filters -- Month/Employer/Policy/Adjustment
     * Reason/Approval Status/Collexia Status, per the requested screen.
     * @param array $filters keys: month (Y-m), employer, policy_id, reason (text), approval_status, collexia_status
     */
    public function filtered(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['a.status = \'ACTIVE\''];
        $params = [];

        if (!empty($filters['month'])) {
            $where[] = "DATE_FORMAT(a.contractual_due_date, '%Y-%m') = ?";
            $params[] = $filters['month'];
        }
        if (!empty($filters['employer'])) {
            $where[] = "a.employer_name = ?";
            $params[] = $filters['employer'];
        }
        if (!empty($filters['policy_id'])) {
            $where[] = "a.pay_cycle_policy_id = ?";
            $params[] = $filters['policy_id'];
        }
        if (!empty($filters['reason'])) {
            $where[] = "a.adjustment_reason LIKE ?";
            $params[] = '%' . $filters['reason'] . '%';
        }
        if (!empty($filters['approval_status'])) {
            $where[] = "batch.approval_status = ?";
            $params[] = $filters['approval_status'];
        }
        if (!empty($filters['collexia_status'])) {
            $where[] = "a.collexia_status = ?";
            $params[] = $filters['collexia_status'];
        }

        $whereSql = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT a.*, b.borrower_no, CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
                    l.loan_no, batch.approval_status, batch.id AS batch_id
             FROM debit_order_collection_adjustments a
             JOIN borrowers b ON b.id = a.borrower_id
             JOIN loan_schedules ls ON ls.id = a.loan_schedule_id
             JOIN loans l ON l.id = ls.loan_id
             JOIN collection_date_adjustment_batches batch ON batch.id = a.batch_id
             WHERE $whereSql
             ORDER BY a.contractual_due_date, b.borrower_no
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM debit_order_collection_adjustments a
             JOIN collection_date_adjustment_batches batch ON batch.id = a.batch_id
             WHERE $whereSql",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function distinctEmployersInAdjustments(): array
    {
        $rows = $this->all("SELECT DISTINCT employer_name FROM debit_order_collection_adjustments WHERE employer_name IS NOT NULL ORDER BY employer_name");
        return array_column($rows, 'employer_name');
    }
}
