<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Dispatches a single outbound AI voice call via Bland AI
 * (https://api.bland.ai/v1/calls). The `task` string is the agent's
 * natural-language conversation instructions -- this is what makes it a
 * real two-way conversation rather than a fixed TTS message. Bland posts
 * a completion webhook (transcript/duration/disposition) and, if a
 * citation schema is configured, a separate structured-extraction
 * webhook -- both land on the same URL, see VoiceCallWebhookController.
 */
class BlandVoiceCallService
{
    /**
     * @return array{success: bool, callId: ?string, error: ?string}
     */
    public static function dispatch(string $toPhone, string $task, string $webhookUrl): array
    {
        $settings = new NotificationSetting();

        $apiKey = trim((string) $settings->get('BLAND_API_KEY'));
        if ($apiKey === '') {
            return [
                'success' => false,
                'callId' => null,
                'error' => 'AI voice calling is not configured. Please add a Bland API key under Notification Settings.',
            ];
        }

        $voice = trim((string) $settings->get('BLAND_VOICE')) ?: 'maya';
        $fromNumber = trim((string) $settings->get('BLAND_FROM_NUMBER'));
        $maxDuration = (int) $settings->get('AI_VOICE_MAX_DURATION_MINUTES', '5');
        $citationSchemaId = trim((string) $settings->get('AI_VOICE_CITATION_SCHEMA_ID'));

        $body = [
            'phone_number' => $toPhone,
            'task' => $task,
            'webhook' => $webhookUrl,
            'voice' => $voice,
            'record' => true,
            'max_duration' => $maxDuration,
        ];
        if ($fromNumber !== '') {
            $body['from'] = $fromNumber;
        }
        if ($citationSchemaId !== '') {
            $body['citation_schema_ids'] = [$citationSchemaId];
        }

        $ch = curl_init('https://api.bland.ai/v1/calls');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'authorization: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'callId' => null,
                'error' => 'Could not reach Bland AI: ' . $curlError,
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($data['call_id'])) {
            return [
                'success' => true,
                'callId' => (string) $data['call_id'],
                'error' => null,
            ];
        }

        $error = $data['message'] ?? ('Bland AI returned HTTP ' . $httpCode);

        return [
            'success' => false,
            'callId' => null,
            'error' => $error,
        ];
    }
}
