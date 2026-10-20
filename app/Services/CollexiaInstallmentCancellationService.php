<?php

namespace App\Services;

use App\Models\DebitOrder;

/**
 * Cancels one installment of a placed Collexia mandate and then re-reads the contract's status at
 * once, so the debit order reflects Collexia's final-fate rule (see
 * CollexiaMandateStatusSyncService::contractFinalFate()) straight away instead of at the next
 * 07:00 / 12:00 / 17:00 sync: cancelling the LAST live installment makes every installment final,
 * which completes the contract.
 */
class CollexiaInstallmentCancellationService
{
    /** @var callable */
    private $clientFactory;
    private DebitOrder $debitOrders;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
        $this->debitOrders = new DebitOrder();
    }

    /**
     * @param string $contractReference the Collexia contract the installment belongs to (the debit order's own, or one split leg's)
     * @param int $settleSeconds how long to let Collexia process the cancel before asking for its status
     * @return array{status_before: string, status_after: string, completed: bool, sync_error: ?string}
     * @throws \RuntimeException when Collexia refuses the cancel
     */
    public function cancel(array $debitOrder, string $contractReference, int $installmentNo, string $frontEndUserName, int $settleSeconds = 2): array
    {
        $before = (string) $debitOrder['status'];

        ($this->clientFactory)()->cancelInstallment($contractReference, $installmentNo, $frontEndUserName);

        if ($settleSeconds > 0) {
            sleep($settleSeconds);
        }

        $fresh = $this->debitOrders->find((int) $debitOrder['id']) ?? $debitOrder;
        $sync = (new CollexiaMandateStatusSyncService($this->clientFactory))->syncDebitOrder($fresh);
        $after = (string) ($this->debitOrders->find((int) $debitOrder['id'])['status'] ?? $before);

        return [
            'status_before' => $before,
            'status_after' => $after,
            'completed' => $before !== 'Completed' && $after === 'Completed',
            'sync_error' => $sync['synced'] ? null : $sync['error'],
        ];
    }
}
