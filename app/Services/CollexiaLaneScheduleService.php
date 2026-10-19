<?php

namespace App\Services;

use App\Models\CollexiaSetting;
use App\Models\DebitOrderSplitLegInstallment;
use App\Support\CollexiaV3Codes;
use App\Support\SplitLanePlanner;

/**
 * Makes a registered split LANE (one Collexia mandate carrying several loan
 * installments) collect each installment on its exact amortization date and
 * for its exact amount.
 *
 * A mandate is loaded with a first date, a monthly rule and ONE amount, so
 * Collexia's own schedule only approximates the loan's: a part-paid
 * installment, a final installment that is a cent different, or an
 * installment whose date is moved to a business day all differ from that
 * pattern. Once Collexia has confirmed the mandate registered, this reads
 * its real installments (Installment Request), compares each against the
 * lane's plan (debit_order_split_leg_installments) and sends one Update
 * Installment call for only those that differ.
 *
 * Safe to run any number of times: an installment already matching the plan
 * is never touched, and a lane whose installments are all confirmed is a
 * no-op, so the status-sync cron can call it for every registered lane.
 */
class CollexiaLaneScheduleService
{
    private DebitOrderSplitLegInstallment $laneInstallments;
    private CollexiaSetting $settings;
    /** @var callable returns an object with installmentRequest()/updateInstallment() -- the real client unless a test injects one */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->laneInstallments = new DebitOrderSplitLegInstallment();
        $this->settings = new CollexiaSetting();
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
    }

    /**
     * rejected = Collexia answered with an API error (it refused the change,
     * e.g. two installments of one mandate on the same date) as opposed to a
     * network/config failure that is worth retrying unchanged.
     *
     * @return array{success: bool, updated: int, message: string, rejected: bool}
     */
    public function apply(array $leg, array $debitOrder, string $frontEndUserName): array
    {
        $legId = (int) $leg['id'];
        $rows = $this->laneInstallments->forLeg($legId);
        if (!$rows) {
            return ['success' => true, 'updated' => 0, 'message' => '', 'rejected' => false];
        }

        $pending = array_filter($rows, fn ($r) => $r['applied_at'] === null);
        if (!$pending) {
            return ['success' => true, 'updated' => 0, 'message' => '', 'rejected' => false];
        }
        if (empty($leg['collexia_api_contract_reference'])) {
            return ['success' => false, 'updated' => 0, 'message' => 'This lane has not been placed yet.', 'rejected' => false];
        }

        try {
            $client = ($this->clientFactory)();
            $response = $client->installmentRequest((string) $leg['collexia_api_contract_reference']);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'updated' => 0, 'message' => $e->getMessage(), 'rejected' => false];
        }

        $actual = self::actualInstallments($response['installments'] ?? []);
        $planned = [];
        foreach ($rows as $row) {
            $planned[(int) $row['installment_no']] = ['date' => $row['planned_date'], 'amount' => (float) $row['planned_amount']];
        }

        $needs = SplitLanePlanner::installmentsNeedingUpdate(
            $planned,
            array_map(fn ($a) => ['date' => $a['date'], 'amount' => $a['amount']], $actual)
        );

        $updates = [];
        foreach ($needs as $no) {
            // Only an installment Collexia still holds as Active can be moved.
            if (($actual[$no]['status'] ?? 0) !== 1 || empty($actual[$no]['intId'])) {
                continue;
            }
            $date = $planned[$no]['date'];
            $update = [
                'intId' => (int) $actual[$no]['intId'],
                'trackingDate' => date('Ymd', strtotime($date)),
                'scheduledDate' => date('Ymd', strtotime($date)),
                'numberOfTrackingDays' => (int) $debitOrder['no_of_days_tracking'],
                'installmentAmount' => $planned[$no]['amount'],
                'collectionDay' => CollexiaV3Codes::collectionDay((int) date('j', strtotime($date))),
            ];
            if ($this->settings->v4FieldsEnabled() && !empty($debitOrder['phone'])) {
                $update['debtorTelephone'] = $debitOrder['phone'];
            }
            $updates[$no] = $update;
        }

        if ($updates) {
            try {
                $client->updateInstallment(array_values($updates), $frontEndUserName);
            } catch (\RuntimeException $e) {
                return ['success' => false, 'updated' => 0, 'message' => $e->getMessage(), 'rejected' => $e instanceof CollexiaApiException];
            }
        }

        foreach ($rows as $row) {
            $no = (int) $row['installment_no'];
            if (isset($updates[$no])) {
                $this->laneInstallments->setPlanned($legId, $no, $row['planned_date'], (float) $row['planned_amount']);
            } elseif (isset($actual[$no]) && !in_array($no, $needs, true)) {
                $this->laneInstallments->setCollexiaDate($legId, $no, $actual[$no]['date']);
                $this->laneInstallments->markApplied((int) $row['id']);
            }
        }

        return ['success' => true, 'updated' => count($updates), 'message' => '', 'rejected' => false];
    }

    /**
     * Installment Request / Mandate Enquiry entries keyed by Collexia's own
     * installment number. Tolerates both field spellings seen across the two
     * calls (installmentNo / noOfInstallment, scheduledDate / scheduleDate)
     * and both date formats (YYYYMMDD / YYYY-MM-DD).
     *
     * @return array<int, array{intId: int, date: string, amount: float, status: int}>
     */
    public static function actualInstallments(array $installments): array
    {
        $byNo = [];
        foreach ($installments as $i) {
            $no = (int) ($i['installmentNo'] ?? $i['noOfInstallment'] ?? 0);
            $date = self::normaliseDate((string) ($i['scheduledDate'] ?? $i['scheduleDate'] ?? ''));
            if ($no < 1 || $date === null) {
                continue;
            }
            $byNo[$no] = [
                'intId' => (int) ($i['intId'] ?? 0),
                'date' => $date,
                'amount' => (float) ($i['installmentAmount'] ?? 0),
                'status' => (int) ($i['status'] ?? 0),
            ];
        }
        return $byNo;
    }

    public static function normaliseDate(string $raw): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }
}
