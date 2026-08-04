<?php

namespace App\Services;

use App\Models\AiVoiceCall;
use App\Models\CollectionContact;
use App\Models\Company;
use App\Models\Loan;
use App\Models\NotificationSetting;
use App\Models\PaymentPromise;

/**
 * Orchestrates a manually-triggered AI collection call end to end:
 * dispatching it via whichever provider is configured (trigger) and, once
 * that provider posts back the outcome (handleWebhook), turning it into
 * the exact same collection_contacts / payment_promises rows a human
 * staff member would create by hand from the Collections Worklist -- see
 * CollectionsController::storeContact()/storePromise() for the records
 * this deliberately mirrors, so nothing downstream needs to know an AI
 * call was involved at all, or which provider placed it.
 *
 * Bland takes a freeform script per call (AI_VOICE_SCRIPT, merged here).
 * Retell instead runs off a pre-configured Agent in its own dashboard and
 * only takes named variables -- see RetellVoiceCallService's docblock.
 * handleWebhook() branches on the matched ai_voice_calls row's own
 * `provider` column (set at dispatch time) rather than sniffing the
 * payload shape, since that's unambiguous regardless of what either
 * provider's webhook payload happens to look like.
 */
class CollectionsAiCallService
{
    private const DEFAULT_SCRIPT = 'You are a polite, professional collections agent for {{company_name}} calling {{borrower_name}} '
        . 'about loan {{loan_no}}, which is {{days_in_arrears}} days overdue with an outstanding balance of {{outstanding_balance}}. '
        . 'Explain the situation clearly and ask whether they can make a payment, and if so, try to agree a specific date and amount '
        . 'they can commit to. Be respectful and understanding -- never threaten, never raise your voice, never mention this call to '
        . 'anyone other than the borrower themselves, and never contact them about this at their workplace. If they cannot commit to '
        . 'anything today, thank them for their time and end the call politely.';

    /**
     * @return array{sent: bool, note: string}
     */
    public static function trigger(int $loanId, ?int $userId): array
    {
        $settings = new NotificationSetting();

        if ($settings->get('AI_VOICE_ENABLED') !== '1') {
            return ['sent' => false, 'note' => 'AI voice calling is turned off. Enable it under Notification Settings first.'];
        }

        $loan = (new Loan())->find($loanId);
        if (!$loan) {
            return ['sent' => false, 'note' => 'Loan not found.'];
        }

        $phone = trim((string) ($loan['borrower_phone'] ?? ''));
        if ($phone === '') {
            return ['sent' => false, 'note' => 'This borrower has no phone number on file -- call not placed.'];
        }

        $outstanding = ArrearsService::loanOutstanding($loanId, date('Y-m-d'));

        $context = [
            'company_name' => (new Company())->displayName(),
            'borrower_name' => $loan['borrower_name'],
            'loan_no' => $loan['loan_no'],
            'days_in_arrears' => (string) ($outstanding['days_in_arrears'] ?? 0),
            'outstanding_balance' => format_money((float) ($outstanding['outstanding_balance'] ?? 0)),
        ];

        $provider = trim((string) $settings->get('AI_VOICE_PROVIDER')) ?: 'bland';

        if ($provider === 'retell') {
            $result = RetellVoiceCallService::dispatch($phone, $context);
        } else {
            $provider = 'bland';
            $script = trim((string) $settings->get('AI_VOICE_SCRIPT')) ?: self::DEFAULT_SCRIPT;
            $task = NotificationMergeService::render($script, $context);
            $webhookUrl = full_url('/api/voice-calls/webhook/' . self::webhookToken());
            $result = BlandVoiceCallService::dispatch($phone, $task, $webhookUrl);
        }

        (new AiVoiceCall())->create([
            'loan_id' => $loanId,
            'borrower_id' => (int) $loan['borrower_id'],
            'phone_number' => $phone,
            'provider' => $provider,
            'provider_call_id' => $result['callId'],
            'status' => $result['success'] ? 'Queued' : 'Failed',
            'triggered_by' => $userId,
        ]);

        return [
            'sent' => $result['success'],
            'note' => $result['success']
                ? ('AI call placed to ' . $phone . '.')
                : ('AI call not placed (' . $result['error'] . ')'),
        ];
    }

    /**
     * Called by VoiceCallWebhookController once the token is verified.
     * Each provider sends a completion event and, separately, an
     * extraction event -- both are merged into whichever ai_voice_calls
     * row matches call_id, since either can arrive first.
     */
    public static function handleWebhook(array $payload): void
    {
        $callId = (string) ($payload['call_id'] ?? '');
        if ($callId === '') {
            return;
        }

        $calls = new AiVoiceCall();
        $call = $calls->findByProviderCallId($callId);
        if (!$call) {
            return;
        }

        $parsed = ($call['provider'] ?? 'bland') === 'retell'
            ? self::parseRetellPayload($payload)
            : self::parseBlandPayload($payload);

        $update = [];
        if ($parsed['transcript'] !== null) {
            $update['transcript'] = $parsed['transcript'];
        }
        if ($parsed['duration_seconds'] !== null) {
            $update['duration_seconds'] = $parsed['duration_seconds'];
        }
        if ($parsed['recording_url'] !== null) {
            $update['recording_url'] = $parsed['recording_url'];
        }

        $promiseDate = $parsed['promise_date'];
        $promiseAmount = $parsed['promise_amount'];
        if ($promiseDate !== null) {
            $update['extracted_promise_date'] = $promiseDate;
        }
        if ($promiseAmount !== null) {
            $update['extracted_promise_amount'] = $promiseAmount;
        }

        $hasPromise = $promiseDate !== null && $promiseAmount !== null;
        $outcome = $hasPromise ? 'Promised to Pay' : $parsed['outcome'];

        if ($outcome !== null && ($update['status'] ?? $call['status']) !== 'Completed') {
            $update['status'] = 'Completed';
            $update['completed_at'] = date('Y-m-d H:i:s');
        }

        if (!empty($update)) {
            $calls->updateFromWebhook((int) $call['id'], $update);
        }

        // Only log a contact note / promise once we actually have an outcome
        // to report -- the completion event and the citations event can each
        // trigger this webhook separately, and we don't want a duplicate note.
        if ($outcome === null || $call['collection_contact_id'] !== null) {
            return;
        }

        $notes = 'AI call outcome: ' . $outcome . '.';
        if (isset($update['transcript'])) {
            $notes .= ' Transcript recorded -- see AI Call History on this case for details.';
        }

        $contactId = (new CollectionContact())->create([
            'loan_id' => (int) $call['loan_id'],
            'borrower_id' => (int) $call['borrower_id'],
            'contact_method' => 'AI Call',
            'outcome' => $outcome,
            'notes' => $notes,
            'contacted_by' => null,
        ]);

        $linkUpdate = ['collection_contact_id' => $contactId];

        if ($hasPromise) {
            $promiseId = (new PaymentPromise())->create([
                'loan_id' => (int) $call['loan_id'],
                'borrower_id' => (int) $call['borrower_id'],
                'promise_date' => $promiseDate,
                'expected_amount' => $promiseAmount,
                'notes' => 'Captured automatically from an AI collection call.',
                'status' => 'Pending',
                'created_by' => null,
            ]);
            $linkUpdate['payment_promise_id'] = $promiseId;
        }

        $calls->updateFromWebhook((int) $call['id'], $linkUpdate);
    }

    /**
     * @return array{transcript: ?string, duration_seconds: ?int, recording_url: ?string, promise_date: ?string, promise_amount: ?float, outcome: ?string}
     */
    private static function parseBlandPayload(array $payload): array
    {
        $transcript = null;
        if (isset($payload['transcript'])) {
            $transcript = is_array($payload['transcript']) ? json_encode($payload['transcript']) : (string) $payload['transcript'];
        }

        $duration = null;
        if (isset($payload['duration']) || isset($payload['call_length'])) {
            $duration = (int) ($payload['duration'] ?? $payload['call_length'] ?? 0);
        }

        $promiseDate = null;
        $promiseAmount = null;
        foreach ((array) ($payload['citations'] ?? []) as $citation) {
            $name = strtolower((string) ($citation['variable_name'] ?? ''));
            $value = trim((string) ($citation['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (str_contains($name, 'date') && $promiseDate === null && strtotime($value) !== false) {
                $promiseDate = date('Y-m-d', strtotime($value));
            }
            if (str_contains($name, 'amount') && $promiseAmount === null) {
                $numeric = preg_replace('/[^0-9.]/', '', $value);
                if ($numeric !== '' && is_numeric($numeric)) {
                    $promiseAmount = (float) $numeric;
                }
            }
        }

        $signal = strtolower((string) (
            $payload['disposition'] ?? $payload['answered_by'] ?? $payload['status'] ?? ''
        ));

        return [
            'transcript' => $transcript,
            'duration_seconds' => $duration,
            'recording_url' => isset($payload['recording_url']) ? (string) $payload['recording_url'] : null,
            'promise_date' => $promiseDate,
            'promise_amount' => $promiseAmount,
            'outcome' => self::mapSignalToOutcome($signal),
        ];
    }

    /**
     * @return array{transcript: ?string, duration_seconds: ?int, recording_url: ?string, promise_date: ?string, promise_amount: ?float, outcome: ?string}
     */
    private static function parseRetellPayload(array $payload): array
    {
        $duration = isset($payload['duration_ms']) ? (int) round(((int) $payload['duration_ms']) / 1000) : null;

        $promiseDate = null;
        $promiseAmount = null;
        $customData = $payload['call_analysis']['custom_analysis_data'] ?? [];
        foreach ((array) $customData as $key => $value) {
            $name = strtolower((string) $key);
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (str_contains($name, 'date') && $promiseDate === null && strtotime($value) !== false) {
                $promiseDate = date('Y-m-d', strtotime($value));
            }
            if (str_contains($name, 'amount') && $promiseAmount === null) {
                $numeric = preg_replace('/[^0-9.]/', '', $value);
                if ($numeric !== '' && is_numeric($numeric)) {
                    $promiseAmount = (float) $numeric;
                }
            }
        }

        $signal = strtolower((string) ($payload['disconnection_reason'] ?? $payload['call_status'] ?? ''));

        return [
            'transcript' => isset($payload['transcript']) ? (string) $payload['transcript'] : null,
            'duration_seconds' => $duration,
            'recording_url' => isset($payload['recording_url']) ? (string) $payload['recording_url'] : null,
            'promise_date' => $promiseDate,
            'promise_amount' => $promiseAmount,
            'outcome' => self::mapSignalToOutcome($signal),
        ];
    }

    private static function mapSignalToOutcome(string $signal): ?string
    {
        if ($signal === '') {
            return null;
        }
        if (str_contains($signal, 'voicemail')) {
            return 'Left Voicemail';
        }
        if (str_contains($signal, 'no-answer') || str_contains($signal, 'no_answer') || str_contains($signal, 'noanswer')) {
            return 'No Answer';
        }
        if (str_contains($signal, 'fail') || str_contains($signal, 'error')) {
            return 'Call Failed';
        }
        if (str_contains($signal, 'human') || str_contains($signal, 'complet') || str_contains($signal, 'answer') || str_contains($signal, 'hangup') || str_contains($signal, 'ended')) {
            return 'Refused to Pay';
        }

        return null;
    }

    /** Public so NotificationSettingController::testCall() can reuse it for a one-off test call. */
    public static function webhookToken(): string
    {
        $settings = new NotificationSetting();
        $token = trim((string) $settings->get('AI_VOICE_WEBHOOK_TOKEN'));
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            $settings->set('AI_VOICE_WEBHOOK_TOKEN', $token, 'AI', null);
        }
        return $token;
    }
}
