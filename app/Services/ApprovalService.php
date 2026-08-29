<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Correlation;
use App\Core\Database;
use App\Core\Events;
use App\Models\ApprovalRequest;

/**
 * Generic maker-checker engine (Part 7-11) -- one reusable place a
 * sensitive action goes through review, instead of separate approval
 * logic re-implemented per module. A module calls request() at the
 * point it used to just flip a status column; the caller (not this
 * service) still owns actually applying the approved change to its own
 * records -- this service only decides WHETHER/WHO may approve, never
 * touches loan/payment/journal data itself.
 *
 * The maker != checker rule is enforced here unconditionally -- there
 * is no configuration that turns it off. What IS configurable
 * (approval_policies.is_active) is whether a workflow requires approval
 * at all; see request()'s null-return contract below.
 */
class ApprovalService
{
    /**
     * Creates a pending approval request if an active policy exists for
     * $policyKey. Returns null -- deliberately not an exception -- when no
     * active policy is configured, so the calling module's own existing
     * single-permission check remains the whole story for that action.
     * This is the staged-rollout "off switch" (Part 41): disabling a
     * policy removes the extra dual-control gate without touching code.
     *
     * @param array $data resource_id, maker_user_id, title, amount (optional), reason (optional), metadata (optional array)
     */
    public static function request(string $policyKey, array $data): ?int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM approval_policies WHERE policy_key = ? AND is_active = 1');
        $stmt->execute([$policyKey]);
        $policy = $stmt->fetch();
        if (!$policy) {
            return null;
        }

        $model = new ApprovalRequest();
        $requestId = $model->create([
            'approval_uuid' => self::uuid(),
            'correlation_id' => Correlation::id(),
            'policy_id' => $policy['id'],
            'module' => $policy['module'],
            'resource_type' => $policy['resource_type'],
            'resource_id' => $data['resource_id'],
            'action_type' => $policy['action_type'],
            'maker_user_id' => $data['maker_user_id'],
            'required_steps' => $policy['required_steps'],
            'title' => $data['title'],
            'amount' => $data['amount'] ?? null,
            'reason' => $data['reason'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);

        $model->createStep($requestId, 1, $policy['approver_permission']);
        $model->logAction($requestId, null, 'SUBMITTED', $data['maker_user_id'], null, $data['reason'] ?? null);

        // Starts an SLA clock for this request if (and only if) an admin
        // has configured an active 'approval_request_review' policy --
        // SlaService::start() returns null otherwise, so approvals work
        // identically whether or not SLA tracking has been set up yet.
        SlaService::start('approval_request_review', 'approval_request', $requestId);

        Events::fire('ApprovalRequested', ['approval_request_id' => $requestId, 'policy_key' => $policyKey]);

        return $requestId;
    }

    /**
     * @throws \RuntimeException if the request isn't pending, the caller is
     *         the maker, or the caller holds neither the required
     *         permission nor an active delegation granting it.
     * @return array{status: 'PENDING'|'APPROVED', delegation: ?array}
     */
    public static function approve(int $requestId, ?string $comments = null): array
    {
        [$checkerUserId, $model, $request, $step] = self::beginAction($requestId, 'approve');

        $delegation = self::authorizeStep($checkerUserId, $step, $request);

        $model->markStepApproved($step['id'], $checkerUserId, $delegation['id'] ?? null, $comments);
        $stillPending = $model->hasPendingSteps($requestId);

        if ($stillPending) {
            $model->advanceStep($requestId);
        } else {
            $model->updateStatus($requestId, 'APPROVED');
        }

        $model->logAction($requestId, $step['id'], 'APPROVED', $checkerUserId, $delegation['id'] ?? null, $comments);

        $description = $delegation
            ? sprintf('Approved request #%d acting under delegated authority from %s', $requestId, $delegation['delegator_name'])
            : sprintf('Approved request #%d (%s)', $requestId, $request['title']);
        Audit::log('Approve', 'Governance', $description, ['approval_request_id' => $requestId]);

        if (!$stillPending) {
            SlaService::completeForResource('approval_request', $requestId);
            Events::fire('ApprovalCompleted', ['approval_request_id' => $requestId, 'status' => 'APPROVED']);
        }

        return ['status' => $stillPending ? 'PENDING' : 'APPROVED', 'delegation' => $delegation];
    }

    /** @throws \RuntimeException same conditions as approve(); comments are required (Part 11: "Require comments for rejection"). */
    public static function reject(int $requestId, string $comments): void
    {
        if (trim($comments) === '') {
            throw new \RuntimeException('A reason is required to reject a request.');
        }
        [$checkerUserId, $model, $request, $step] = self::beginAction($requestId, 'reject');
        $delegation = self::authorizeStep($checkerUserId, $step, $request);

        $model->markStepRejected($step['id'], $checkerUserId, $comments);
        $model->updateStatus($requestId, 'REJECTED');
        $model->logAction($requestId, $step['id'], 'REJECTED', $checkerUserId, $delegation['id'] ?? null, $comments);

        Audit::log('Reject', 'Governance', sprintf('Rejected request #%d (%s): %s', $requestId, $request['title'], $comments), ['approval_request_id' => $requestId]);
        SlaService::completeForResource('approval_request', $requestId);
        Events::fire('ApprovalCompleted', ['approval_request_id' => $requestId, 'status' => 'REJECTED']);
    }

    /** Sends the request back to the maker for correction rather than a final rejection -- the maker's module decides whether/how resubmission works. */
    public static function returnForCorrection(int $requestId, string $comments): void
    {
        if (trim($comments) === '') {
            throw new \RuntimeException('A reason is required to return a request for correction.');
        }
        [$checkerUserId, $model, $request, $step] = self::beginAction($requestId, 'return');
        $delegation = self::authorizeStep($checkerUserId, $step, $request);

        $model->markStepReturned($step['id'], $checkerUserId, $comments);
        $model->updateStatus($requestId, 'RETURNED');
        $model->logAction($requestId, $step['id'], 'RETURNED', $checkerUserId, $delegation['id'] ?? null, $comments);

        Audit::log('Return', 'Governance', sprintf('Returned request #%d (%s) for correction: %s', $requestId, $request['title'], $comments), ['approval_request_id' => $requestId]);
        SlaService::cancelForResource('approval_request', $requestId, 'Returned for correction');
        Events::fire('ApprovalCompleted', ['approval_request_id' => $requestId, 'status' => 'RETURNED']);
    }

    /** @return array{0:int,1:ApprovalRequest,2:array,3:array} */
    private static function beginAction(int $requestId, string $verb): array
    {
        $checkerUserId = (int) (Auth::user()['id'] ?? 0);
        $model = new ApprovalRequest();
        $request = $model->find($requestId);
        if (!$request || $request['status'] !== 'PENDING') {
            throw new \RuntimeException('This request is not awaiting approval.');
        }
        if ((int) $request['maker_user_id'] === $checkerUserId) {
            throw new \RuntimeException("You cannot $verb your own request.");
        }
        $step = $model->currentStep($requestId);
        if (!$step) {
            throw new \RuntimeException('No open approval step found for this request.');
        }
        return [$checkerUserId, $model, $request, $step];
    }

    private static function authorizeStep(int $checkerUserId, array $step, array $request): ?array
    {
        if (Auth::can($step['approver_permission'])) {
            return null; // authorized directly, no delegation involved
        }
        $delegation = DelegationService::activeDelegationGranting($checkerUserId, $step['approver_permission'], (float) ($request['amount'] ?? 0));
        if (!$delegation) {
            throw new \RuntimeException('You are not authorized to act on this request.');
        }
        return $delegation;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
