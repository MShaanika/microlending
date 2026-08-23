<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
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
 */
class DebitOrderCollexiaController extends Controller
{
    private DebitOrder $debitOrders;
    private CollexiaSetting $settings;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
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

        $contractReference = $this->buildContractReference((int) $debitOrder['id']);
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

            $this->debitOrders->updateCollexiaApiState((int) $id, [
                'collexia_api_contract_reference' => $contractReference,
                'collexia_api_status' => 'Load Pending',
                'collexia_api_last_response' => 'Mandate submitted. Call "Check Final Fate" to confirm registration.',
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::log('Update', 'Debit Orders', 'Placed Collexia API mandate for debit order #' . $id . ' (' . $contractReference . ')');
            Session::flash('success', 'Mandate submitted (' . $contractReference . '). Use "Check Final Fate" to confirm it registered.');
        } catch (CollexiaApiException $e) {
            $this->debitOrders->updateCollexiaApiState((int) $id, [
                'collexia_api_status' => 'Load Failed',
                'collexia_api_last_response' => $e->getMessage(),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);
            Session::flash('error', 'The mandate was rejected: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect('/debit-orders/' . $id);
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

    /** Step 1 of reschedule -- fetch current installments/intId(s) to pick from. */
    public function installments(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->loadOr404($id);
        if (!$debitOrder) {
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
    private function buildContractReference(int $debitOrderId): string
    {
        $merchantGid = (int) $this->settings->get('collexia_merchant_gid');
        $gidHex = strtoupper(str_pad(dechex($merchantGid), 4, '0', STR_PAD_LEFT));
        $gidHex = substr($gidHex, -4);
        $dateSeg = date('md');
        $tellerSeg = str_pad((string) $debitOrderId, 6, '0', STR_PAD_LEFT);
        return $gidHex . $dateSeg . $tellerSeg;
    }
}
