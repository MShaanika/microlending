<?php

namespace App\Services;

use App\Models\CollexiaSetting;

/**
 * Central HTTP/auth/signing layer for every Collexia API call -- HTTP
 * Basic Auth plus the three CX_SWITCH_* security headers get applied here
 * once, so no individual endpoint method (mandate load, final fate,
 * cancel, reschedule, enquiry, payment download, ...) re-implements
 * authentication or signing itself. CollexiaEndoApiClient (the EnDO V3
 * business endpoints) calls send() here rather than curl'ing directly.
 *
 * generateSignature() is a deliberate stub: Collexia confirmed HMAC-SHA512
 * + Base64 is required and that the clientId and DTS are involved, but the
 * exact key/concatenation/separator/encoding must come from Collexia's own
 * Postman pre-request script (the authoritative source) -- inventing that
 * construction would produce a signature that looks plausible but is
 * simply wrong, and Collexia would reject every request silently for the
 * wrong reason. Every send() call fails closed (throws) until that script
 * has been inspected and this method reproduces it exactly -- see
 * CollexiaSetting::SIGNING_IMPLEMENTED, which gates readiness on the same
 * fact so the UI never claims "Digital Signature: Configured" prematurely.
 */
class CollexiaClient
{
    private const BASE_PATH = '/api/coswitchuadsrest/v3';

    /** SAST is UTC+2 year-round (Namibia observes no DST) -- fixed offset, not the server's own timezone. */
    private const SAST_OFFSET_SECONDS = 2 * 3600;

    private CollexiaSetting $settings;

    public function __construct()
    {
        $this->settings = new CollexiaSetting();
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    public function config(string $key): ?string
    {
        return $this->settings->get('collexia_' . $key) ?: null;
    }

    /**
     * yyyy-MM-dd HH:mm:ss.SSS in SAST (UTC+2), generated fresh for this
     * exact call -- never cached or reused, per Collexia's 60-second clock
     * tolerance. Built from gettimeofday() (not DateTime) so millisecond
     * precision is exact rather than rounded.
     */
    public function buildTimestamp(): string
    {
        $micro = gettimeofday();
        $sastSeconds = $micro['sec'] + self::SAST_OFFSET_SECONDS;
        $millis = (int) round($micro['usec'] / 1000);
        if ($millis >= 1000) {
            $millis -= 1000;
            $sastSeconds += 1;
        }

        return gmdate('Y-m-d H:i:s', $sastSeconds) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
    }

    /** @throws \RuntimeException always, until the real construction is confirmed against Collexia's Postman script -- see class docblock. */
    private function generateSignature(string $clientId, string $dts): string
    {
        throw new \RuntimeException(
            'Collexia digital signature not implemented yet -- HMAC-SHA512 construction must be reproduced exactly '
            . 'from Collexia\'s Postman pre-request script before any request can be signed. See CollexiaClient::generateSignature().'
        );
    }

    private function buildSecurityHeaders(): array
    {
        $clientId = $this->config('client_id') ?? '';
        $dts = $this->buildTimestamp();
        $signature = $this->generateSignature($clientId, $dts);

        return [
            'CX_SWITCH_ClientId: ' . $clientId,
            'CX_SWITCH_DTS: ' . $dts,
            'CX_SWITCH_HSH: ' . $signature,
        ];
    }

    public function post(string $path, array $body): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('The Collexia API integration is disabled (Collections > Debit Order API Settings).');
        }

        $baseUrl = rtrim((string) $this->config('base_url'), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('The Collexia API is not configured yet -- see Collections > Debit Order API Settings.');
        }

        $username = $this->settings->get('collexia_system_username');
        $password = $this->settings->getDecrypted('collexia_password') ?? '';

        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $this->buildSecurityHeaders()
        );

        $ch = curl_init($baseUrl . self::BASE_PATH . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to reach the Collexia API: ' . $error);
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
}
