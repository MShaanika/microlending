<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Dispatches a single outbound AI voice call via Retell AI
 * (https://api.retellai.com/v2/create-phone-call). Unlike Bland, Retell
 * does not take a freeform script per call -- the conversation prompt and
 * the "Promise to Pay Date"/"Promise to Pay Amount" extraction schema live
 * on a pre-configured Agent in Retell's own dashboard (RETELL_AGENT_ID),
 * and we only pass named variables into it via
 * retell_llm_dynamic_variables. The webhook is configured once on that
 * Agent in Retell's dashboard too, not passed per call.
 */
class RetellVoiceCallService
{
    /**
     * @param array<string,string> $dynamicVariables
     * @return array{success: bool, callId: ?string, error: ?string}
     */
    public static function dispatch(string $toPhone, array $dynamicVariables): array
    {
        $settings = new NotificationSetting();

        $apiKey = trim((string) $settings->get('RETELL_API_KEY'));
        $agentId = trim((string) $settings->get('RETELL_AGENT_ID'));
        $fromNumber = trim((string) $settings->get('RETELL_FROM_NUMBER'));

        if ($apiKey === '' || $agentId === '' || $fromNumber === '') {
            return [
                'success' => false,
                'callId' => null,
                'error' => 'AI voice calling is not configured. Please add a voice calling API key, Agent ID and From Number under Notification Settings.',
            ];
        }

        $body = [
            'from_number' => $fromNumber,
            'to_number' => $toPhone,
            'override_agent_id' => $agentId,
            'retell_llm_dynamic_variables' => $dynamicVariables,
        ];

        $ch = curl_init('https://api.retellai.com/v2/create-phone-call');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
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
                'error' => 'Could not reach the voice calling provider: ' . $curlError,
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

        $error = $data['message'] ?? ($data['error'] ?? ('Retell AI returned HTTP ' . $httpCode));

        return [
            'success' => false,
            'callId' => null,
            'error' => $error,
        ];
    }
}
