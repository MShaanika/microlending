<?php

namespace App\Services;

/**
 * Collexia EnDO JSON REST API client, per "CO JSON REST API Interface
 * Specification V3.0" (15 April 2025). Covers all 8 endpoints under
 * /api/coswitchuadsrest/v3/ (spec section 5): mandate load/finalfate/
 * enquiry/cancel, and installment request/update/cancel/download.
 *
 * This is a separate, greenfield integration from the existing
 * CollexiaEndoExporter/DebitOrder* flow, which targets the older "EnDo
 * Batch v1.0" Excel file exchange -- the two are not interchangeable and
 * this client is not wired into that flow. See CollexiaV3Codes for this
 * spec's numeric code tables (distinct from CollexiaCodes' v1.0 codes).
 *
 * NOT YET IMPLEMENTED: the spec's Digital Signature requirement (added in
 * v3.0 -- see the version-control changelog and section 1's "Scope").
 * Neither the JSON structures nor the prose in this document specify the
 * signing algorithm, what gets signed, or where the signature is attached
 * (body field vs. HTTP header) -- the EnCr companion spec explicitly says
 * "this information will be shared in a separate document, due to the
 * confidential nature." Until Collexia supplies that document, requests
 * sent by this client carry no signature. digitalSignature() is a named
 * stub marking exactly where that needs to be wired in.
 */
class CollexiaEndoApiClient
{
    private const BASE_PATH = '/api/coswitchuadsrest/v3';

    private array $config;

    public function __construct()
    {
        $this->config = self::loadConfig();
    }

    /** 6.1 Request for Mandate Load -- POST /mandates/load. $mandate keys per spec 9.3. */
    public function loadMandate(array $mandate, ?string $frontEndUserName = null): array
    {
        return $this->post('/mandates/load', [
            'messageInfo' => $this->messageInfo($frontEndUserName),
            'mandate' => $mandate,
        ]);
    }

    /** 6.2 Request Final Fate for Mandate Load -- POST /mandates/finalfate. */
    public function requestFinalFate(string $contractReference, ?string $frontEndUserName = null): array
    {
        return $this->post('/mandates/finalfate', [
            'contractReference' => $contractReference,
            'merchantGid' => $this->config['merchant_gid'],
            'frontEndUserName' => $frontEndUserName ?? $this->config['front_end_username'],
            'remoteGid' => $this->config['remote_gid'],
        ]);
    }

    /**
     * 6.3 Mandate Enquiry -- POST /mandates/batch/mandateenquiry.
     * All filter fields are conditional/optional per spec 9.6 other than
     * merchantGid/remoteGid; pass only what's relevant to narrow the query.
     */
    public function mandateEnquiry(array $filters = []): array
    {
        return $this->post('/mandates/batch/mandateenquiry', array_merge([
            'merchantGid' => $this->config['merchant_gid'],
            'remoteGid' => $this->config['remote_gid'],
        ], $filters));
    }

    /** 6.4 Cancel of Mandate -- POST /mandates/cancel. */
    public function cancelMandate(string $contractReference, ?string $frontEndUserName = null): array
    {
        return $this->post('/mandates/cancel', [
            'contractReference' => $contractReference,
            'frontEndUserName' => $frontEndUserName ?? $this->config['front_end_username'],
            'remoteGid' => $this->config['remote_gid'],
            'merchantGid' => $this->config['merchant_gid'],
        ]);
    }

    /**
     * 7.1 Installment Request -- POST /installments/batch/installment.
     * Must be called to fetch the current intId(s) before updateInstallment().
     */
    public function installmentRequest(string $contractReference): array
    {
        return $this->post('/installments/batch/installment', [
            'merchantGid' => $this->config['merchant_gid'],
            'contractReference' => $contractReference,
            'remoteGid' => $this->config['remote_gid'],
        ]);
    }

    /**
     * 7.2 Update Installment -- POST /installments/batch/update.
     * $installments: list of {intId, trackingDate, scheduledDate,
     * numberOfTrackingDays, installmentAmount, collectionDay} per spec 9.15.
     */
    public function updateInstallment(array $installments, ?string $frontEndUserName = null): array
    {
        return $this->post('/installments/batch/update', [
            'messageInfo' => $this->messageInfo($frontEndUserName),
            'installment' => $installments,
        ]);
    }

    /** 7.3 Cancel Installment using Contract Reference and Installment No -- POST /installments/cancel. */
    public function cancelInstallment(string $contractReference, int $installmentNo, ?string $frontEndUserName = null): array
    {
        return $this->post('/installments/cancel', [
            'contractReference' => $contractReference,
            'installmentNo' => $installmentNo,
            'action' => 'C',
            'frontEndUserName' => $frontEndUserName ?? $this->config['front_end_username'],
            'remoteGid' => $this->config['remote_gid'],
            'merchantGid' => $this->config['merchant_gid'],
        ]);
    }

    /** 7.4 Request for Download of Payments -- POST /paymenthistory/download. Recommended times: 06:00/10:00/15:00/20:00. */
    public function downloadPayments(?string $frontEndUserName = null): array
    {
        return $this->post('/paymenthistory/download', [
            'merchantGid' => $this->config['merchant_gid'],
            'frontEndUserName' => $frontEndUserName ?? $this->config['front_end_username'],
            'remoteGid' => $this->config['remote_gid'],
        ]);
    }

    /** 9.2 Message Info, common to Load Mandate and Update Installment. */
    private function messageInfo(?string $frontEndUserName = null): array
    {
        return [
            'merchantGid' => $this->config['merchant_gid'],
            'remoteGid' => $this->config['remote_gid'],
            'messageDate' => date('Ymd'),
            'messageTime' => date('His'),
            'systemUserName' => $this->config['system_username'],
            'frontEndUserName' => $frontEndUserName ?? $this->config['front_end_username'],
        ];
    }

    /** See class docblock -- not implemented, no signing algorithm has been supplied by Collexia yet. */
    private function digitalSignature(array $payload): ?string
    {
        return null;
    }

    private function post(string $path, array $body): array
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Collexia API base_url is not configured (config/services.php: collexia.base_url).');
        }

        $ch = curl_init($baseUrl . self::BASE_PATH . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to reach Collexia: ' . $error);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        if (isset($data['errors']) || $httpCode >= 400) {
            throw new CollexiaApiException(
                $data['errors'] ?? [],
                $data['status'] ?? $httpCode,
                $data['summary'] ?? null,
            );
        }

        return $data;
    }

    private static function loadConfig(): array
    {
        $config = require ROOT_PATH . '/config/services.php';
        return $config['collexia'] ?? [];
    }
}
