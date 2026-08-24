<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
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
 * Split debit orders (debit_orders.split_enabled): when on, every action
 * below places/confirms/syncs/cancels TWO independent Collexia mandates
 * (leg A / leg B, half the amount each -- see DebitOrderSplitLeg) instead
 * of one, and rolls their combined state back onto debit_orders' own
 * collexia_api_status so the existing button-visibility logic in
 * debit_orders/show.php.content needs no changes. Reschedule Installment
 * is not supported for split orders (there are two mandates to pick from,
 * and the reschedule flow assumes exactly one) -- it stays hidden.
 */
class DebitOrderCollexiaController extends Controller
{
    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderInstallmentTarget $installmentTargets;
    private CollexiaSetting $settings;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->installmentTargets = new DebitOrderInstallmentTarget();
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

        $mandate = [
            'clientNo' => substr((string) $debitOrder['debit_order_no'], 0, 15),
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
            Session::flash('error', $e->getMessage());
        }
    }

    /**
     * Places two mandates (leg A / leg B, half the debit amount each --
     * any odd cent goes to leg A) instead of one, and snapshots which
     * loan_schedules row each of Collexia's 1..N installment sequence
     * numbers corresponds to for THIS mandate (DebitOrderInstallmentTarget),
     * so a later collection result can be posted to the exact intended
     * row rather than relying on FIFO. Re-snapshots fresh on every call,
     * so retrying after a failed leg re-reads the loan's current unpaid
     * schedule state rather than a stale one.
     */
    private function placeSplitMandate(array $debitOrder, int $banId): void
    {
        $id = (int) $debitOrder['id'];
        $noOfInstallments = max(1, $this->debitOrders->remainingInstallments((int) $debitOrder['loan_id']));
        $this->installmentTargets->snapshot($id, $this->debitOrders->orderedUnpaidScheduleIds((int) $debitOrder['loan_id']));

        $half = round((float) $debitOrder['debit_amount'] / 2, 2);
        $legAmounts = ['A' => $half, 'B' => round((float) $debitOrder['debit_amount'] - $half, 2)];

        $anyFailed = false;

        foreach ($legAmounts as $leg => $amount) {
            $this->splitLegs->upsert($id, $leg, $amount);
            $contractReference = $this->buildContractReference($id, $leg);

            $mandate = [
                'clientNo' => substr((string) $debitOrder['debit_order_no'], 0, 14) . $leg,
                'userReference' => substr((string) $debitOrder['debit_order_no'], 0, 9) . $leg,
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

                $this->splitLegs->updateState($id, $leg, [
                    'collexia_api_contract_reference' => $contractReference,
                    'collexia_api_status' => 'Load Pending',
                    'collexia_api_last_response' => 'Mandate submitted. Call "Check Final Fate" to confirm registration.',
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (CollexiaApiException $e) {
                $this->splitLegs->updateState($id, $leg, [
                    'collexia_api_status' => 'Load Failed',
                    'collexia_api_last_response' => $e->getMessage(),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $anyFailed = true;
            } catch (\RuntimeException $e) {
                $this->splitLegs->updateState($id, $leg, [
                    'collexia_api_status' => 'Load Failed',
                    'collexia_api_last_response' => $e->getMessage(),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $anyFailed = true;
            }
        }

        $this->rollupSplitStatus($id);

        Audit::log('Update', 'Debit Orders', 'Placed split Collexia API mandate for debit order #' . $id
            . ' (leg A ' . format_money($legAmounts['A']) . ' / leg B ' . format_money($legAmounts['B']) . ')');

        if ($anyFailed) {
            Session::flash('error', 'At least one split mandate leg was rejected. See Split Legs below for details.');
        } else {
            Session::flash('success', 'Both split mandate legs submitted. Use "Check Final Fate" to confirm registration.');
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
        $legs = $this->splitLegs->forDebitOrder($id);
        $attempted = false;
        $allLoaded = true;

        foreach ($legs as $leg) {
            if ($leg['collexia_api_status'] !== 'Load Pending' || !$leg['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->requestFinalFate((string) $leg['collexia_api_contract_reference']);
                $loaded = !empty($result['mandateLoaded']);

                $this->splitLegs->updateState($id, $leg['leg'], [
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
            Session::flash('error', 'No split leg is awaiting confirmation.');
            return;
        }

        $this->rollupSplitStatus($id);

        Audit::log('Update', 'Debit Orders', 'Checked Collexia final fate for split debit order #' . $id);
        Session::flash($allLoaded ? 'success' : 'error', $allLoaded
            ? 'Both split legs confirmed registered.'
            : 'At least one split leg did not register. See Split Legs below for details.');
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
        $legs = $this->splitLegs->forDebitOrder($id);
        $attempted = false;

        foreach ($legs as $leg) {
            if (!in_array($leg['collexia_api_status'], ['Registered', 'Load Pending'], true) || !$leg['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->mandateEnquiry(['contractReference' => $leg['collexia_api_contract_reference']]);

                $this->splitLegs->updateState($id, $leg['leg'], [
                    'collexia_api_last_response' => json_encode($result),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\RuntimeException $e) {
                Session::flash('error', $e->getMessage());
                return;
            }
        }

        if (!$attempted) {
            Session::flash('error', 'Neither split leg has been placed yet.');
            return;
        }

        $this->debitOrders->updateCollexiaApiState($id, ['collexia_api_synced_at' => date('Y-m-d H:i:s')]);
        Session::flash('success', 'Synced the latest status for both split legs.');
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
        $legs = $this->splitLegs->forDebitOrder($id);
        $attempted = false;

        foreach ($legs as $leg) {
            if ($leg['collexia_api_status'] === 'Cancelled' || !$leg['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->cancelMandate((string) $leg['collexia_api_contract_reference']);

                $this->splitLegs->updateState($id, $leg['leg'], [
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
        Session::flash('success', 'Both split legs cancelled.');
    }

    /**
     * Rolls both legs' own status up onto debit_orders.collexia_api_status
     * so the existing single-badge/button-visibility UI in
     * debit_orders/show.php.content works unchanged for split orders too
     * -- worst-first: any Load Failed wins, else any Load Pending, else
     * the legs' shared status if they agree, else Registered (the more
     * actionable state) if they've drifted apart. The Split Legs detail
     * table always shows each leg's real status regardless of this rollup.
     */
    private function rollupSplitStatus(int $debitOrderId): void
    {
        $statuses = array_column($this->splitLegs->forDebitOrder($debitOrderId), 'collexia_api_status');

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
     */
    /**
     * $leg ('A'/'B') is for a split order's second mandate, which needs
     * its own distinct reference from the same debit order id -- shortens
     * the zero-padded id segment from 6 to 5 digits to make room for a
     * 1-character leg suffix while staying within the 14-character total.
     * Omitting $leg (the non-split case) is byte-for-byte the original
     * 6-digit encoding.
     */
    private function buildContractReference(int $debitOrderId, ?string $leg = null): string
    {
        $merchantGid = (int) $this->settings->get('collexia_merchant_gid');
        $gidHex = strtoupper(str_pad(dechex($merchantGid), 4, '0', STR_PAD_LEFT));
        $gidHex = substr($gidHex, -4);
        $dateSeg = date('md');
        $tellerSeg = $leg !== null
            ? str_pad((string) $debitOrderId, 5, '0', STR_PAD_LEFT) . $leg
            : str_pad((string) $debitOrderId, 6, '0', STR_PAD_LEFT);
        return $gidHex . $dateSeg . $tellerSeg;
    }
}
