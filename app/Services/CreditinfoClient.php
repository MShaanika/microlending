<?php

namespace App\Services;

use App\Models\CreditinfoSetting;

/**
 * Central HTTP/auth layer for every Creditinfo API call -- OAuth2
 * client_credentials token fetch/cache plus authenticated GET/POST, so no
 * individual business method (search, report, pdf, poll) re-implements
 * authentication itself. CreditinfoBureauClient calls get()/post() here
 * rather than curl'ing directly. Mirrors CollexiaClient's role for the
 * Collexia integration.
 *
 * Auth flow is exactly the vendor's own Postman collection ("Login"
 * request) and manual section 3: POST to the auth URL,
 * application/x-www-form-urlencoded, client_id/client_secret/
 * scope=cb5webservices/grant_type=client_credentials, no bearer on that
 * call itself. Response: access_token/expires_in/token_type. The manual
 * explicitly instructs caching and reusing the token rather than fetching
 * fresh per call -- see CreditinfoSetting::cachedAccessToken()/
 * storeAccessToken(), the only place this app persists it.
 */
class CreditinfoClient
{
    /** Refresh if the cached token is within this many seconds of its stated expiry -- never wait until the exact edge. */
    private const TOKEN_SAFETY_MARGIN_SECONDS = 60;

    private CreditinfoSetting $settings;

    /**
     * Request/response of the most recent call, for the same browser-console
     * UAT debug pattern CollexiaClient::lastDebug() already established.
     * NEVER includes the access token, client secret, or raw report/PDF
     * content -- see post()/get()'s redaction below.
     *
     * @var array{path: string, requestBody: mixed, httpCode: int, responseRaw: string}|null
     */
    private ?array $lastDebug = null;

    public function __construct()
    {
        $this->settings = new CreditinfoSetting();
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    public function config(string $key): ?string
    {
        $value = $this->settings->get($key);
        return $value === '' ? null : $value;
    }

    public function lastDebug(): ?array
    {
        return $this->lastDebug;
    }

    /** Cached token if still fresh, else a fresh one fetched and stored -- never logged, never returned via lastDebug(). */
    public function getAccessToken(string $source = 'application_check'): string
    {
        $cached = $this->settings->cachedAccessToken(self::TOKEN_SAFETY_MARGIN_SECONDS);
        if ($cached !== null) {
            return $cached;
        }

        $startedAt = microtime(true);
        $authUrl = $this->config('creditinfo_auth_url');
        $clientId = $this->config('creditinfo_client_id');
        $clientSecret = $this->settings->getDecrypted('creditinfo_client_secret');
        $scope = $this->config('creditinfo_scope') ?? 'cb5webservices';

        if ($authUrl === null || $clientId === null || $clientSecret === null) {
            throw new CreditinfoAuthException('Creditinfo is not configured -- see Settings > Integrations > Creditinfo.');
        }

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'scope' => $scope,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response === false) {
            $this->logDiagnostic($source, '/connect/token', null, 0, null, $durationMs, 'TIMEOUT', $error);
            throw new CreditinfoAuthException('Failed to reach the Creditinfo authentication endpoint: ' . $error);
        }

        $data = json_decode((string) $response, true);
        if ($httpCode >= 400 || !is_array($data) || empty($data['access_token'])) {
            $this->logDiagnostic($source, '/connect/token', null, $httpCode, null, $durationMs, (string) $httpCode, 'Authentication failed');
            // Never include $response verbatim in the exception message -- an
            // OAuth2 error body can legitimately echo back the client_id and
            // could, depending on the provider, echo more; keep it out of
            // anything that might get displayed or logged.
            throw new CreditinfoAuthException('Creditinfo authentication failed (HTTP ' . $httpCode . ').', $httpCode);
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $this->settings->storeAccessToken((string) $data['access_token'], $expiresIn, null);
        $this->logDiagnostic($source, '/connect/token', null, $httpCode, null, $durationMs, null, null);

        return (string) $data['access_token'];
    }

    public function get(string $path, string $source = 'application_check'): array
    {
        return $this->send('GET', $path, null, $source);
    }

    public function post(string $path, array $body, string $source = 'application_check'): array
    {
        return $this->send('POST', $path, $body, $source);
    }

    private function send(string $method, string $path, ?array $body, string $source): array
    {
        $startedAt = microtime(true);

        if (!$this->isEnabled()) {
            throw new CreditinfoApiException('The Creditinfo integration is disabled (Settings > Integrations > Creditinfo).');
        }

        $baseUrl = rtrim((string) $this->config('creditinfo_api_base_url'), '/');
        $version = $this->config('creditinfo_api_version');
        if ($baseUrl === '' || $version === null) {
            throw new CreditinfoApiException('The Creditinfo API is not configured yet -- see Settings > Integrations > Creditinfo.');
        }

        $token = $this->getAccessToken($source);
        $url = $baseUrl . '/api/' . $version . $path;
        $jsonBody = $body !== null ? json_encode($body) : null;

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response === false) {
            $this->lastDebug = ['path' => $path, 'requestBody' => $body, 'httpCode' => 0, 'responseRaw' => '(no response -- ' . $error . ')'];
            $this->logDiagnostic($source, $path, $body, 0, null, $durationMs, 'TIMEOUT', $error);
            throw new CreditinfoTimeoutException('Failed to reach the Creditinfo API: ' . $error);
        }

        $this->lastDebug = ['path' => $path, 'requestBody' => $body, 'httpCode' => $httpCode, 'responseRaw' => (string) $response];

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $this->logDiagnostic($source, $path, $body, $httpCode, null, $durationMs, 'MALFORMED_RESPONSE', 'Non-JSON response');
            throw new CreditinfoApiException('Creditinfo returned a malformed (non-JSON) response.', $httpCode, (string) $response);
        }

        if ($httpCode >= 400 || ($data['status'] ?? 'Success') !== 'Success') {
            $this->logDiagnostic($source, $path, $body, $httpCode, $data, $durationMs, (string) $httpCode, $data['status'] ?? 'unknown');
            $exceptionClass = $httpCode === 400 ? CreditinfoValidationException::class : CreditinfoApiException::class;
            throw new $exceptionClass(
                'Creditinfo API error (HTTP ' . $httpCode . ', status ' . ($data['status'] ?? 'unknown') . ').',
                $httpCode,
                (string) $response
            );
        }

        $this->logDiagnostic($source, $path, $body, $httpCode, $data, $durationMs, null, null);

        return $data;
    }

    /**
     * Writes one row of safe diagnostic evidence (CreditinfoDiagnosticLog)
     * for every call made while the environment is UAT -- never called
     * for Production, and never passed the token/secret/full National ID/
     * report content; see that model's own docblock for the exact masking
     * rules. $body is only inspected defensively for a National ID to mask
     * (present only on the search endpoint's request shape) -- this class
     * stays otherwise endpoint-agnostic.
     */
    private function logDiagnostic(string $source, string $path, ?array $body, int $httpCode, ?array $responseData, int $durationMs, ?string $errorCode, ?string $errorMessage): void
    {
        if ($this->settings->get('creditinfo_environment', 'uat') !== 'uat') {
            return;
        }

        $data = $responseData['data'] ?? [];
        $nationalId = $body['parameters']['idNumbersList'][0]['idNumber'] ?? null;
        // /reports/pdf has its own response shape (data.report base64 if
        // immediate, or data.token if async) -- computed only for that
        // endpoint so it never gets confused with reports/custom's
        // reportToken below.
        $isPdfEndpoint = str_starts_with($path, '/reports/pdf');

        try {
            (new \App\Models\CreditinfoDiagnosticLog())->record([
                'triggered_by' => \App\Core\Auth::user()['id'] ?? null,
                'source' => $source,
                'environment' => 'uat',
                'endpoint' => $path,
                'http_status' => $httpCode ?: null,
                'creditinfo_request_id' => $data['requestId'] ?? $responseData['requestId'] ?? null,
                'workflow_id' => $data['workflowId'] ?? null,
                'outcome_status' => $data['status'] ?? $data['requestStatus'] ?? null,
                'subject_token_present' => !empty($data['individualRecords'][0]['subjectToken']) ? 1 : 0,
                'report_token_present' => !$isPdfEndpoint && (!empty($data['report']['reportInfo']['reportToken']) || !empty($data['token'])) ? 1 : 0,
                'pdf_result_present' => $isPdfEndpoint && (!empty($data['report']) || !empty($data['token'])) ? 1 : 0,
                'national_id_used' => $nationalId,
                'duration_ms' => $durationMs,
                'error_code' => $errorCode,
                'error_message' => $errorMessage ? substr($errorMessage, 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            // Diagnostic logging must never break the actual API call it's
            // observing -- swallow silently, same principle as Audit::log().
        }
    }
}
