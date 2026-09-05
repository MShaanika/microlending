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
 * Signature construction is reproduced exactly from Collexia's own
 * Postman pre-request script (DigitalSignatureScript.txt, supplied
 * 2026-09-05) -- not invented. See computeSignature() for the byte-for-byte
 * match: stringToSign = clientId . dts (no separator), HMAC-SHA512 keyed
 * by the Client Secret, Base64-encoded.
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
     * SAST (UTC+2) date/time parts for one instant, each zero-padded to
     * the script's exact widths -- the single source both buildTimestamp()
     * and buildContractReference()/buildUserReference() draw from, so a
     * mandate placed right on a second boundary can't see the DTS and the
     * contractReference disagree about what second it is.
     *
     * millis uses floor(), not round(): the Postman script reads
     * milliseconds straight off a native JS Date, which never produces
     * 1000 -- rounding gettimeofday()'s microseconds could, which would
     * silently break the fixed-width format. Floor matches JS exactly.
     */
    public static function sastComponentsFromEpoch(int $epochSeconds, int $microseconds): array
    {
        $sastSeconds = $epochSeconds + self::SAST_OFFSET_SECONDS;
        $millis = (int) floor($microseconds / 1000);

        return [
            'year' => gmdate('Y', $sastSeconds),
            'month' => gmdate('m', $sastSeconds),
            'day' => gmdate('d', $sastSeconds),
            'hours' => gmdate('H', $sastSeconds),
            'minutes' => gmdate('i', $sastSeconds),
            'seconds' => gmdate('s', $sastSeconds),
            'millis' => str_pad((string) $millis, 3, '0', STR_PAD_LEFT),
        ];
    }

    private static function sastNow(): array
    {
        $now = gettimeofday();
        return self::sastComponentsFromEpoch($now['sec'], $now['usec']);
    }

    /**
     * yyyy-MM-dd HH:mm:ss.SSS in SAST -- generated fresh for this exact
     * call, never cached or reused, per Collexia's 60-second clock
     * tolerance.
     */
    public static function buildTimestamp(): string
    {
        $c = self::sastNow();
        return self::formatTimestamp($c);
    }

    public static function formatTimestamp(array $c): string
    {
        return "{$c['year']}-{$c['month']}-{$c['day']} {$c['hours']}:{$c['minutes']}:{$c['seconds']}.{$c['millis']}";
    }

    /**
     * 14 chars: Merchant GID in hex (4, uppercase, zero-padded) + MMDD (4)
     * + HHmmss (6) -- per the Postman script. Unique across calls made in
     * different seconds; NOT unique for two calls in the same second (the
     * script itself only claims "unique every second"). Safe for a single
     * mandate placement (one contractReference generated once). Split
     * mandate placement calls this several times in a tight loop and is
     * NOT yet switched to this method for that reason -- see
     * DebitOrderCollexiaController::placeSplitMandate() and the
     * implementation report for the flagged conflict.
     */
    public static function contractReferenceFromParts(int $merchantGid, array $c): string
    {
        $gid = strtoupper(str_pad(dechex($merchantGid), 4, '0', STR_PAD_LEFT));
        return $gid . $c['month'] . $c['day'] . $c['hours'] . $c['minutes'] . $c['seconds'];
    }

    public function buildContractReference(): string
    {
        $merchantGid = (int) ($this->config('merchant_gid') ?? 0);
        return self::contractReferenceFromParts($merchantGid, self::sastNow());
    }

    /**
     * Per the Postman script: (seconds . millis), last 6 chars, + 4 random
     * alphanumeric chars. seconds is always 2 digits and millis always 3,
     * so seconds.millis is only 5 characters -- JS's slice(-6) on a
     * 5-char string returns the whole string (out-of-range negative start
     * clamps to 0), not 6. The script's own comment claims "10 chars
     * total"; the actual math it performs produces 9. Reproduced exactly
     * as the script behaves, not as its comment claims -- see the
     * implementation report.
     */
    public static function userReferenceFromParts(array $c, string $randomPart): string
    {
        $timeAnchor = substr($c['seconds'] . $c['millis'], -6);
        return $timeAnchor . $randomPart;
    }

    public static function randomAlphanumeric(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $rand = '';
        for ($i = 0; $i < $length; $i++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $rand;
    }

    public function buildUserReference(): string
    {
        return self::userReferenceFromParts(self::sastNow(), self::randomAlphanumeric(4));
    }

    /**
     * The pure signature math, isolated from settings/DB so it's directly
     * unit-testable with fixed inputs -- see tests/Unit/CollexiaClientTest.php.
     * stringToSign = clientId . dts, no separator; HMAC-SHA512 keyed by
     * $clientSecret; Base64 of the raw digest. Exactly the Postman script's
     * steps 4-5.
     */
    public static function computeSignature(string $clientId, string $dts, string $clientSecret): string
    {
        return base64_encode(hash_hmac('sha512', $clientId . $dts, $clientSecret, true));
    }

    private function generateSignature(string $clientId, string $dts): string
    {
        $clientSecret = $this->settings->getDecrypted('collexia_client_secret');
        if ($clientSecret === null || $clientSecret === '') {
            throw new \RuntimeException('Collexia Client Secret is not configured -- cannot sign requests.');
        }

        return self::computeSignature($clientId, $dts, $clientSecret);
    }

    private function buildSecurityHeaders(): array
    {
        $clientId = $this->config('client_id') ?? '';
        $dts = self::buildTimestamp();
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
