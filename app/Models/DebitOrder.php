<?php

namespace App\Models;

use App\Core\Model;

class DebitOrder extends Model
{
    public function paginated(string $status = '', ?int $branchId = null): array
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
        $sql .= " ORDER BY d.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, b.id_number, b.phone, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
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

    public function remainingInstallments(int $loanId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'", [$loanId]);
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

    public function create(array $data): int
    {
        return $this->insert('debit_orders', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_orders', $data, 'id', $id);
    }
}
