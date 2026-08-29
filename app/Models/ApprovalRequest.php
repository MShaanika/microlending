<?php

namespace App\Models;

use App\Core\Model;

/** Data access for approval_requests/approval_steps/approval_actions. Business rules (maker != checker, delegation lookup) live in App\Services\ApprovalService -- this model is deliberately thin. */
class ApprovalRequest extends Model
{
    public function create(array $data): int
    {
        return $this->insert('approval_requests', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, u.name AS maker_name, p.policy_name
             FROM approval_requests r
             JOIN users u ON u.id = r.maker_user_id
             JOIN approval_policies p ON p.id = r.policy_id
             WHERE r.id = ?",
            [$id]
        );
    }

    /** The one open (Open/Pending) request for a resource, if any -- used to detect "was this submitted under an active policy" without the calling module tracking its own approval_request_id column. */
    public function findPendingByResource(string $module, string $resourceType, int $resourceId): ?array
    {
        return $this->one(
            "SELECT * FROM approval_requests WHERE module = ? AND resource_type = ? AND resource_id = ? AND status = 'PENDING' ORDER BY id DESC LIMIT 1",
            [$module, $resourceType, $resourceId]
        );
    }

    public function createStep(int $requestId, int $stepNumber, string $approverPermission): int
    {
        return $this->insert('approval_steps', [
            'approval_request_id' => $requestId,
            'step_number' => $stepNumber,
            'approver_permission' => $approverPermission,
        ]);
    }

    public function currentStep(int $requestId): ?array
    {
        return $this->one(
            "SELECT s.* FROM approval_steps s
             JOIN approval_requests r ON r.id = s.approval_request_id
             WHERE s.approval_request_id = ? AND s.step_number = r.current_step AND s.status = 'PENDING'
             LIMIT 1",
            [$requestId]
        );
    }

    public function markStepApproved(int $stepId, int $userId, ?int $delegationId, ?string $comments): void
    {
        $this->update('approval_steps', [
            'status' => 'APPROVED',
            'acted_by' => $userId,
            'acted_via_delegation_id' => $delegationId,
            'acted_at' => date('Y-m-d H:i:s'),
            'comments' => $comments,
        ], 'id', $stepId);
    }

    public function markStepRejected(int $stepId, int $userId, string $comments): void
    {
        $this->update('approval_steps', [
            'status' => 'REJECTED',
            'acted_by' => $userId,
            'acted_at' => date('Y-m-d H:i:s'),
            'comments' => $comments,
        ], 'id', $stepId);
    }

    public function markStepReturned(int $stepId, int $userId, string $comments): void
    {
        $this->update('approval_steps', [
            'status' => 'RETURNED',
            'acted_by' => $userId,
            'acted_at' => date('Y-m-d H:i:s'),
            'comments' => $comments,
        ], 'id', $stepId);
    }

    public function hasPendingSteps(int $requestId): bool
    {
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM approval_steps WHERE approval_request_id = ? AND status = 'PENDING'",
            [$requestId]
        );
    }

    public function advanceStep(int $requestId): void
    {
        $this->query("UPDATE approval_requests SET current_step = current_step + 1 WHERE id = ?", [$requestId]);
    }

    public function updateStatus(int $requestId, string $status): void
    {
        $data = ['status' => $status];
        if (in_array($status, ['APPROVED', 'REJECTED', 'RETURNED', 'CANCELLED'], true)) {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        $this->update('approval_requests', $data, 'id', $requestId);
    }

    public function logAction(int $requestId, ?int $stepId, string $action, ?int $actorUserId, ?int $delegationId, ?string $comments): void
    {
        $this->insert('approval_actions', [
            'approval_request_id' => $requestId,
            'approval_step_id' => $stepId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'acted_via_delegation_id' => $delegationId,
            'comments' => $comments,
        ]);
    }

    public function timeline(int $requestId): array
    {
        return $this->all(
            "SELECT a.*, u.name AS actor_name, d.delegator_user_id, du.name AS delegator_name
             FROM approval_actions a
             LEFT JOIN users u ON u.id = a.actor_user_id
             LEFT JOIN delegations d ON d.id = a.acted_via_delegation_id
             LEFT JOIN users du ON du.id = d.delegator_user_id
             WHERE a.approval_request_id = ? ORDER BY a.created_at ASC",
            [$requestId]
        );
    }

    /** Requests awaiting a step this user is eligible to act on (by direct permission) -- delegation-eligible ones are added separately by the caller, since that requires per-row permission lookups the DB can't do generically. */
    public function pendingForPermissions(array $permissionKeys, int $excludeMakerId, int $page = 1, int $perPage = 25): array
    {
        if (empty($permissionKeys)) {
            return ['rows' => [], 'total' => 0, 'totalPages' => 1];
        }
        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        $params = array_merge($permissionKeys, [$excludeMakerId]);

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM approval_requests r
             JOIN approval_steps s ON s.approval_request_id = r.id AND s.step_number = r.current_step
             WHERE r.status = 'PENDING' AND s.approver_permission IN ($placeholders) AND r.maker_user_id != ?",
            $params
        );
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT r.*, u.name AS maker_name, p.policy_name
             FROM approval_requests r
             JOIN approval_steps s ON s.approval_request_id = r.id AND s.step_number = r.current_step
             JOIN users u ON u.id = r.maker_user_id
             JOIN approval_policies p ON p.id = r.policy_id
             WHERE r.status = 'PENDING' AND s.approver_permission IN ($placeholders) AND r.maker_user_id != ?
             ORDER BY r.requested_at ASC LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function submittedByUser(int $userId, int $page = 1, int $perPage = 25): array
    {
        $total = (int) $this->scalar("SELECT COUNT(*) FROM approval_requests WHERE maker_user_id = ?", [$userId]);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT r.*, p.policy_name FROM approval_requests r
             JOIN approval_policies p ON p.id = r.policy_id
             WHERE r.maker_user_id = ? ORDER BY r.requested_at DESC LIMIT $perPage OFFSET $offset",
            [$userId]
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }
}
