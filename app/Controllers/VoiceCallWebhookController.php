<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\NotificationSetting;
use App\Services\CollectionsAiCallService;

/**
 * Public, unauthenticated endpoint Bland AI posts call completion and
 * citation-extraction events to. Security comes from the random token
 * embedded in the webhook URL itself (see
 * CollectionsAiCallService::webhookToken()) rather than a session/CSRF
 * token -- same category of endpoint as ApplicationIntakeController,
 * which is the only other unauthenticated POST route in this app.
 */
class VoiceCallWebhookController extends Controller
{
    public function receive(string $token): void
    {
        header('Content-Type: application/json');

        $expected = trim((string) (new NotificationSetting())->get('AI_VOICE_WEBHOOK_TOKEN'));

        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid token.']);
            return;
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
            return;
        }

        CollectionsAiCallService::handleWebhook($payload);

        echo json_encode(['status' => 'ok']);
    }
}
