<?php

namespace App\Services;

/**
 * Collexia EnDO JSON REST API client, per "CO JSON REST API Interface
 * Specification V3.0" (15 April 2025, spec section 9 -- Structures).
 * Covers all 8 endpoints under /api/coswitchuadsrest/v3/: mandate load/
 * finalfate/enquiry/cancel, and installment request/update/cancel/download.
 *
 * This is a separate, greenfield integration from the existing
 * CollexiaEndoExporter/DebitOrder* flow, which targets the older "EnDo
 * Batch v1.0" Excel file exchange -- the two are not interchangeable and
 * this client is not wired into that flow. See CollexiaV3Codes for this
 * spec's numeric code tables (distinct from CollexiaCodes' v1.0 codes).
 *
 * HTTP transport, Basic Auth, and the CX_SWITCH_* signing headers all live
 * in CollexiaClient -- every method below just builds a JSON body and
 * calls $this->client->post(), never curl directly.
 *
 * frontEndUserName is NOT a Collexia-provisioned credential and is never
 * read from settings -- it's an operational/audit identity, per spec
 * "Username of the user on the system that is requesting..." (9.4, 9.10,
 * 9.16 mark it Required; 9.2 Message Info marks it Conditional). Every
 * method below that sends it takes the caller's own username as a
 * parameter -- DebitOrderCollexiaController passes Auth::user()['username']
 * for every user-triggered action. mandateEnquiry() and installmentRequest()
 * genuinely have no frontEndUserName field at all per spec 9.6/9.12 and
 * never send one. downloadPayments() is the one exception: spec 9.18's own
 * comment says this field "will be the System Username ... provided to you
 * by Softsplice" -- i.e. the same value as systemUserName, not a per-user
 * identity -- which is exactly what a background cron job needs since
 * there is no logged-in user to attribute it to.
 *
 * Credentials and the enabled/disabled toggle are read (via CollexiaClient)
 * from CollexiaSetting (DB-backed, editable at /collexia/settings/manage),
 * not a config file -- so staff can enter Collexia's values through the
 * interface once supplied, without a code deploy.
 */
class CollexiaEndoApiClient
{
    private CollexiaClient $client;

    public function __construct()
    {
        $this->client = new CollexiaClient();
    }

    /** 6.1 Request for Mandate Load -- POST /mandates/load. $mandate keys per spec 9.3. */
    public function loadMandate(array $mandate, string $frontEndUserName): array
    {
        return $this->client->post('/mandates/load', [
            'messageInfo' => $this->messageInfo($frontEndUserName),
            'mandate' => $mandate,
        ]);
    }

    /** 6.2 Request Final Fate for Mandate Load -- POST /mandates/finalfate. frontEndUserName is Required (spec 9.4). */
    public function requestFinalFate(string $contractReference, string $frontEndUserName): array
    {
        return $this->client->post('/mandates/finalfate', [
            'contractReference' => $contractReference,
            'merchantGid' => $this->client->configInt('merchant_gid'),
            'frontEndUserName' => $frontEndUserName,
            'remoteGid' => $this->client->configInt('remote_gid'),
        ]);
    }

    /**
     * 6.3 Mandate Enquiry -- POST /mandates/batch/mandateenquiry.
     * All filter fields are conditional/optional per spec 9.6 other than
     * merchantGid/remoteGid; pass only what's relevant to narrow the query.
     * No frontEndUserName field exists in this request per spec 9.6.
     */
    public function mandateEnquiry(array $filters = []): array
    {
        return $this->client->post('/mandates/batch/mandateenquiry', array_merge([
            'merchantGid' => $this->client->configInt('merchant_gid'),
            'remoteGid' => $this->client->configInt('remote_gid'),
        ], $filters));
    }

    /** 6.4 Cancel of Mandate -- POST /mandates/cancel. frontEndUserName is Required (spec 9.10). */
    public function cancelMandate(string $contractReference, string $frontEndUserName): array
    {
        return $this->client->post('/mandates/cancel', [
            'contractReference' => $contractReference,
            'frontEndUserName' => $frontEndUserName,
            'remoteGid' => $this->client->configInt('remote_gid'),
            'merchantGid' => $this->client->configInt('merchant_gid'),
        ]);
    }

    /**
     * 7.1 Installment Request -- POST /installments/batch/installment.
     * Must be called to fetch the current intId(s) before updateInstallment().
     * No frontEndUserName field exists in this request per spec 9.12.
     */
    public function installmentRequest(string $contractReference): array
    {
        return $this->client->post('/installments/batch/installment', [
            'merchantGid' => $this->client->configInt('merchant_gid'),
            'contractReference' => $contractReference,
            'remoteGid' => $this->client->configInt('remote_gid'),
        ]);
    }

    /**
     * 7.2 Update Installment -- POST /installments/batch/update.
     * $installments: list of {intId, trackingDate, scheduledDate,
     * numberOfTrackingDays, installmentAmount, collectionDay} per spec 9.15.
     */
    public function updateInstallment(array $installments, string $frontEndUserName): array
    {
        return $this->client->post('/installments/batch/update', [
            'messageInfo' => $this->messageInfo($frontEndUserName),
            'installment' => $installments,
        ]);
    }

    /** 7.3 Cancel Installment using Contract Reference and Installment No -- POST /installments/cancel. frontEndUserName is Required (spec 9.16). */
    public function cancelInstallment(string $contractReference, int $installmentNo, string $frontEndUserName): array
    {
        return $this->client->post('/installments/cancel', [
            'contractReference' => $contractReference,
            'installmentNo' => $installmentNo,
            'action' => 'C',
            'frontEndUserName' => $frontEndUserName,
            'remoteGid' => $this->client->configInt('remote_gid'),
            'merchantGid' => $this->client->configInt('merchant_gid'),
        ]);
    }

    /**
     * 7.4 Request for Download of Payments -- POST /paymenthistory/download.
     * Recommended times: 06:00/10:00/15:00/20:00 -- called from
     * bin/download_collexia_payments.php with no logged-in user, hence
     * frontEndUserName = systemUserName per spec 9.18's own comment (see
     * class docblock), not an invented "system actor" label.
     */
    public function downloadPayments(): array
    {
        return $this->client->post('/paymenthistory/download', [
            'merchantGid' => $this->client->configInt('merchant_gid'),
            'frontEndUserName' => $this->client->config('system_username'),
            'remoteGid' => $this->client->configInt('remote_gid'),
        ]);
    }

    /** 9.2 Message Info, common to Load Mandate and Update Installment. */
    private function messageInfo(string $frontEndUserName): array
    {
        return [
            'merchantGid' => $this->client->configInt('merchant_gid'),
            'remoteGid' => $this->client->configInt('remote_gid'),
            'messageDate' => date('Ymd'),
            'messageTime' => date('His'),
            'systemUserName' => $this->client->config('system_username'),
            'frontEndUserName' => $frontEndUserName,
        ];
    }
}
