<?php

namespace App\Services;

use App\Core\Audit;
use App\Models\DebitOrder;
use App\Models\Loan;

/**
 * Step one of replacing a mandate that no longer matches its loan (typically after a reschedule): cancel the old debit order at
 * Collexia so a new one can be registered from the loan's current schedule.
 *
 * Deliberately NOT automatic -- it runs only when a person confirms it on the Replace page, with a written reason. Everything it does
 * is recorded twice, so the loan's history answers "what happened to its debit order and why": an audit entry carrying the loan, debit
 * order and reschedule ids, and a line in the loan's own status history.
 *
 * The cancel itself is the existing, already-live CollexiaMandateCancellationService (the same code as the Cancel Mandate button), so
 * a split debit order is cancelled leg by leg and a partial failure is reported as such rather than hidden.
 */
class MandateReplacementService
{
    /** @var callable fn(array $debitOrder): array{success: bool, message: string, attempted: bool} */
    private $canceller;
    private DebitOrder $debitOrders;
    private Loan $loans;

    public function __construct(?callable $canceller = null, ?DebitOrder $debitOrders = null, ?Loan $loans = null)
    {
        $this->canceller = $canceller ?? fn (array $d) => (new CollexiaMandateCancellationService())->cancel($d);
        $this->debitOrders = $debitOrders ?? new DebitOrder();
        $this->loans = $loans ?? new Loan();
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancelForReplacement(array $debitOrder, string $reason, ?int $userId): array
    {
        $reason = trim($reason);
        $id = (int) $debitOrder['id'];
        $loan = $this->loans->find((int) $debitOrder['loan_id']);
        if (!$loan) {
            return ['success' => false, 'message' => 'The loan for this debit order was not found.'];
        }
        if (!in_array($debitOrder['status'], ['Active', 'Suspended'], true)) {
            return ['success' => false, 'message' => 'This debit order is already ' . strtolower((string) $debitOrder['status']) . ' -- there is nothing to replace.'];
        }

        $context = [
            'loan_id' => (int) $loan['id'],
            'debit_order_id' => $id,
            'reschedule_id' => $debitOrder['mandate_stale_reschedule_id'] ?? null,
            'reason' => $reason,
        ];
        $label = $debitOrder['debit_order_no'] . ' (loan ' . $loan['loan_no'] . ')';

        $result = ($this->canceller)($debitOrder);
        if (!$result['success']) {
            Audit::log('Update', 'Debit Orders', 'Could NOT cancel debit order ' . $label . ' for replacement: ' . $result['message'], $context + ['outcome' => 'failed']);
            $this->loans->logStatus((int) $loan['id'], $loan['loan_status'], $loan['loan_status'], $userId,
                'Tried to cancel debit order ' . $debitOrder['debit_order_no'] . ' for replacement, but Collexia did not accept it: ' . $result['message']);
            return ['success' => false, 'message' => 'Collexia did not cancel the old mandate: ' . $result['message'] . ' Nothing else was changed -- you can try again.'];
        }

        // Only now is the debit order itself closed, so a new one can be registered for the loan (one live debit order per loan).
        $this->debitOrders->updateRecord($id, ['status' => 'Cancelled']);

        $wasAtCollexia = !empty($result['attempted']);
        $note = 'Debit order ' . $debitOrder['debit_order_no'] . ($wasAtCollexia ? ' cancelled at Collexia' : ' closed (it had not been placed at Collexia)')
            . ' so it can be replaced from the loan\'s current schedule'
            . (!empty($debitOrder['mandate_stale_note']) ? ' (' . $debitOrder['mandate_stale_note'] . ')' : '') . '. Reason: ' . $reason;
        Audit::log('Update', 'Debit Orders', 'Cancelled debit order ' . $label . ' for replacement. Reason: ' . $reason, $context + ['outcome' => 'cancelled', 'cancelled_at_collexia' => $wasAtCollexia]);
        $this->loans->logStatus((int) $loan['id'], $loan['loan_status'], $loan['loan_status'], $userId, $note);

        return ['success' => true, 'message' => 'The old mandate has been cancelled. Register the new debit order from the loan\'s current schedule below.'];
    }
}
