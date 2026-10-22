<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\DebitOrderSplitLegInstallment;

/**
 * Cancels, at Collexia, the installments of a mandate that were ALREADY PAID BY HAND.
 *
 * A client may want the mandate to carry the whole term -- so its installment numbers follow the
 * loan -- even though some installments were settled manually before the debit order existed. Those
 * installments are flagged auto_cancel when the debit order is registered; once Collexia has confirmed
 * the mandate and set every installment to its date, this cancels exactly those, so staff cannot forget
 * to and the borrower is never debited for something already paid.
 *
 * It only ever cancels an installment when ALL of these hold, and otherwise leaves it alone and says why:
 *   - it was flagged at registration (never inferred from the loan afterwards);
 *   - the mandate is Registered and every one of its installments has been set to its planned date, so
 *     Collexia's installment numbers are known to line up with ours;
 *   - the loan installment is STILL fully paid right now (a reversed payment means it must collect);
 *   - Collexia still holds that installment as Active and its collection date is in the future (one
 *     already sent for payment cannot be recalled by a cancel);
 *   - at least one installment of the mandate stays live (otherwise the whole mandate would end).
 *
 * Idempotent: an installment that is already cancelled is recorded as done, and one that could not be
 * cancelled stays pending for the next run (bin/finalize_pending_collexia_mandates.php retries them).
 */
class CollexiaPaidInstallmentCanceller
{
    private DebitOrderSplitLegInstallment $laneInstallments;
    /** @var callable returns an object with installmentRequest()/cancelInstallment() -- the real client unless a test injects one */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null, ?DebitOrderSplitLegInstallment $laneInstallments = null)
    {
        $this->laneInstallments = $laneInstallments ?? new DebitOrderSplitLegInstallment();
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
    }

    /**
     * @return array{cancelled: int, pending: int, warnings: string[]}
     */
    public function cancelForLeg(array $leg, string $frontEndUserName, ?string $today = null): array
    {
        $result = ['cancelled' => 0, 'pending' => 0, 'warnings' => []];
        $legId = (int) $leg['id'];

        $waiting = $this->laneInstallments->pendingAutoCancel($legId);
        if (!$waiting) {
            return $result;
        }
        $result['pending'] = count($waiting);
        $label = 'Mandate ' . (int) $leg['split_no'];

        if (($leg['collexia_api_status'] ?? '') !== 'Registered' || empty($leg['collexia_api_contract_reference'])) {
            return $result; // not confirmed yet -- the finalizer calls this again once it is
        }
        foreach ($this->laneInstallments->forLeg($legId) as $row) {
            if ($row['applied_at'] === null) {
                return $result; // dates not all confirmed yet, so Collexia's numbering is not proven to match ours
            }
        }
        if ($this->laneInstallments->liveCount($legId) < 1) {
            $result['warnings'][] = $label . ': every installment is marked paid by hand, so nothing would be left to collect -- none were cancelled.';
            return $result;
        }

        try {
            $client = ($this->clientFactory)();
            $response = $client->installmentRequest((string) $leg['collexia_api_contract_reference']);
        } catch (\RuntimeException $e) {
            $result['warnings'][] = $label . ': could not read the installments from Collexia (' . $e->getMessage() . ') -- will retry.';
            return $result;
        }
        $actual = CollexiaLaneScheduleService::actualInstallments($response['installments'] ?? []);
        $today = $today ?? date('Y-m-d');
        $db = Database::connection();

        foreach ($waiting as $row) {
            $no = (int) $row['installment_no'];
            $id = (int) $row['id'];

            $schedule = $db->prepare('SELECT installment_no, total_due, total_paid FROM loan_schedules WHERE id = ?');
            $schedule->execute([(int) $row['schedule_id']]);
            $s = $schedule->fetch(\PDO::FETCH_ASSOC);
            if (!$s || (float) $s['total_paid'] < (float) $s['total_due'] - 0.005) {
                // The payment was reversed (or never was in full): this one must collect after all.
                $this->laneInstallments->clearAutoCancel($id, 'Not cancelled: the loan installment is no longer fully paid, so it will be collected.');
                $result['pending']--;
                $result['warnings'][] = $label . ' installment ' . $no . ': loan installment #' . (int) ($s['installment_no'] ?? 0) . ' is no longer fully paid, so it was NOT cancelled and will be collected.';
                continue;
            }

            $a = $actual[$no] ?? null;
            if ($a === null) {
                $result['warnings'][] = $label . ' installment ' . $no . ': Collexia does not list it -- not cancelled.';
                continue;
            }
            if ($a['status'] === 2) {
                $this->laneInstallments->markCancelled($id, 'Paid by hand -- already cancelled at Collexia.');
                $result['cancelled']++;
                $result['pending']--;
                continue;
            }
            if ($a['status'] !== 1 || $a['date'] <= $today) {
                $note = 'Not cancelled: Collexia already has it past the point a cancel can stop it (status ' . $a['status'] . ', date ' . $a['date'] . '). Check it was not collected.';
                $this->laneInstallments->noteCancelAttempt($id, $note);
                $result['warnings'][] = $label . ' installment ' . $no . ': ' . $note;
                continue;
            }

            try {
                $client->cancelInstallment((string) $leg['collexia_api_contract_reference'], $no, $frontEndUserName);
            } catch (\RuntimeException $e) {
                $note = 'Cancel not accepted yet (' . $e->getMessage() . ') -- will retry.';
                $this->laneInstallments->noteCancelAttempt($id, $note);
                $result['warnings'][] = $label . ' installment ' . $no . ': ' . $note;
                continue;
            }

            $this->laneInstallments->markCancelled($id, 'Paid by hand -- cancelled at Collexia automatically.');
            Audit::log('Update', 'Debit Orders', 'Automatically cancelled Collexia installment #' . $no . ' (loan installment #' . (int) $s['installment_no']
                . ', already paid by hand) on ' . $leg['collexia_api_contract_reference']);
            $result['cancelled']++;
            $result['pending']--;
        }

        return $result;
    }
}
