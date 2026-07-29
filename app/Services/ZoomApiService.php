<?php

namespace App\Services;

use App\Models\HrmZoomSetting;

/**
 * Server-to-Server OAuth client for the Zoom REST API v2. Ported from
 * the reference workdo/ZoomMeeting package's ZoomService, using raw cURL
 * instead of Guzzle (no HTTP client dependency exists in this app, and
 * adding one for three API calls isn't worth it -- see composer.json).
 * The reference's Firebase\JWT import was unused dead code (JWT app
 * auth was deprecated by Zoom in favor of Server-to-Server OAuth) and
 * is not carried over.
 */
class ZoomApiService
{
    private const BASE_URL = 'https://api.zoom.us/v2';
    private const OAUTH_URL = 'https://zoom.us/oauth/token';

    private HrmZoomSetting $settings;

    public function __construct()
    {
        $this->settings = new HrmZoomSetting();
    }

    private function getAccessToken(): string
    {
        $apiKey = $this->settings->get('zoom_api_key');
        $apiSecret = $this->settings->get('zoom_api_secret');
        $accountId = $this->settings->get('zoom_account_id');

        if ($apiKey === '' || $apiSecret === '' || $accountId === '') {
            throw new \RuntimeException('Zoom API credentials are not configured.');
        }

        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($apiKey . ':' . $apiSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to reach Zoom: ' . $error);
        }
        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException('Failed to get Zoom access token: ' . ($data['reason'] ?? $response));
        }

        return $data['access_token'];
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $token = $this->getAccessToken();

        $ch = curl_init(self::BASE_URL . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ];
        if (in_array($method, ['POST', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to reach Zoom: ' . $error);
        }
        $data = json_decode($response, true) ?: [];
        if ($httpCode >= 400) {
            throw new \RuntimeException('Zoom API error: ' . ($data['message'] ?? $response));
        }

        return $data;
    }

    public function createMeeting(array $data): array
    {
        $payload = [
            'topic' => $data['title'],
            'type' => 2,
            'start_time' => date('c', strtotime($data['start_time'])),
            'duration' => (int) $data['duration'],
            'timezone' => 'UTC',
            'password' => $data['meeting_password'] ?? '',
            'settings' => [
                'host_video' => (bool) ($data['host_video'] ?? false),
                'participant_video' => (bool) ($data['participant_video'] ?? false),
                'waiting_room' => (bool) ($data['waiting_room'] ?? false),
                'auto_recording' => !empty($data['recording']) ? 'local' : 'none',
                'join_before_host' => false,
                'mute_upon_entry' => true,
            ],
        ];

        $result = $this->request('POST', '/users/me/meetings', $payload);

        if (empty($result['start_url']) && !empty($result['id'])) {
            $result['start_url'] = "https://zoom.us/s/{$result['id']}";
        }
        if (empty($result['join_url']) && !empty($result['id'])) {
            $result['join_url'] = "https://zoom.us/j/{$result['id']}";
        }

        return $result;
    }

    public function updateMeeting(string $meetingId, array $data): array
    {
        $payload = [
            'topic' => $data['title'],
            'start_time' => date('c', strtotime($data['start_time'])),
            'duration' => (int) $data['duration'],
            'password' => $data['meeting_password'] ?? '',
            'settings' => [
                'host_video' => (bool) ($data['host_video'] ?? false),
                'participant_video' => (bool) ($data['participant_video'] ?? false),
                'waiting_room' => (bool) ($data['waiting_room'] ?? false),
                'auto_recording' => !empty($data['recording']) ? 'local' : 'none',
            ],
        ];

        $this->request('PATCH', '/meetings/' . $meetingId, $payload);

        try {
            $meeting = $this->getMeeting($meetingId);
            return [
                'start_url' => $meeting['start_url'] ?? "https://zoom.us/s/{$meetingId}",
                'join_url' => $meeting['join_url'] ?? "https://zoom.us/j/{$meetingId}",
            ];
        } catch (\Throwable $e) {
            return [
                'start_url' => "https://zoom.us/s/{$meetingId}",
                'join_url' => "https://zoom.us/j/{$meetingId}",
            ];
        }
    }

    public function deleteMeeting(string $meetingId): void
    {
        $this->request('DELETE', '/meetings/' . $meetingId);
    }

    public function getMeeting(string $meetingId): array
    {
        return $this->request('GET', '/meetings/' . $meetingId);
    }
}
