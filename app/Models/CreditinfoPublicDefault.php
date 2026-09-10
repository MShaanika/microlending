<?php

namespace App\Models;

use App\Core\Model;

/**
 * One record per default's full lifecycle (listing, then later removal) --
 * see database/creditinfo_public_defaults_module.sql for the merged status
 * vocabulary. Every field here is an internal DesertLedger field; none of
 * it should be treated as a Creditinfo API field until the vendor
 * specification arrives (see App\Services\CreditinfoPublicDefaultsClient).
 */
class CreditinfoPublicDefault extends Model
{
    private const LISTING_ACTIVE_STATUSES = [
        'Draft', 'Pending Review', 'Approved', 'Awaiting API Submission', 'Submitted',
    ];

    private const ACTIVE_STATUSES = [
        'Listed', 'Removal Required', 'Removal Pending Review', 'Removal Approved',
        'Awaiting API Removal', 'Removal Submitted',
    ];

    private const HISTORY_STATUSES = [
        'Rejected', 'Failed', 'Cancelled', 'Removed', 'Removal Rejected', 'Removal Failed',
    ];

    private const REMOVAL_QUEUE_STATUSES = [
        'Removal Pending Review', 'Removal Approved', 'Awaiting API Removal', 'Removal Submitted',
    ];

    public function create(array $data): int
    {
        return $this->insert('creditinfo_public_defaults', $data);
    }

    private function baseSelect(): string
    {
        return "SELECT pd.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.borrower_no, b.id_number,
                       l.loan_no, br.branch_name,
                       ru.name AS listing_requested_by_name, au.name AS listing_approved_by_name,
                       rru.name AS removal_requested_by_name, rau.name AS removal_approved_by_name
                FROM creditinfo_public_defaults pd
                JOIN borrowers b ON b.id = pd.borrower_id
                JOIN loans l ON l.id = pd.loan_id
                LEFT JOIN branches br ON br.id = pd.branch_id
                LEFT JOIN users ru ON ru.id = pd.listing_requested_by
                LEFT JOIN users au ON au.id = pd.listing_approved_by
                LEFT JOIN users rru ON rru.id = pd.removal_requested_by
                LEFT JOIN users rau ON rau.id = pd.removal_approved_by";
    }

    public function find(int $id): ?array
    {
        return $this->one($this->baseSelect() . " WHERE pd.id = ?", [$id]);
    }

    public function findByReference(string $reference): ?array
    {
        return $this->one($this->baseSelect() . " WHERE pd.listing_reference = ?", [$reference]);
    }

    /** Row lock for a status-guarded write inside a transaction -- see Model::transaction(). */
    public function findForUpdate(int $id): ?array
    {
        return $this->one("SELECT * FROM creditinfo_public_defaults WHERE id = ? FOR UPDATE", [$id]);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('creditinfo_public_defaults', $data, 'id', $id);
    }

    /** True if this loan already has a listing request in flight or an active listing -- never allow a second, parallel one. */
    public function hasActiveForLoan(int $loanId): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::LISTING_ACTIVE_STATUSES) + count(self::ACTIVE_STATUSES), '?'));
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM creditinfo_public_defaults WHERE loan_id = ? AND status IN ($placeholders)",
            array_merge([$loanId], self::LISTING_ACTIVE_STATUSES, self::ACTIVE_STATUSES)
        );
    }

    public function listingQueue(?string $status, ?int $branchId, int $page = 1, int $perPage = 25): array
    {
        $statuses = $status !== null && $status !== '' ? [$status] : self::LISTING_ACTIVE_STATUSES;
        return $this->filteredPage($statuses, $branchId, null, null, null, $page, $perPage, 'pd.listing_requested_at DESC');
    }

    public function removalQueue(?string $status, ?int $branchId, int $page = 1, int $perPage = 25): array
    {
        $statuses = $status !== null && $status !== '' ? [$status] : self::REMOVAL_QUEUE_STATUSES;
        return $this->filteredPage($statuses, $branchId, null, null, null, $page, $perPage, 'pd.removal_requested_at DESC');
    }

    /** Item 10: Active Public Defaults Register. */
    public function activeRegister(array $filters, int $page = 1, int $perPage = 25): array
    {
        $statuses = !empty($filters['status']) ? [$filters['status']] : self::ACTIVE_STATUSES;
        return $this->filteredPage(
            $statuses,
            $filters['branch_id'] ?? null,
            $filters['borrower_id'] ?? null,
            $filters['loan_id'] ?? null,
            $filters,
            $page,
            $perPage,
            'pd.listed_at DESC'
        );
    }

    /** Item 3: completed lifecycles -- Removed, Rejected, Failed, Cancelled. */
    public function history(array $filters, int $page = 1, int $perPage = 25): array
    {
        $statuses = !empty($filters['status']) ? [$filters['status']] : self::HISTORY_STATUSES;
        return $this->filteredPage(
            $statuses,
            $filters['branch_id'] ?? null,
            $filters['borrower_id'] ?? null,
            $filters['loan_id'] ?? null,
            $filters,
            $page,
            $perPage,
            'pd.updated_at DESC'
        );
    }

    private function filteredPage(array $statuses, ?int $branchId, ?int $borrowerId, ?int $loanId, ?array $filters, int $page, int $perPage, string $orderBy): array
    {
        $where = ['pd.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
        $params = $statuses;

        if ($branchId) {
            $where[] = 'pd.branch_id = ?';
            $params[] = $branchId;
        }
        if ($borrowerId) {
            $where[] = 'pd.borrower_id = ?';
            $params[] = $borrowerId;
        }
        if ($loanId) {
            $where[] = 'pd.loan_id = ?';
            $params[] = $loanId;
        }
        // isset()+!== '' rather than !empty() throughout below -- a
        // legitimate filter value of 0 (e.g. "outstanding balance already
        // at zero") must not be treated as "no filter given".
        if (isset($filters['amount_min']) && $filters['amount_min'] !== '') {
            $where[] = 'pd.outstanding_amount >= ?';
            $params[] = (float) $filters['amount_min'];
        }
        if (isset($filters['amount_max']) && $filters['amount_max'] !== '') {
            $where[] = 'pd.outstanding_amount <= ?';
            $params[] = (float) $filters['amount_max'];
        }
        if (isset($filters['days_in_arrears_min']) && $filters['days_in_arrears_min'] !== '') {
            $where[] = 'pd.days_in_arrears_at_listing >= ?';
            $params[] = (int) $filters['days_in_arrears_min'];
        }
        if (!empty($filters['listed_from'])) {
            $where[] = 'pd.listed_at >= ?';
            $params[] = $filters['listed_from'] . ' 00:00:00';
        }
        if (!empty($filters['listed_to'])) {
            $where[] = 'pd.listed_at <= ?';
            $params[] = $filters['listed_to'] . ' 23:59:59';
        }
        if (!empty($filters['removed_from'])) {
            $where[] = 'pd.removed_at >= ?';
            $params[] = $filters['removed_from'] . ' 00:00:00';
        }
        if (!empty($filters['removed_to'])) {
            $where[] = 'pd.removed_at <= ?';
            $params[] = $filters['removed_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM creditinfo_public_defaults pd WHERE $whereSql", $params);
        $rows = $this->all(
            $this->baseSelect() . " WHERE $whereSql ORDER BY $orderBy LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Item 16: Public Default Compliance dashboard counts. */
    public function complianceCounts(): array
    {
        $counts = [];
        $statusGroups = [
            'draft' => ['Draft'],
            'pending_approval' => ['Pending Review'],
            'approved_awaiting_submission' => ['Approved', 'Awaiting API Submission'],
            'active_defaults' => self::ACTIVE_STATUSES,
            'removal_required' => ['Removal Required'],
            // Derived from the same constant removalQueue() itself uses --
            // never a manually retyped subset, so the two can't drift apart.
            'removal_pending' => self::REMOVAL_QUEUE_STATUSES,
            'api_failures' => ['Failed', 'Removal Failed'],
        ];
        foreach ($statusGroups as $key => $statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $counts[$key] = (int) $this->scalar(
                "SELECT COUNT(*) FROM creditinfo_public_defaults WHERE status IN ($placeholders)",
                $statuses
            );
        }
        return $counts;
    }

    /**
     * Item 11: loans that are fully settled (loan_status = 'Completed') but
     * still carry a 'Listed' public default -- i.e. still need to be
     * DETECTED and flipped to 'Removal Required'. Only ever called from
     * CreditinfoPublicDefaultController::syncSettlementTriggers(), which
     * consumes these rows by updating their status; once flipped, a row no
     * longer matches this query. Never use this to POPULATE a dashboard
     * banner in the same request that just called syncSettlementTriggers()
     * -- see removalRequired() below for that.
     */
    public function settlementRemovalCandidates(): array
    {
        return $this->all(
            $this->baseSelect() . "
             WHERE pd.status = 'Listed'
               AND EXISTS (SELECT 1 FROM loans lx WHERE lx.id = pd.loan_id AND lx.loan_status = 'Completed')
             ORDER BY pd.listed_at ASC"
        );
    }

    /** Rows already flagged 'Removal Required' (by settlementRemovalCandidates() having run, this request or an earlier one) -- what the dashboard's banner should actually display. */
    public function removalRequired(): array
    {
        return $this->all(
            $this->baseSelect() . " WHERE pd.status = 'Removal Required' ORDER BY pd.updated_at ASC"
        );
    }

    /** Item 15: transactions this calendar month, by the action that actually happened (Listed / Removed), for cost estimation -- counts state, never re-derives it from api logs. */
    public function transactionCountThisMonth(string $column): int
    {
        $allowed = ['listed_at', 'removed_at'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown transaction column.');
        }
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM creditinfo_public_defaults WHERE $column IS NOT NULL AND $column >= ? AND $column < ?",
            [date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00', strtotime('+1 month'))]
        );
    }
}
