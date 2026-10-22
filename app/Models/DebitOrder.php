<?php

namespace App\Models;

use App\Core\Model;

class DebitOrder extends Model
{
    /** $fromDate/$toDate filter on created_at (when the debit order was registered), inclusive on both ends. */
    public function paginated(string $status = '', ?int $branchId = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $sql = "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM debit_orders d
                JOIN loans l ON l.id = d.loan_id
                JOIN borrowers b ON b.id = d.borrower_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND d.status = ?";
            $params[] = $status;
        }
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        if ($fromDate !== null) {
            $sql .= " AND d.created_at >= ?";
            $params[] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== null) {
            $sql .= " AND d.created_at <= ?";
            $params[] = $toDate . ' 23:59:59';
        }
        $sql .= " ORDER BY d.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, b.id_number, b.phone, b.borrower_no, b.loan_ref_no AS borrower_loan_ref_no, b.date_of_birth, b.gender, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_orders d
             JOIN loans l ON l.id = d.loan_id
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public function forLoan(int $loanId): array
    {
        return $this->all("SELECT * FROM debit_orders WHERE loan_id = ? ORDER BY id DESC", [$loanId]);
    }

    /**
     * A live (not Cancelled/Completed) debit order already on this loan --
     * Collexia registers every 'Active' mandate independently and collects
     * it every period on its own (see unregistered()'s docblock), so a
     * second one on the same loan means the client gets deducted twice,
     * not a harmless duplicate. 'Suspended' counts as live too: it's meant
     * to be resumed, not superseded by a fresh registration.
     */
    public function liveForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT * FROM debit_orders WHERE loan_id = ? AND status IN ('Active', 'Suspended') ORDER BY id DESC LIMIT 1",
            [$loanId]
        );
    }

    /**
     * Active mandates on this branch's loans that have never been
     * registered with Collexia yet -- registration is a one-time EnDo Batch
     * submission per contract (Collexia then collects every period on its
     * own), so this is not month-scoped the way the old bank-CSV workflow
     * was.
     */
    public function unregistered(int $branchId): array
    {
        return $this->all(
            "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, l.payment_day,
                    b.first_name, b.last_name, b.id_number, b.phone,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_orders d
             JOIN loans l ON l.id = d.loan_id
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.status = 'Active'
               AND d.collexia_status = 'Not Registered'
               AND l.loan_status IN ('Active', 'Current')
               AND l.branch_id = ?
               -- Defense in depth against a duplicate Active mandate on the
               -- same loan (DebitOrderController::store() now blocks
               -- creating one, but this guards any pre-existing bad data
               -- too): only ever submit the most recent Active row per
               -- loan to Collexia, never two.
               AND d.id = (SELECT MAX(d2.id) FROM debit_orders d2 WHERE d2.loan_id = d.loan_id AND d2.status = 'Active')
             ORDER BY d.debit_day, d.id",
            [$branchId]
        );
    }

    public function findByContractNo(string $contractNo): ?array
    {
        return $this->one(
            "SELECT d.*, l.loan_no FROM debit_orders d JOIN loans l ON l.id = d.loan_id WHERE d.merchant_system_contract_no = ?",
            [$contractNo]
        );
    }

    /** A debit order whose mandate is actually live at Collexia (placed, or being placed), as opposed to one only recorded here. */
    private const LIVE_MANDATE_SQL = "d.status IN ('Active', 'Suspended') AND (d.collexia_status = 'Registered' OR d.collexia_api_status IN ('Registered', 'Load Pending', 'Mixed', 'Uncertain'))";

    /** The loan's debit order whose mandate is live at Collexia, if any -- what a reschedule would leave out of step. */
    public function liveMandateForLoan(int $loanId): ?array
    {
        return $this->one("SELECT d.* FROM debit_orders d WHERE d.loan_id = ? AND " . self::LIVE_MANDATE_SQL . " ORDER BY d.id DESC LIMIT 1", [$loanId]);
    }

    /**
     * The loan's live mandate that no longer matches its loan because the loan was rescheduled after the mandate was placed. Stays
     * returned -- so the red notice stays -- until the debit order is cancelled/replaced or completes, because only then does it stop
     * being live.
     */
    public function outOfStepForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT d.*, r.reschedule_no, r.implemented_at AS rescheduled_at
             FROM debit_orders d
             LEFT JOIN loan_reschedules r ON r.id = d.mandate_stale_reschedule_id
             WHERE d.loan_id = ? AND d.mandate_stale_since IS NOT NULL AND " . self::LIVE_MANDATE_SQL . "
             ORDER BY d.id DESC LIMIT 1",
            [$loanId]
        );
    }

    /** Every out-of-step live mandate (this branch, or all if null) -- for the warning on the Debit Orders list. */
    public function outOfStepAll(?int $branchId = null): array
    {
        $sql = "SELECT d.id, d.debit_order_no, d.loan_id, l.loan_no, d.mandate_stale_since
                FROM debit_orders d JOIN loans l ON l.id = d.loan_id
                WHERE d.mandate_stale_since IS NOT NULL AND " . self::LIVE_MANDATE_SQL;
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        return $this->all($sql . " ORDER BY d.mandate_stale_since", $params);
    }

    /** Marks every live mandate of the loan as out of step with it. Returns the debit orders that were marked. */
    public function markMandatesOutOfStep(int $loanId, int $rescheduleId, string $note): array
    {
        $rows = $this->all("SELECT d.* FROM debit_orders d WHERE d.loan_id = ? AND " . self::LIVE_MANDATE_SQL, [$loanId]);
        foreach ($rows as $row) {
            $this->query(
                "UPDATE debit_orders SET mandate_stale_since = NOW(), mandate_stale_reschedule_id = ?, mandate_stale_note = ? WHERE id = ?",
                [$rescheduleId, substr($note, 0, 255), (int) $row['id']]
            );
        }
        return $rows;
    }

    public function remainingInstallments(int $loanId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'", [$loanId]);
    }

    /**
     * Ordered ids of this loan's not-yet-paid schedule rows, oldest first --
     * the same set/order remainingInstallments() counts. Used to snapshot
     * DebitOrderInstallmentTarget when placing a split mandate, so each of
     * Collexia's 1..N installment sequence numbers maps to a specific
     * loan_schedules row rather than being guessed later from FIFO.
     */
    public function orderedUnpaidScheduleIds(int $loanId): array
    {
        return array_column(
            $this->all("SELECT id FROM loan_schedules WHERE loan_id = ? AND status != 'Paid' ORDER BY installment_no", [$loanId]),
            'id'
        );
    }

    /**
     * Every installment still owing money on this loan, oldest first, with
     * what's actually outstanding on each -- what the "one mandate per
     * scheduled installment" registration form lists and places. Skips Paid
     * rows and any row with nothing left to collect.
     */
    public function unpaidInstallments(int $loanId): array
    {
        return $this->all(
            "SELECT id, installment_no, due_date, status, ROUND(total_due - total_paid, 2) AS outstanding
             FROM loan_schedules
             WHERE loan_id = ? AND status != 'Paid' AND (total_due - total_paid) > 0
             ORDER BY installment_no",
            [$loanId]
        );
    }

    /**
     * Installments already settled in full (by hand, before any debit order) that are still dated in the future -- the ones a client may
     * want sent to Collexia anyway so the mandate follows the whole term, and then cancelled automatically (CollexiaPaidInstallmentCanceller).
     * A past-dated one is left out: Collexia rejects a collection date that is not in the future, so it could not be placed at all.
     */
    public function paidAheadInstallments(int $loanId): array
    {
        return $this->all(
            "SELECT id, installment_no, due_date, status, ROUND(total_due, 2) AS amount
             FROM loan_schedules
             WHERE loan_id = ? AND status = 'Paid' AND total_due > 0 AND total_paid >= total_due - 0.005 AND due_date >= ?
             ORDER BY installment_no",
            [$loanId, date('Y-m-d', strtotime('+1 day'))]
        );
    }

    public function nextCollectionDate(int $loanId): ?string
    {
        $date = $this->scalar("SELECT MIN(due_date) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'", [$loanId]);
        return $date ?: null;
    }

    public function markRegistered(int $id): bool
    {
        return $this->update('debit_orders', ['collexia_status' => 'Registered'], 'id', $id);
    }

    /**
     * Records the outcome of an EnDO V3 API call (placeMandate/checkFinalFate/
     * syncStatus/cancelMandate) -- separate from collexia_status/
     * merchant_system_contract_no, which belong to the older Excel batch flow.
     */
    public function updateCollexiaApiState(int $id, array $data): bool
    {
        return $this->update('debit_orders', $data, 'id', $id);
    }

    public function findByCollexiaApiContractReference(string $contractReference): ?array
    {
        return $this->one("SELECT * FROM debit_orders WHERE collexia_api_contract_reference = ?", [$contractReference]);
    }

    /**
     * Debit orders whose local status could still change based on what
     * Collexia reports -- status is still Active/Suspended (Cancelled and
     * Completed are terminal locally) and there's actually a mandate to ask
     * about (a non-split contract reference, or split_enabled, where
     * CollexiaMandateStatusSyncService looks at the individual splits and
     * safely no-ops if none are live yet). Feeds
     * bin/sync_collexia_mandate_status.php -- the scheduled equivalent of
     * clicking "Sync Status" on every eligible debit order.
     */
    /**
     * Also re-checks a JUST-cancelled debit order (status='Cancelled' AND
     * collexia_api_synced_at within the last 3 days) -- found 2026-10-18
     * auditing debit order #3 (Helenna Nashinwe): once status flips to
     * 'Cancelled' it used to drop out of this sweep forever, so DesertLedger
     * trusted Collexia's /mandates/cancel acknowledgment as proof the
     * mandate was actually gone and never looked again. That ack only
     * confirms Collexia received the request, not that it finished
     * processing it (same async-batch pattern as mandate placement). The
     * 3-day window is enough for the normal multi-times-a-day cron cadence
     * to catch a delayed failure while it's still actionable, without
     * polling every long-settled cancellation forever.
     */
    public function dueForCollexiaStatusSync(): array
    {
        return $this->all(
            "SELECT * FROM debit_orders
             WHERE (
                    status IN ('Active', 'Suspended')
                 OR (status = 'Cancelled' AND collexia_api_synced_at >= DATE_SUB(NOW(), INTERVAL 3 DAY))
               )
               AND (collexia_api_contract_reference IS NOT NULL OR split_enabled = 1)
             ORDER BY id"
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_orders', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_orders', $data, 'id', $id);
    }

    /**
     * A mandate can only be edited locally while nothing has actually been
     * placed with Collexia yet -- once it's live (batch-registered via
     * collexia_status, or API-registered/pending via collexia_api_status),
     * changing bank/amount/day here would silently desync this record from
     * what Collexia is really collecting. Amount/date changes on an
     * already-placed API mandate go through the real reschedule flow
     * (DebitOrderCollexiaController::installments/updateInstallment)
     * instead; bank/account changes on an already-placed mandate require
     * cancelling and re-registering.
     */
    public static function isEditable(array $debitOrder): bool
    {
        if ($debitOrder['collexia_status'] !== 'Not Registered') {
            return false;
        }
        return in_array($debitOrder['collexia_api_status'] ?? 'Not Placed', ['Not Placed', 'Load Failed'], true);
    }

    /** Active loans (this branch, or all if null) that have never had a debit order registered at all. */
    public function activeLoansMissingDebitOrder(?int $branchId = null): array
    {
        $sql = "SELECT l.id, l.loan_no, l.branch_id, l.payment_day, l.installment_amount, l.loan_status,
                       CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.phone
                FROM loans l
                JOIN borrowers b ON b.id = l.borrower_id
                LEFT JOIN debit_orders d ON d.loan_id = l.id
                WHERE l.loan_status IN ('Active', 'Current')
                  AND d.id IS NULL";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        $sql .= " ORDER BY l.id DESC";
        return $this->all($sql, $params);
    }

    public function countActiveLoansMissingDebitOrder(?int $branchId = null): int
    {
        $sql = "SELECT COUNT(*) FROM loans l
                LEFT JOIN debit_orders d ON d.loan_id = l.id
                WHERE l.loan_status IN ('Active', 'Current') AND d.id IS NULL";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        return (int) $this->scalar($sql, $params);
    }

    /** Distinct borrowers currently holding at least one Active debit order mandate. */
    public function countBorrowersWithActiveDebitOrder(?int $branchId = null): int
    {
        $sql = "SELECT COUNT(DISTINCT d.borrower_id) FROM debit_orders d
                JOIN loans l ON l.id = d.loan_id
                WHERE d.status = 'Active'";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        return (int) $this->scalar($sql, $params);
    }
}
