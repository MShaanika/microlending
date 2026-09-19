<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CollectionDateAdjustment;
use App\Models\PayCycleSetting;

/**
 * Orchestrates the rolling preview -> maker-checker -> (future) Collexia
 * apply flow. NEVER touches loan_schedules.due_date. applyApproved() is
 * built (safety checks included) but hard-disabled -- see its own
 * docblock -- until Collexia's rescheduling cutoff/lead-time is
 * confirmed and pay_cycle_settings.automatic_apply_enabled is
 * deliberately turned on by a code change, not just a settings toggle.
 */
class CollectionDateAdjustmentService
{
    private BusinessCalendarService $calendar;
    private PayCycleResolverService $resolver;
    private CollectionDateAdjustment $adjustments;

    public function __construct(
        ?BusinessCalendarService $calendar = null,
        ?PayCycleResolverService $resolver = null,
        ?CollectionDateAdjustment $adjustments = null
    ) {
        $this->calendar = $calendar ?? new BusinessCalendarService();
        $this->resolver = $resolver ?? new PayCycleResolverService();
        $this->adjustments = $adjustments ?? new CollectionDateAdjustment();
    }

    /**
     * Computes what the adjustment WOULD be for one loan_schedules row +
     * one debit order, without writing anything. Returns null if no
     * policy/override applies, or if nothing actually needs to change
     * (the debit order's own debit_day already lands on the correct
     * date this cycle).
     */
    public function computeAdjustment(array $scheduleRow, array $debitOrder, int $loanPaymentDay): ?array
    {
        $resolved = $this->resolver->resolveFor((int) $debitOrder['borrower_id'], $loanPaymentDay);
        if ($resolved === null) {
            return null;
        }

        $dueDate = new \DateTimeImmutable($scheduleRow['due_date']);
        $normalPayDate = $this->dateInSameMonth($dueDate, $resolved['normal_pay_day']);

        $adjustedPayDate = $normalPayDate;
        $ruleApplied = $resolved['non_business_day_rule'];
        if ($ruleApplied !== 'NONE' && !$this->calendar->isBusinessDay($normalPayDate)) {
            $adjustedPayDate = $ruleApplied === 'PREVIOUS_BUSINESS_DAY'
                ? $this->calendar->previousBusinessDay($normalPayDate)
                : $this->calendar->nextBusinessDay($normalPayDate);
        }

        $proposedCollectionDate = $adjustedPayDate;

        // No-op check: does the debit order's own current debit_day
        // already land on this exact date this cycle? If so there is
        // nothing to propose.
        $naturalCollectionDate = $this->dateInSameMonth($dueDate, (int) $debitOrder['debit_day']);
        if ($naturalCollectionDate->format('Y-m-d') === $proposedCollectionDate->format('Y-m-d')) {
            return null;
        }

        $crossesPeriod = $adjustedPayDate->format('Y-m') !== $normalPayDate->format('Y-m');

        $reason = $this->buildReason($normalPayDate, $adjustedPayDate, $ruleApplied, $resolved['source']);

        return [
            'contractual_due_date' => $dueDate->format('Y-m-d'),
            'normal_pay_date' => $normalPayDate->format('Y-m-d'),
            'adjusted_pay_date' => $adjustedPayDate->format('Y-m-d'),
            'proposed_collection_date' => $proposedCollectionDate->format('Y-m-d'),
            'rule_applied' => $ruleApplied,
            'pay_cycle_policy_id' => $resolved['pay_cycle_policy_id'],
            'adjustment_reason' => $reason,
            'crosses_period_boundary' => $crossesPeriod,
            'requires_manual_flag' => $crossesPeriod ? 'CROSS_PERIOD_ADJUSTMENT' : 'NONE',
        ];
    }

    /**
     * Rolling window generation -- scans unpaid loan_schedules due within
     * $throughDate for loans with an Active debit order, computes
     * adjustments, and idempotently upserts them: an unchanged existing
     * ACTIVE row is left alone (no duplicate), a changed one is
     * superseded and replaced, nothing needed removes an existing row
     * that's no longer needed... actually leaves it (see note below).
     * Only creates a batch if there is at least one new/changed row --
     * a no-op day creates nothing at all.
     */
    public function generateRollingPreview(\DateTimeImmutable $throughDate, ?int $userId): array
    {
        $db = Database::connection();
        $today = new \DateTimeImmutable('today');

        $stmt = $db->prepare(
            "SELECT ls.id AS schedule_id, ls.due_date, ls.total_due, ls.total_paid,
                    l.id AS loan_id, l.payment_day, l.borrower_id
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE ls.due_date BETWEEN ? AND ?
               AND ls.total_paid < ls.total_due
               AND l.loan_status IN ('Active', 'Current')"
        );
        $stmt->execute([$today->format('Y-m-d'), $throughDate->format('Y-m-d')]);
        $scheduleRows = $stmt->fetchAll();

        $created = [];
        $batchId = null;

        foreach ($scheduleRows as $row) {
            $doStmt = $db->prepare("SELECT * FROM debit_orders WHERE loan_id = ? AND status = 'Active'");
            $doStmt->execute([$row['loan_id']]);
            $debitOrder = $doStmt->fetch();
            if (!$debitOrder) {
                continue;
            }

            $adjustment = $this->computeAdjustment($row, $debitOrder, (int) $row['payment_day']);
            if ($adjustment === null) {
                continue;
            }

            $legs = [null];
            if (!empty($debitOrder['split_enabled'])) {
                $legStmt = $db->prepare(
                    "SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND merged_into_id IS NULL AND collexia_api_status != 'Cancelled'"
                );
                $legStmt->execute([$debitOrder['id']]);
                $legs = array_column($legStmt->fetchAll(), 'id');
                if (empty($legs)) {
                    continue;
                }
            }

            foreach ($legs as $legId) {
                if ($this->adjustments->activeAdjustmentExists((int) $debitOrder['id'], $legId, (int) $row['schedule_id'])) {
                    // Idempotent: an identical active row already covers
                    // this cycle. Only supersede+recreate if the computed
                    // values actually differ (e.g. a holiday changed).
                    $existing = $this->findActiveAdjustment((int) $debitOrder['id'], $legId, (int) $row['schedule_id']);
                    if ($existing && $existing['proposed_collection_date'] === $adjustment['proposed_collection_date']) {
                        continue; // nothing changed -- true no-op re-run
                    }
                    $this->adjustments->supersedeActiveFor((int) $debitOrder['id'], $legId, (int) $row['schedule_id']);
                }

                if ($batchId === null) {
                    $batchId = $this->adjustments->createBatch($userId);
                }

                $id = $this->adjustments->createAdjustment(array_merge($adjustment, [
                    'batch_id' => $batchId,
                    'debit_order_id' => $debitOrder['id'],
                    'debit_order_split_leg_id' => $legId,
                    'loan_schedule_id' => $row['schedule_id'],
                    'borrower_id' => $row['borrower_id'],
                    'employer_name' => $this->currentEmployerName((int) $row['borrower_id']),
                ]));
                $created[] = $id;
            }
        }

        if ($batchId !== null) {
            Audit::log('Create', 'Collections', 'Generated collection date adjustment batch #' . $batchId . ' (' . count($created) . ' rows)');
        }

        return ['batch_id' => $batchId, 'adjustment_ids' => $created];
    }

    /** Wraps the batch through the EXISTING maker-checker framework -- no new approval mechanism. */
    public function submitForApproval(int $batchId, int $userId): void
    {
        $batch = $this->adjustments->findBatch($batchId);
        if (!$batch || $batch['approval_status'] !== 'PENDING_REVIEW') {
            throw new \RuntimeException('Only a batch still pending review can be submitted for approval.');
        }

        $rows = $this->adjustments->forBatch($batchId);
        if (empty($rows)) {
            throw new \RuntimeException('This batch has no adjustment rows to submit.');
        }

        $requestId = ApprovalService::request('collection_date_adjustment_approval', [
            'resource_id' => $batchId,
            'maker_user_id' => $userId,
            'title' => 'Collection date adjustment batch #' . $batchId . ' (' . count($rows) . ' installments)',
            'reason' => 'Employer pay-cycle-driven collection date shift, pending review.',
        ]);

        $this->adjustments->updateBatch($batchId, [
            'approval_status' => $requestId !== null ? 'SUBMITTED' : 'APPROVED', // policy inactive -> Part 41 off-switch, same as write-offs
            'approval_request_id' => $requestId,
            'submitted_by' => $userId,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Update', 'Collections', 'Submitted collection date adjustment batch #' . $batchId . ' for approval');
    }

    /** Mirrors LoanWriteOffController::approve() exactly -- ApprovalService never touches this module's own tables, this method does, in the same click. */
    public function approve(int $batchId, ?string $comments, int $userId): void
    {
        $batch = $this->adjustments->findBatch($batchId);
        if (!$batch || $batch['approval_status'] !== 'SUBMITTED') {
            throw new \RuntimeException('Only a submitted batch can be approved.');
        }

        $approvalRequest = $this->adjustments->findPendingApprovalRequestForBatch($batchId);
        if ($approvalRequest) {
            ApprovalService::approve((int) $approvalRequest['id'], $comments);
        }

        $this->adjustments->updateBatch($batchId, [
            'approval_status' => 'APPROVED',
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_comments' => $comments,
        ]);

        foreach ($this->adjustments->forBatch($batchId) as $row) {
            $this->adjustments->updateAdjustment((int) $row['id'], ['collexia_status' => 'READY']);
        }

        Audit::log('Approve', 'Collections', 'Approved collection date adjustment batch #' . $batchId);
    }

    public function reject(int $batchId, string $comments, int $userId): void
    {
        $batch = $this->adjustments->findBatch($batchId);
        if (!$batch || $batch['approval_status'] !== 'SUBMITTED') {
            throw new \RuntimeException('Only a submitted batch can be rejected.');
        }

        $approvalRequest = $this->adjustments->findPendingApprovalRequestForBatch($batchId);
        if ($approvalRequest) {
            ApprovalService::reject((int) $approvalRequest['id'], $comments);
        }

        $this->adjustments->updateBatch($batchId, [
            'approval_status' => 'REJECTED',
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_comments' => $comments,
        ]);

        Audit::log('Reject', 'Collections', 'Rejected collection date adjustment batch #' . $batchId . ': ' . $comments);
    }

    /**
     * NOT CALLED ANYWHERE YET. Deliberately disabled regardless of
     * pay_cycle_settings.automatic_apply_enabled -- that setting exists
     * so enabling this later is a visible, deliberate settings change,
     * not just a silent code path that happens to start firing. Every
     * check below is real and independently testable; only the actual
     * Collexia call at the end is stubbed out.
     *
     * Safety checks, in order, exactly as specified -- if ANY fails,
     * Collexia is never called:
     *   1. adjustment still approved (collexia_status = READY, batch still APPROVED)
     *   2. mandate still active (debit_orders.status = Active)
     *   3. installment still exists (loan_schedules row not deleted/rescheduled away)
     *   4. installment not already paid (total_paid < total_due)
     *   5. installment not cancelled (split leg, if any, not Cancelled)
     *   6. proposed date still valid (not superseded)
     *   7. proposed date not already passed (>= today)
     *   8. no newer adjustment supersedes it (status still ACTIVE)
     *   9. Collexia reference/intId available (contract reference present)
     *   10. no duplicate API request already succeeded (collexia_status != APPLIED)
     */
    public function applyApproved(int $adjustmentId): array
    {
        $settings = new PayCycleSetting();
        if (!$settings->automaticApplyEnabled()) {
            return ['applied' => false, 'reason' => 'Automatic Collexia application is disabled pending confirmation of Collexia\'s rescheduling cutoff/lead-time.'];
        }

        // Hard stop regardless of the setting above -- this phase never
        // calls Collexia. Enabling the next phase means removing this
        // return and wiring in, in order:
        //   $check = $this->revalidateBeforeApply($adjustmentId);
        //   if (!$check['ok']) { return ['applied' => false, 'reason' => $check['reason']]; }
        //   ... CollexiaEndoApiClient::installmentRequest() + updateInstallment(),
        //   exactly the pair DebitOrderCollexiaController's existing
        //   Reschedule Installment action already uses. Response gets
        //   written to debit_order_collection_adjustments.collexia_response
        //   and collexia_status flips to APPLIED or FAILED.
        return ['applied' => false, 'reason' => 'applyApproved() is disabled in this build.'];
    }

    /** The 10-point safety check, real and callable today -- only the Collexia call itself is not. */
    public function revalidateBeforeApply(int $adjustmentId): array
    {
        $adjustment = $this->adjustments->find($adjustmentId);
        if (!$adjustment) {
            return ['ok' => false, 'reason' => 'Adjustment record not found.'];
        }
        if ($adjustment['status'] !== 'ACTIVE') {
            return ['ok' => false, 'reason' => 'A newer adjustment has superseded this one.'];
        }
        if ($adjustment['collexia_status'] !== 'READY') {
            return ['ok' => false, 'reason' => 'Adjustment is not in READY state.'];
        }
        if ($adjustment['collexia_status'] === 'APPLIED') {
            return ['ok' => false, 'reason' => 'This adjustment has already been applied -- refusing to duplicate the Collexia call.'];
        }

        $batch = $this->adjustments->findBatch((int) $adjustment['batch_id']);
        if (!$batch || $batch['approval_status'] !== 'APPROVED') {
            return ['ok' => false, 'reason' => 'The parent batch is no longer in an APPROVED state.'];
        }

        $db = Database::connection();

        $doStmt = $db->prepare("SELECT status FROM debit_orders WHERE id = ?");
        $doStmt->execute([$adjustment['debit_order_id']]);
        $debitOrder = $doStmt->fetch();
        if (!$debitOrder || $debitOrder['status'] !== 'Active') {
            return ['ok' => false, 'reason' => 'The debit order/mandate is no longer Active.'];
        }

        $scheduleStmt = $db->prepare("SELECT total_due, total_paid, due_date FROM loan_schedules WHERE id = ?");
        $scheduleStmt->execute([$adjustment['loan_schedule_id']]);
        $schedule = $scheduleStmt->fetch();
        if (!$schedule) {
            return ['ok' => false, 'reason' => 'The installment no longer exists.'];
        }
        if ((float) $schedule['total_paid'] >= (float) $schedule['total_due']) {
            return ['ok' => false, 'reason' => 'The installment is already fully paid.'];
        }

        if ($adjustment['debit_order_split_leg_id'] !== null) {
            $legStmt = $db->prepare("SELECT collexia_api_status, collexia_api_contract_reference FROM debit_order_split_legs WHERE id = ?");
            $legStmt->execute([$adjustment['debit_order_split_leg_id']]);
            $leg = $legStmt->fetch();
            if (!$leg || $leg['collexia_api_status'] === 'Cancelled') {
                return ['ok' => false, 'reason' => 'This split leg has been cancelled.'];
            }
            if (empty($leg['collexia_api_contract_reference'])) {
                return ['ok' => false, 'reason' => 'No Collexia contract reference available for this leg.'];
            }
        } else {
            $refStmt = $db->prepare("SELECT collexia_api_contract_reference FROM debit_orders WHERE id = ?");
            $refStmt->execute([$adjustment['debit_order_id']]);
            $ref = $refStmt->fetchColumn();
            if (empty($ref)) {
                return ['ok' => false, 'reason' => 'No Collexia contract reference available for this mandate.'];
            }
        }

        $proposedDate = new \DateTimeImmutable($adjustment['proposed_collection_date']);
        if ($proposedDate < new \DateTimeImmutable('today')) {
            return ['ok' => false, 'reason' => 'The proposed collection date has already passed.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    private function findActiveAdjustment(int $debitOrderId, ?int $splitLegId, int $loanScheduleId): ?array
    {
        $db = Database::connection();
        if ($splitLegId === null) {
            $stmt = $db->prepare(
                "SELECT * FROM debit_order_collection_adjustments WHERE debit_order_id = ? AND debit_order_split_leg_id IS NULL AND loan_schedule_id = ? AND status = 'ACTIVE'"
            );
            $stmt->execute([$debitOrderId, $loanScheduleId]);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM debit_order_collection_adjustments WHERE debit_order_id = ? AND debit_order_split_leg_id = ? AND loan_schedule_id = ? AND status = 'ACTIVE'"
            );
            $stmt->execute([$debitOrderId, $splitLegId, $loanScheduleId]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function currentEmployerName(int $borrowerId): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT employer_name FROM borrower_employment WHERE borrower_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$borrowerId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }

    /** Same day-of-month clamping convention as LoanScheduleService::nextDueDate() -- day 31 in February resolves to 28/29, not an overflow. */
    private function dateInSameMonth(\DateTimeImmutable $anchor, int $day): \DateTimeImmutable
    {
        $daysInMonth = (int) $anchor->format('t');
        $clamped = min(max($day, 1), $daysInMonth);
        return $anchor->modify('first day of this month')->modify('+' . ($clamped - 1) . ' days');
    }

    private function buildReason(\DateTimeImmutable $normal, \DateTimeImmutable $adjusted, string $rule, string $source): string
    {
        if ($normal->format('Y-m-d') === $adjusted->format('Y-m-d')) {
            return 'Pay date falls on a business day; no shift needed.';
        }
        $dayName = $normal->format('l');
        $direction = $rule === 'PREVIOUS_BUSINESS_DAY' ? 'previous' : 'next';
        return sprintf(
            '%s %s is a %s; shifted to the %s business day (%s) per %s.',
            $normal->format('d'),
            $normal->format('F Y'),
            $dayName,
            $direction,
            $adjusted->format('d M Y'),
            $source === 'EMPLOYER_POLICY' ? 'the employer pay-cycle policy' : ($source === 'BORROWER_OVERRIDE' ? 'this borrower\'s override' : 'the assigned policy')
        );
    }
}
