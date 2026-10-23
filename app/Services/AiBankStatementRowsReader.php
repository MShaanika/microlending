<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Backup reader for bank statement PDFs that BankStatementPdfParser does not recognise or cannot verify. Sends the PDF to OpenAI
 * (same API key and model settings as AiBankStatementAnalyzer) and asks only for the transaction lines.
 *
 * A model can misread an amount, so nothing it returns is trusted: the rows go through the same arithmetic check as the exact
 * parser and are shown on the review page marked "read by AI" before anything is saved.
 *
 * Never throws; always returns a result array.
 */
class AiBankStatementRowsReader
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'opening_balance' => ['type' => ['number', 'null'], 'description' => 'Balance before the first transaction, or null if not printed'],
            'closing_balance' => ['type' => ['number', 'null'], 'description' => 'Balance after the last transaction, or null if not printed'],
            'transactions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'description' => ['type' => 'string'],
                        'money_in' => ['type' => 'number', 'description' => 'Credit/deposit amount, 0 if this line is a debit'],
                        'money_out' => ['type' => 'number', 'description' => 'Debit/withdrawal/fee amount, 0 if this line is a credit'],
                        'balance' => ['type' => ['number', 'null'], 'description' => 'Running balance printed after this line (negative if overdrawn), or null'],
                    ],
                    'required' => ['date', 'description', 'money_in', 'money_out', 'balance'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['opening_balance', 'closing_balance', 'transactions'],
        'additionalProperties' => false,
    ];

    public static function isConfigured(): bool
    {
        return (new NotificationSetting())->get('OPENAI_API_KEY') !== '';
    }

    /**
     * @return array{success: bool, rows?: array, opening?: ?float, closing?: ?float, model?: string, error?: string}
     */
    public static function read(string $pdfPath): array
    {
        $settings = new NotificationSetting();
        $apiKey = $settings->get('OPENAI_API_KEY');
        $model = $settings->get('OPENAI_MODEL', 'gpt-6-luna');
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'AI reading is not configured (no API key under Settings > AI Settings).'];
        }
        $bytes = is_file($pdfPath) ? file_get_contents($pdfPath) : false;
        if ($bytes === false || $bytes === '') {
            return ['success' => false, 'error' => 'The PDF could not be read from disk.'];
        }

        $prompt = "This is a bank statement. List EVERY transaction line in the order printed, across all pages. Do not skip, merge or invent lines.\n"
            . "- date: YYYY-MM-DD (work out the year from the statement period if lines only show day and month).\n"
            . "- description: the narration as printed.\n"
            . "- money_in: amount for credits/deposits, otherwise 0. money_out: amount for debits/withdrawals/fees/charges, otherwise 0. Amounts are always positive numbers.\n"
            . "- balance: the running balance printed after that line (negative if the statement shows it as overdrawn / Dr / minus), or null when the statement does not print one.\n"
            . "- opening_balance / closing_balance: the statement's opening and closing balances (same sign rule), or null.\n"
            . 'Do not include the opening-balance or closing-balance rows, totals, summaries or fee tables as transactions.';

        $payload = [
            'model' => $model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_file', 'filename' => 'statement.pdf', 'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes)],
                ],
            ]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'bank_statement_lines', 'schema' => self::SCHEMA, 'strict' => true]],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 180,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'Could not reach the AI provider: ' . $curlError];
        }
        $body = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => $body['error']['message'] ?? ('AI provider returned HTTP ' . $httpCode)];
        }
        $data = json_decode((string) self::outputText((array) $body), true);
        if (!is_array($data) || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['success' => false, 'error' => 'The AI did not return a usable list of transactions.'];
        }

        return self::normalise($data) + ['model' => $model];
    }

    /**
     * Turns the model's JSON into importer rows, rejecting anything that is not a plausible transaction.
     * Public and pure so it can be unit-tested without calling the API.
     *
     * @return array{success: bool, rows?: array, opening?: ?float, closing?: ?float, error?: string}
     */
    public static function normalise(array $data): array
    {
        $rows = [];
        $skipped = 0;
        foreach ($data['transactions'] as $t) {
            $date = is_string($t['date'] ?? null) ? $t['date'] : '';
            $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
            $in = is_numeric($t['money_in'] ?? null) ? round(abs((float) $t['money_in']), 2) : null;
            $out = is_numeric($t['money_out'] ?? null) ? round(abs((float) $t['money_out']), 2) : null;
            if (!$valid || $in === null || $out === null || ($in > 0 && $out > 0) || ($in === 0.0 && $out === 0.0)) {
                $skipped++;
                continue;
            }
            $balance = is_numeric($t['balance'] ?? null) ? round((float) $t['balance'], 2) : null;
            $rows[] = BankStatementPdfParser::row($date, trim((string) ($t['description'] ?? '')), $in, $out, $balance);
        }
        if ($rows === []) {
            return ['success' => false, 'error' => 'The AI did not find any transactions in this PDF.'];
        }
        // Where the statement printed no balance, carry one forward so the importer always has a running balance.
        $opening = is_numeric($data['opening_balance'] ?? null) ? round((float) $data['opening_balance'], 2) : null;
        $running = $opening;
        foreach ($rows as $i => $row) {
            if ($row['balance'] === null && $running !== null) {
                $rows[$i]['balance'] = $running = round($running + $row['money_in'] - $row['money_out'], 2);
            } elseif ($row['balance'] !== null) {
                $running = $row['balance'];
            }
        }
        return [
            'success' => true,
            'rows' => $rows,
            'opening' => $opening,
            'closing' => is_numeric($data['closing_balance'] ?? null) ? round((float) $data['closing_balance'], 2) : null,
            'skipped' => $skipped,
        ];
    }

    private static function outputText(array $body): ?string
    {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }
        foreach ($body['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return $content['text'];
                }
            }
        }
        return null;
    }
}
