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
 * dispatching it (trigger) and, once Bland posts back the outcome
 * (handleWebhook), turning that outcome into the exact same
 * collection_contacts / payment_promises rows a human staff member would
 * create by hand from the Collections Worklist -- see
 * CollectionsController::storeContact()/storePromise() for the records
 * this deliberately mirrors, so nothing downstream needs to know an AI
 * call was involved at all.
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

        $script = trim((string) $settings->get('AI_VOICE_SCRIPT')) ?: self::DEFAULT_SCRIPT;
        $task = NotificationMergeService::render($script, $context);

        $token = self::webhookToken();
        $webhookUrl = url('/api/voice-calls/webhook/' . $token);

        $result = BlandVoiceCallService::dispatch($phone, $task, $webhookUrl);

        (new AiVoiceCall())->create([
            'loan_id' => $loanId,
            'borrower_id' => (int) $loan['borrower_id'],
            'phone_number' => $phone,
            'provider' => 'bland',
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
     * Bland sends the completion event and, separately, a citations event
     * with any structured extractions -- both are merged into whichever
     * ai_voice_calls row matches call_id, since either can arrive first.
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

        $update = [];

        if (isset($payload['transcript'])) {
            $update['transcript'] = is_array($payload['transcript']) ? json_encode($payload['transcript']) : (string) $payload['transcript'];
        }
        if (isset($payload['duration']) || isset($payload['call_length'])) {
            $update['duration_seconds'] = (int) ($payload['duration'] ?? $payload['call_length'] ?? 0);
        }
        if (isset($payload['recording_url'])) {
            $update['recording_url'] = (string) $payload['recording_url'];
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
        if ($promiseDate !== null) {
            $update['extracted_promise_date'] = $promiseDate;
        }
        if ($promiseAmount !== null) {
            $update['extracted_promise_amount'] = $promiseAmount;
        }

        $hasPromise = $promiseDate !== null && $promiseAmount !== null;
        $outcome = self::mapOutcome($payload, $hasPromise);

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

    private static function mapOutcome(array $payload, bool $hasPromise): ?string
    {
        if ($hasPromise) {
            return 'Promised to Pay';
        }

        $signal = strtolower((string) (
            $payload['disposition'] ?? $payload['answered_by'] ?? $payload['status'] ?? ''
        ));

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
        if (str_contains($signal, 'human') || str_contains($signal, 'complet') || str_contains($signal, 'answer')) {
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
