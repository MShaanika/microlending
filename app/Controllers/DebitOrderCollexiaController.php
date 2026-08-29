<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderCollection;
use App\Models\DebitOrderInstallmentTarget;
use App\Models\DebitOrderSplitLeg;
use App\Services\CollexiaApiException;
use App\Services\CollexiaEndoApiClient;
use App\Support\CollexiaV3Codes;

/**
 * Wires CollexiaEndoApiClient's mandate lifecycle (place/confirm/status/
 * cancel/reschedule) into the existing debit order screens, as additive
 * actions on /debit-orders/{id} -- entirely separate from that same
 * screen's existing Excel-batch registration, which is unaffected and
 * keeps working regardless of whether this integration is enabled.
 *
 * contractReference generation (buildContractReference()) is a best-effort
 * placeholder: the spec states the 14-char format is "Merchant GID in HEX"
 * + "calculated base date" + "teller for the day" but does not give the
 * date/teller formulas, so this has not been confirmed against what
 * Collexia's backend actually expects -- verify before relying on it for
 * a real mandate.
 *
 * Split debit orders (debit_orders.split_enabled): the installment is
 * divided into 1-10 independent split transactions (DebitOrderSplitLeg,
 * amounts entered individually at registration, not necessarily equal),
 * each its own Collexia mandate. Place/Check Final Fate/Sync/Cancel
 * Mandate act on whichever splits are actually in the relevant state
 * (placement can happen incrementally -- e.g. only a newly-merged split
 * still needs placing while the others are already Registered), and roll
 * their combined state back onto debit_orders.collexia_api_status purely
 * for the existing single-badge summary. splitTransactions()/mergeSplits()
 * are the drill-down/merge screen -- see their docblocks. Reschedule
 * Installment and single-installment Cancel stay unsupported for split
 * orders (multiple mandates to pick from; use merge/cancel-mandate
 * instead).
 */
class DebitOrderCollexiaController extends Controller
{
    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderInstallmentTarget $installmentTargets;
    private DebitOrderCollection $collections;
    private CollexiaSetting $settings;

    /** Statuses a split can still be pushed to Collexia from, or merged away from without needing an API cancel first. */
    private const SPLIT_UNSENT_STATUSES = ['Not Placed', 'Load Failed'];
    /** Statuses meaning Collexia actually has this mandate live -- must be cancelled there before it can be merged away. */
    private const SPLIT_LIVE_STATUSES = ['Load Pending', 'Registered'];

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->installmentTargets = new DebitOrderInstallmentTarget();
        $this->collections = new DebitOrderCollection();
        $this->settings = new CollexiaSetting();
    }

    public function placeMandate(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id)) {
            return;
        }

        $banId = $debitOrder['bank_code'] ? CollexiaV3Codes::fromLegacyBankCode($debitOrder['bank_code']) : null;
        if (!$banId) {
            Session::flash('error', 'This debit order\'s bank has no known bank ID for this integration -- cannot place the mandate.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            $this->placeSplitMandate($debitOrder, $banId);
        } else {
            $this->placeSingleMandate($debitOrder, $banId);
        }

        $this->redirect('/debit-orders/' . $id);
    }

    private function placeSingleMandate(array $debitOrder, int $banId): void
    {
        $id = (int) $debitOrder['id'];
        $contractReference = $this->buildContractReference($id);
        $noOfInstallments = max(1, $this->debitOrders->remainingInstallments((int) $debitOrder['loan_id']));
        $clientNoBase = $debitOrder['borrower_loan_ref_no'] ?: $debitOrder['debit_order_no'];

        $mandate = [
            'clientNo' => substr((string) $clientNoBase, 0, 15),
            'userReference' => substr((string) $debitOrder['debit_order_no'], 0, 10),
            'frequencyCode' => 4, // Monthly -- this app's debit orders are always monthly (debit_day is a day-of-month)
            'installmentAmount' => (float) $debitOrder['debit_amount'],
            'noOfInstallments' => $noOfInstallments,
            'origin' => 0,
            'contractReference' => $contractReference,
            'magId' => CollexiaV3Codes::MAG_ID_ENDO,
            'initialAmount' => 0,
            'firstCollectionDate' => date('Ymd', strtotime((string) $debitOrder['start_date'])),
            'collectionDay' => CollexiaV3Codes::collectionDay((int) $debitOrder['debit_day']),
            'numberOfTrackingDays' => (int) $debitOrder['no_of_days_tracking'],
            'debtorAccountName' => $debitOrder['account_name'] ?: $debitOrder['borrower_name'],
            'debtorIdentificationType' => 1,
            'debtorIdentificationNo' => $debitOrder['id_number'],
            'debtorAccountNumber' => $debitOrder['account_number'],
            'debtorAccountType' => (int) $debitOrder['account_type'],
            'debtorBanId' => $banId,
        ];

        try {
            $client = new CollexiaEndoApiClient();
            $client->loadMandate($mandate);

            $this->debitOrders->updateCollexiaApiState($id, [
                'collexia_api_contract_reference' => $contractReference,
                'collexia_api_status' => 'Load Pending',
                'collexia_api_last_response' => 'Mandate submitted. Call "Check Final Fate" to confirm registration.',
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::log('Update', 'Debit Orders', 'Placed Collexia API mandate for debit order #' . $id . ' (' . $contractReference . ')');
            Session::flash('success', 'Mandate submitted (' . $contractReference . '). Use "Check Final Fate" to confirm it registered.');
        } catch (CollexiaApiException $e) {
            $this->debitOrders->updateCollexiaApiState($id, [
                'collexia_api_status' => 'Load Failed',
                'collexia_api_last_response' => $e->getMessage(),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);
            Session::flash('error', 'The mandate was rejected: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            // A network-level failure (e.g. a timeout) does NOT mean
            // Collexia never received the request -- it may have been
            // accepted on their end with the response lost in transit. Mark
            // this visibly as Uncertain rather than leaving the previous
            // status untouched (which would be indistinguishable from
            // "never attempted" and could tempt a blind resubmit). Staff
            // must use "Check Final Fate" to reconcile before assuming
            // either outcome.
            $this->debitOrders->updateCollexiaApiState($id, [
                'collexia_api_contract_reference' => $contractReference,
                'collexia_api_status' => 'Uncertain',
                'collexia_api_last_response' => 'Network error, outcome unknown: ' . $e->getMessage(),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log(
                'Uncertain',
                'Debit Orders',
                'Collexia mandate request outcome unknown after network error for debit order #' . $id . ' (' . $contractReference . ')',
                ['exception' => $e->getMessage()]
            );
            Session::flash('error', "The request may or may not have reached Collexia (network error). Status marked Uncertain -- use \"Check Final Fate\" to confirm before resubmitting.");
        }
    }

    /**
     * Places a mandate for every split transaction still waiting to be sent
     * (status Not Placed or Load Failed, and not folded away by a merge) --
     * NOT necessarily all of them, since placement can now happen
     * incrementally (a merge creates one new split while its siblings stay
     * however they already were). Amounts come from the split rows created
     * at registration (or by a merge), never recomputed here.
     *
     * Snapshots which loan_schedules row each of Collexia's 1..N
     * installment sequence numbers corresponds to (DebitOrderInstallmentTarget)
     * on the FIRST placement only -- re-snapshotting later could remap
     * already-live splits to a different installment than Collexia already
     * knows them by, since the loan's unpaid-schedule state can have moved
     * on by the time a later split (e.g. a merge result) gets placed.
     */
    private function placeSplitMandate(array $debitOrder, int $banId): void
    {
        $id = (int) $debitOrder['id'];
        $noOfInstallments = max(1, $this->debitOrders->remainingInstallments((int) $debitOrder['loan_id']));
        $clientNoBase = $debitOrder['borrower_loan_ref_no'] ?: $debitOrder['debit_order_no'];

        $pending = array_values(array_filter(
            $this->splitLegs->activeForDebitOrder($id),
            fn ($s) => in_array($s['collexia_api_status'], self::SPLIT_UNSENT_STATUSES, true)
        ));

        if (empty($pending)) {
            Session::flash('error', 'No split transaction is waiting to be placed.');
            return;
        }

        if (!$this->installmentTargets->hasSnapshot($id)) {
            $this->installmentTargets->snapshot($id, $this->debitOrders->orderedUnpaidScheduleIds((int) $debitOrder['loan_id']));
        }

        $anyFailed = false;
        $placedSummary = [];

        foreach ($pending as $split) {
            $splitNo = (int) $split['split_no'];
            $amount = (float) $split['leg_amount'];
            $contractReference = $this->buildContractReference($id, $splitNo);
            $suffix = self::splitSuffix($splitNo);

            $mandate = [
                'clientNo' => substr((string) $clientNoBase, 0, 14) . $suffix,
                'userReference' => substr((string) $debitOrder['debit_order_no'], 0, 9) . $suffix,
                'frequencyCode' => 4,
                'installmentAmount' => $amount,
                'noOfInstallments' => $noOfInstallments,
                'origin' => 0,
                'contractReference' => $contractReference,
                'magId' => CollexiaV3Codes::MAG_ID_ENDO,
                'initialAmount' => 0,
                'firstCollectionDate' => date('Ymd', strtotime((string) $debitOrder['start_date'])),
                'collectionDay' => CollexiaV3Codes::collectionDay((int) $debitOrder['debit_day']),
                'numberOfTrackingDays' => (int) $debitOrder['no_of_days_tracking'],
                'debtorAccountName' => $debitOrder['account_name'] ?: $debitOrder['borrower_name'],
                'debtorIdentificationType' => 1,
                'debtorIdentificationNo' => $debitOrder['id_number'],
                'debtorAccountNumber' => $debitOrder['account_number'],
                'debtorAccountType' => (int) $debitOrder['account_type'],
                'debtorBanId' => $banId,
            ];

            try {
                $client = new CollexiaEndoApiClient();
                $client->loadMandate($mandate);

                $this->splitLegs->updateState($id, $splitNo, [
                    'collexia_api_contract_reference' => $contractReference,
                    'collexia_api_status' => 'Load Pending',
                    'collexia_api_last_response' => 'Mandate submitted. Call "Check Final Fate" to confirm registration.',
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $placedSummary[] = 'split #' . $splitNo . ' ' . format_money($amount);
            } catch (CollexiaApiException $e) {
                $this->splitLegs->updateState($id, $splitNo, [
                    'collexia_api_status' => 'Load Failed',
                    'collexia_api_last_response' => $e->getMessage(),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $anyFailed = true;
            } catch (\RuntimeException $e) {
                $this->splitLegs->updateState($id, $splitNo, [
                    'collexia_api_status' => 'Load Failed',
                    'collexia_api_last_response' => $e->getMessage(),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $anyFailed = true;
            }
        }

        $this->rollupSplitStatus($id);

        Audit::log('Update', 'Debit Orders', 'Placed split Collexia API mandate(s) for debit order #' . $id . ': ' . implode(', ', $placedSummary));

        if ($anyFailed) {
            Session::flash('error', 'At least one split transaction was rejected. See Split Transactions for details.');
        } else {
            Session::flash('success', count($pending) . ' split transaction(s) submitted. Use "Check Final Fate" to confirm registration.');
        }
    }

    public function checkFinalFate(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id)) {
            return;
        }

        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            $this->checkSplitFinalFate($debitOrder);
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        if (!$debitOrder['collexia_api_contract_reference']) {
            Session::flash('error', 'This mandate has not been placed yet.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->requestFinalFate((string) $debitOrder['collexia_api_contract_reference']);

            $loaded = !empty($result['mandateLoaded']);
            $this->debitOrders->updateCollexiaApiState((int) $id, [
                'collexia_api_status' => $loaded ? 'Registered' : 'Load Failed',
                'collexia_api_last_response' => json_encode($result),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::log('Update', 'Debit Orders', 'Checked Collexia final fate for debit order #' . $id . ' -> ' . ($loaded ? 'Registered' : 'Load Failed'));
            Session::flash($loaded ? 'success' : 'error', $loaded
                ? 'Mandate confirmed registered.'
                : 'The mandate did not register (code ' . ($result['mandateLoadedResponseCode'] ?? '?') . ').');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id);
    }

    private function checkSplitFinalFate(array $debitOrder): void
    {
        $id = (int) $debitOrder['id'];
        $splits = $this->splitLegs->activeForDebitOrder($id);
        $attempted = false;
        $allLoaded = true;

        foreach ($splits as $split) {
            if ($split['collexia_api_status'] !== 'Load Pending' || !$split['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->requestFinalFate((string) $split['collexia_api_contract_reference']);
                $loaded = !empty($result['mandateLoaded']);

                $this->splitLegs->updateState($id, (int) $split['split_no'], [
                    'collexia_api_status' => $loaded ? 'Registered' : 'Load Failed',
                    'collexia_api_last_response' => json_encode($result),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $allLoaded = $allLoaded && $loaded;
            } catch (\RuntimeException $e) {
                Session::flash('error', $e->getMessage());
                return;
            }
        }

        if (!$attempted) {
            Session::flash('error', 'No split transaction is awaiting confirmation.');
            return;
        }

        $this->rollupSplitStatus($id);

        Audit::log('Update', 'Debit Orders', 'Checked Collexia final fate for split debit order #' . $id);
        Session::flash($allLoaded ? 'success' : 'error', $allLoaded
            ? 'All submitted split transactions confirmed registered.'
            : 'At least one split transaction did not register. See Split Transactions for details.');
    }

    public function syncStatus(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id)) {
            return;
        }

        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            $this->syncSplitStatus($debitOrder);
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        if (!$debitOrder['collexia_api_contract_reference']) {
            Session::flash('error', 'This mandate has not been placed yet.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->mandateEnquiry(['contractReference' => $debitOrder['collexia_api_contract_reference']]);

            $this->debitOrders->updateCollexiaApiState((int) $id, [
                'collexia_api_last_response' => json_encode($result),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            Session::flash('success', 'Synced the latest mandate status.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id);
    }

    private function syncSplitStatus(array $debitOrder): void
    {
        $id = (int) $debitOrder['id'];
        $splits = $this->splitLegs->activeForDebitOrder($id);
        $attempted = false;

        foreach ($splits as $split) {
            if (!in_array($split['collexia_api_status'], self::SPLIT_LIVE_STATUSES, true) || !$split['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->mandateEnquiry(['contractReference' => $split['collexia_api_contract_reference']]);

                $this->splitLegs->updateState($id, (int) $split['split_no'], [
                    'collexia_api_last_response' => json_encode($result),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\RuntimeException $e) {
                Session::flash('error', $e->getMessage());
                return;
            }
        }

        if (!$attempted) {
            Session::flash('error', 'No split transaction has been placed yet.');
            return;
        }

        $this->debitOrders->updateCollexiaApiState($id, ['collexia_api_synced_at' => date('Y-m-d H:i:s')]);
        Session::flash('success', 'Synced the latest status for every placed split transaction.');
    }

    public function cancelMandate(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id)) {
            return;
        }

        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            $this->cancelSplitMandate($debitOrder);
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        if (!$debitOrder['collexia_api_contract_reference']) {
            Session::flash('error', 'This mandate has not been placed yet.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->cancelMandate((string) $debitOrder['collexia_api_contract_reference']);

            $this->debitOrders->updateCollexiaApiState((int) $id, [
                'collexia_api_status' => 'Cancelled',
                'collexia_api_last_response' => json_encode($result),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::log('Update', 'Debit Orders', 'Cancelled Collexia API mandate for debit order #' . $id);
            Session::flash('success', 'Mandate cancelled.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id);
    }

    private function cancelSplitMandate(array $debitOrder): void
    {
        $id = (int) $debitOrder['id'];
        $splits = $this->splitLegs->activeForDebitOrder($id);
        $attempted = false;

        foreach ($splits as $split) {
            if ($split['collexia_api_status'] === 'Cancelled' || !$split['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->cancelMandate((string) $split['collexia_api_contract_reference']);

                $this->splitLegs->updateState($id, (int) $split['split_no'], [
                    'collexia_api_status' => 'Cancelled',
                    'collexia_api_last_response' => json_encode($result),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\RuntimeException $e) {
                Session::flash('error', $e->getMessage());
                return;
            }
        }

        if (!$attempted) {
            Session::flash('error', 'This mandate has not been placed yet.');
            return;
        }

        $this->rollupSplitStatus($id);

        Audit::log('Update', 'Debit Orders', 'Cancelled split Collexia API mandate for debit order #' . $id);
        Session::flash('success', 'Every live split transaction cancelled.');
    }

    /**
     * Rolls every live split's own status up onto
     * debit_orders.collexia_api_status so the existing single-badge summary
     * on debit_orders/show.php.content stays meaningful for split orders
     * too -- worst-first: any Load Failed wins, else any Load Pending, else
     * the splits' shared status if they agree, else Registered (the more
     * actionable state) if they've drifted apart. Button visibility for
     * split orders no longer depends on this rollup (see splitActionFlags()
     * / DebitOrderController::show()) precisely because placement can now
     * be incremental and this single value can't represent that -- it's
     * purely a glanceable summary badge now. Splits already folded away by
     * a merge are excluded; they're permanently Cancelled and irrelevant
     * to the live rollup.
     */
    private function rollupSplitStatus(int $debitOrderId): void
    {
        $statuses = array_column($this->splitLegs->activeForDebitOrder($debitOrderId), 'collexia_api_status');

        if (in_array('Load Failed', $statuses, true)) {
            $status = 'Load Failed';
        } elseif (in_array('Load Pending', $statuses, true)) {
            $status = 'Load Pending';
        } elseif (!empty($statuses) && count(array_unique($statuses)) === 1) {
            $status = $statuses[0];
        } else {
            $status = 'Registered';
        }

        $this->debitOrders->updateCollexiaApiState($debitOrderId, [
            'collexia_api_status' => $status,
            'collexia_api_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Step 1 of reschedule -- fetch current installments/intId(s) to pick from. */
    public function installments(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            Session::flash('error', 'Rescheduling isn\'t supported for a split debit order (it has two mandates). Cancel and re-register instead.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }
        if (!$debitOrder['collexia_api_contract_reference']) {
            Session::flash('error', 'This mandate has not been placed yet.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->installmentRequest((string) $debitOrder['collexia_api_contract_reference']);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $this->view('debit_orders/collexia_installments', [
            'title' => 'Reschedule Installments - ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'installments' => $result['installments'] ?? [],
        ]);
    }

    /** Step 2 of reschedule -- submit the new terms for one installment. */
    public function updateInstallment(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id, '/debit-orders/' . $id . '/collexia/installments')) {
            return;
        }

        $intId = (int) ($_POST['int_id'] ?? 0);
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $installmentAmount = (float) ($_POST['installment_amount'] ?? 0);

        if (!$intId || $scheduledDate === '' || $installmentAmount <= 0) {
            Session::flash('error', 'Select an installment and provide a valid date and amount.');
            $this->redirect('/debit-orders/' . $id . '/collexia/installments');
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $client->updateInstallment([[
                'intId' => $intId,
                'trackingDate' => date('Ymd', strtotime($scheduledDate)),
                'scheduledDate' => date('Ymd', strtotime($scheduledDate)),
                'numberOfTrackingDays' => (int) $debitOrder['no_of_days_tracking'],
                'installmentAmount' => $installmentAmount,
                'collectionDay' => CollexiaV3Codes::collectionDay((int) date('j', strtotime($scheduledDate))),
            ]]);

            $this->debitOrders->updateCollexiaApiState((int) $id, ['collexia_api_synced_at' => date('Y-m-d H:i:s')]);
            Audit::log('Update', 'Debit Orders', 'Rescheduled Collexia installment ' . $intId . ' for debit order #' . $id);
            Session::flash('success', 'Installment reschedule submitted.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id);
    }

    /**
     * Cancels a single future installment within an otherwise-live mandate
     * (spec 7.3) -- distinct from Cancel Mandate, which cancels the whole
     * contract. Reached from the same Reschedule Installments screen, not
     * supported for a split order (same reasoning as installments()).
     */
    public function cancelInstallment(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id, '/debit-orders/' . $id . '/collexia/installments')) {
            return;
        }
        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            Session::flash('error', 'Cancelling a single installment isn\'t supported for a split debit order.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }
        if (!$debitOrder['collexia_api_contract_reference']) {
            Session::flash('error', 'This mandate has not been placed yet.');
            $this->redirect('/debit-orders/' . $id . '/collexia/installments');
            return;
        }

        $installmentNo = (int) ($_POST['installment_no'] ?? 0);
        if ($installmentNo < 1) {
            Session::flash('error', 'Select a valid installment to cancel.');
            $this->redirect('/debit-orders/' . $id . '/collexia/installments');
            return;
        }

        try {
            $client = new CollexiaEndoApiClient();
            $client->cancelInstallment((string) $debitOrder['collexia_api_contract_reference'], $installmentNo);

            $this->debitOrders->updateCollexiaApiState((int) $id, ['collexia_api_synced_at' => date('Y-m-d H:i:s')]);
            Audit::log('Update', 'Debit Orders', 'Cancelled Collexia installment #' . $installmentNo . ' for debit order #' . $id);
            Session::flash('success', 'Installment #' . $installmentNo . ' cancelled.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id . '/collexia/installments');
    }

    private function loadOr404(string $id): ?array
    {
        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return null;
        }
        return $debitOrder;
    }

    private function verifyCsrfOrRedirect(string $id, ?string $redirectTo = null): bool
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($redirectTo ?? '/debit-orders/' . $id);
            return false;
        }
        return true;
    }

    /**
     * NOT CONFIRMED against Collexia's actual expected encoding -- see
     * class docblock. 4 chars merchant GID in hex, 4 chars date, 6 chars
     * zero-padded debit order id, for exactly 14 characters and guaranteed
     * local uniqueness (but not necessarily the format Collexia expects).
     *
     * $splitNo (1-10) is for a split order's Nth mandate, which needs its
     * own distinct reference from the same debit order id -- shortens the
     * zero-padded id segment from 6 to 5 digits to make room for a
     * 1-character split suffix (splitSuffix()) while staying within the
     * 14-character total. Omitting $splitNo (the non-split case) is
     * byte-for-byte the original 6-digit encoding.
     */
    private function buildContractReference(int $debitOrderId, ?int $splitNo = null): string
    {
        $merchantGid = (int) $this->settings->get('collexia_merchant_gid');
        $gidHex = strtoupper(str_pad(dechex($merchantGid), 4, '0', STR_PAD_LEFT));
        $gidHex = substr($gidHex, -4);
        $dateSeg = date('md');
        $tellerSeg = $splitNo !== null
            ? str_pad((string) $debitOrderId, 5, '0', STR_PAD_LEFT) . self::splitSuffix($splitNo)
            : str_pad((string) $debitOrderId, 6, '0', STR_PAD_LEFT);
        return $gidHex . $dateSeg . $tellerSeg;
    }

    /** Single-character encoding of a split number (1-10) for the 14-char contract reference and the 15/10-char clientNo/userReference fields: '1'-'9' for splits 1-9, 'X' for split 10. */
    private static function splitSuffix(int $splitNo): string
    {
        return $splitNo <= 9 ? (string) $splitNo : 'X';
    }

    /**
     * The drill-down/history screen for a split debit order -- every split
     * transaction ever created for it, including ones folded away by a
     * merge, with which loan_schedules row's collection date (if any) each
     * corresponds to, and which currently-live splits are eligible to be
     * selected for a merge.
     */
    public function splitTransactions(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (empty($debitOrder['split_enabled'])) {
            Session::flash('error', 'This debit order was not registered as a split.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $splits = $this->splitLegs->forDebitOrder((int) $id);

        $collectedBySplitNo = [];
        foreach ($this->collections->forDebitOrder((int) $id) as $c) {
            if ($c['payment_id'] && $c['split_no'] !== null) {
                $collectedBySplitNo[(int) $c['split_no']] = $c['payment_date'];
            }
        }

        // Rows that resulted FROM a merge: map the merged-into id back to
        // the split_no(s) that were folded into it, for the "Merged (from
        // #.. #..)" label.
        $mergeSources = [];
        $idToSplitNo = [];
        foreach ($splits as $s) {
            $idToSplitNo[(int) $s['id']] = (int) $s['split_no'];
            if ($s['merged_into_id']) {
                $mergeSources[(int) $s['merged_into_id']][] = (int) $s['split_no'];
            }
        }

        $this->view('debit_orders/split_transactions', [
            'title' => 'Split Transactions - ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'splits' => $splits,
            'collectedBySplitNo' => $collectedBySplitNo,
            'mergeSources' => $mergeSources,
            'idToSplitNo' => $idToSplitNo,
            'mergeableIds' => $this->mergeableSplitIds((int) $id, $splits),
        ]);
    }

    /**
     * A split is mergeable only while it's still live (never itself
     * already merged away) AND has never actually collected a payment --
     * undoing a real collection isn't something a merge should attempt,
     * that needs an actual refund process. Not-yet-sent, rejected, pending,
     * and registered-but-not-yet-collected splits are all fair game; only
     * Cancelled (nothing left to merge) and already-collected splits are
     * excluded.
     */
    private function mergeableSplitIds(int $debitOrderId, array $splits): array
    {
        $ids = [];
        foreach ($splits as $s) {
            if ($s['merged_into_id'] !== null) {
                continue;
            }
            if (!in_array($s['collexia_api_status'], array_merge(self::SPLIT_UNSENT_STATUSES, self::SPLIT_LIVE_STATUSES), true)) {
                continue;
            }
            if ($this->splitLegs->hasPostedCollection($debitOrderId, (int) $s['split_no'])) {
                continue;
            }
            $ids[] = (int) $s['id'];
        }
        return $ids;
    }

    /**
     * Combines two or more selected split transactions into one. Any
     * selected split that's actually live at Collexia (Load Pending /
     * Registered) is cancelled there FIRST via the existing cancelMandate
     * call -- if any of those cancels fails, the whole merge is aborted
     * with no local changes at all, so a merge is never half-applied
     * against Collexia. Splits that were never sent (Not Placed / Load
     * Failed) need no API call. The selected rows are then marked
     * Cancelled and linked via merged_into_id to a single new split row
     * (status Not Placed) for the combined amount -- that new row is NOT
     * automatically placed; use the existing Place Mandate action for it
     * afterward, same as any other unplaced split.
     */
    public function mergeSplits(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
            return;
        }
        if (!$this->verifyCsrfOrRedirect($id, '/debit-orders/' . $id . '/split-transactions')) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['split_ids'] ?? []))));
        if (count($ids) < 2) {
            Session::flash('error', 'Select at least two split transactions to merge.');
            $this->redirect('/debit-orders/' . $id . '/split-transactions');
            return;
        }

        $selected = $this->splitLegs->findManyForDebitOrder((int) $id, $ids);
        if (count($selected) !== count($ids)) {
            Session::flash('error', 'One or more selected transactions could not be found on this debit order.');
            $this->redirect('/debit-orders/' . $id . '/split-transactions');
            return;
        }

        $mergeable = $this->mergeableSplitIds((int) $id, $selected);
        foreach ($selected as $s) {
            if (!in_array((int) $s['id'], $mergeable, true)) {
                Session::flash('error', 'Split #' . $s['split_no'] . ' can no longer be merged (already merged, cancelled, or already collected a payment).');
                $this->redirect('/debit-orders/' . $id . '/split-transactions');
                return;
            }
        }

        $toCancelAtCollexia = array_values(array_filter(
            $selected,
            fn ($s) => in_array($s['collexia_api_status'], self::SPLIT_LIVE_STATUSES, true) && $s['collexia_api_contract_reference']
        ));

        $cancelResponses = [];
        if (!empty($toCancelAtCollexia)) {
            try {
                $client = new CollexiaEndoApiClient();
                foreach ($toCancelAtCollexia as $s) {
                    $cancelResponses[$s['id']] = $client->cancelMandate((string) $s['collexia_api_contract_reference']);
                }
            } catch (\RuntimeException $e) {
                Session::flash('error', 'Could not cancel one of the selected transactions at Collexia, so the merge was not applied: ' . $e->getMessage());
                $this->redirect('/debit-orders/' . $id . '/split-transactions');
                return;
            }
        }

        $combinedAmount = round((float) array_sum(array_column($selected, 'leg_amount')), 2);
        $totalSplits = (int) $selected[0]['total_splits'];
        $newSplitNo = $this->splitLegs->nextSplitNo((int) $id);
        $newId = $this->splitLegs->upsert((int) $id, $newSplitNo, $combinedAmount, $totalSplits);

        $refs = [];
        foreach ($selected as $s) {
            $this->splitLegs->updateById((int) $s['id'], [
                'collexia_api_status' => 'Cancelled',
                'merged_into_id' => $newId,
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                'collexia_api_last_response' => isset($cancelResponses[$s['id']]) ? json_encode($cancelResponses[$s['id']]) : $s['collexia_api_last_response'],
            ]);
            $refs[] = 'split #' . $s['split_no'] . ' (' . format_money($s['leg_amount'])
                . ($s['collexia_api_contract_reference'] ? ', was ' . $s['collexia_api_contract_reference'] . ' ' . $s['collexia_api_status'] : ', ' . $s['collexia_api_status'])
                . ')';
        }

        Audit::log(
            'Update',
            'Debit Orders',
            'Merged ' . count($selected) . ' split transaction(s) on debit order #' . $id . ' (' . $debitOrder['debit_order_no'] . ') into new split #' . $newSplitNo
                . ' totalling ' . format_money($combinedAmount) . '. Merged: ' . implode('; ', $refs)
        );

        Session::flash('success', count($selected) . ' split transaction(s) merged into one ' . format_money($combinedAmount) . ' transaction (split #' . $newSplitNo . '). Place its mandate when ready.');
        $this->redirect('/debit-orders/' . $id . '/split-transactions');
    }
}
