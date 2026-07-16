<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Sends an applicant's uploaded bank statement document(s) to OpenAI for
 * structured extraction, so staff get one consolidated picture of income,
 * spending, and existing debt commitments to inform screening -- instead of
 * manually reading through PDFs.
 *
 * An applicant may have uploaded their bank statements in either shape:
 *   - ONE file covering all months (document_type = 'Bank Statement (Merged)'), or
 *   - UP TO THREE separate files, one per month (document_type = 'Bank Statement').
 * Both shapes are sent to the model together in a single request with an
 * explicit instruction not to double-count months, so the result is always
 * one consolidated analysis regardless of which shape the applicant used.
 *
 * Never throws -- always returns a result array so a bad/missing API key or
 * a flaky request degrades to a visible error rather than a fatal.
 */
class AiBankStatementAnalyzer
{
    private const ALLOWED_DOCUMENT_TYPES = ['Bank Statement (Merged)', 'Bank Statement'];
    private const MAX_FILES = 3;

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'months_covered' => ['type' => 'integer'],
            'analysis_period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'analysis_period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'average_monthly_income' => ['type' => 'number'],
            'average_monthly_expenses' => ['type' => 'number'],
            'average_closing_balance' => ['type' => 'number'],
            'existing_commitments_total' => ['type' => 'number'],
            'existing_commitments' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['Loan', 'Insurance Policy', 'Retail/Furniture Account', 'Credit Card', 'Store Card', 'Other'],
                        ],
                        'creditor_name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'monthly_amount' => ['type' => 'number'],
                    ],
                    'required' => ['type', 'creditor_name', 'description', 'monthly_amount'],
                    'additionalProperties' => false,
                ],
            ],
            'expense_breakdown' => [
                'type' => 'object',
                'properties' => [
                    'bank_fees' => ['type' => 'number'],
                    'transfers' => ['type' => 'number'],
                    'cash_withdrawals' => ['type' => 'number'],
                    'living_expenses' => ['type' => 'number'],
                    'other' => ['type' => 'number'],
                ],
                'required' => ['bank_fees', 'transfers', 'cash_withdrawals', 'living_expenses', 'other'],
                'additionalProperties' => false,
            ],
            'transactions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'description' => ['type' => 'string'],
                        'amount' => ['type' => 'number', 'description' => 'Always positive'],
                        'type' => ['type' => 'string', 'enum' => ['Debit', 'Credit']],
                        'category' => ['type' => 'string', 'enum' => ['Income', 'Bank Fees', 'Transfer', 'Cash Withdrawal', 'Living Expenses', 'Other']],
                        'running_balance' => ['type' => ['number', 'null']],
                    ],
                    'required' => ['date', 'description', 'amount', 'type', 'category', 'running_balance'],
                    'additionalProperties' => false,
                ],
            ],
            'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'nsf_count' => ['type' => 'integer'],
            'summary' => ['type' => 'string'],
        ],
        'required' => [
            'months_covered', 'analysis_period_start', 'analysis_period_end',
            'average_monthly_income', 'average_monthly_expenses', 'average_closing_balance',
            'existing_commitments_total', 'existing_commitments', 'expense_breakdown',
            'transactions', 'risk_flags', 'nsf_count', 'summary',
        ],
        'additionalProperties' => false,
    ];

    /**
     * @param array $documents Rows from loan_application_documents (any document_type; irrelevant ones are filtered out here)
     * @return array{success: bool, format?: string, documentIds?: int[], data?: array, modelUsed?: string, error?: string}
     */
    public static function analyze(array $documents): array
    {
        $settings = new NotificationSetting();
        $apiKey = $settings->get('OPENAI_API_KEY');
        $model = $settings->get('OPENAI_MODEL', 'gpt-4o-mini');

        if ($apiKey === '') {
            return ['success' => false, 'error' => 'AI analysis is not configured yet -- add an OpenAI API key under Settings > AI Settings.'];
        }

        $statements = array_values(array_filter($documents, fn ($d) => in_array($d['document_type'], self::ALLOWED_DOCUMENT_TYPES, true)));
        if (empty($statements)) {
            return ['success' => false, 'error' => 'No bank statement documents have been uploaded for this application yet.'];
        }

        $merged = array_values(array_filter($statements, fn ($d) => $d['document_type'] === 'Bank Statement (Merged)'));
        if (!empty($merged)) {
            $format = 'Merged';
            $toSend = [$merged[0]];
        } else {
            $format = 'Separate';
            $toSend = array_slice($statements, 0, self::MAX_FILES);
        }

        $fileParts = [];
        foreach ($toSend as $doc) {
            $fullPath = STORAGE_PATH . '/' . $doc['file_path'];
            if (!is_file($fullPath)) {
                continue;
            }
            $bytes = file_get_contents($fullPath);
            if ($bytes === false) {
                continue;
            }
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => null,
            };
            if ($mime === null) {
                continue;
            }

            $fileParts[] = [
                'type' => 'input_file',
                'filename' => $doc['document_name'] ?: basename($fullPath),
                'file_data' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            ];
        }

        if (empty($fileParts)) {
            return ['success' => false, 'error' => 'The uploaded bank statement file(s) could not be read from storage.'];
        }

        $instructions = $format === 'Merged'
            ? 'You are given ONE bank statement file that itself covers multiple consecutive months for one applicant. Analyze all months it contains as a single consolidated period.'
            : 'You are given up to THREE SEPARATE bank statement files for the SAME applicant, each typically covering one month. Treat them together as one consolidated multi-month period -- do NOT report figures per-file, and do NOT double count any month if files happen to overlap.';

        $prompt = $instructions . "\n\n"
            . "From the statement(s), determine:\n"
            . "- months_covered: how many distinct calendar months of activity are present overall.\n"
            . "- analysis_period_start / analysis_period_end: the earliest and latest transaction dates visible across the statement(s), as YYYY-MM-DD.\n"
            . "- average_monthly_income: average of recurring salary/income deposits per month (exclude one-off transfers between the applicant's own accounts).\n"
            . "- average_monthly_expenses: average total monthly outflows (all debits).\n"
            . "- average_closing_balance: average of the closing/end-of-month balance across the covered months.\n"
            . "- existing_commitments: every recurring monthly obligation to another company you can identify from debit order / recurring payment lines -- this is the client's existing debt/obligation picture, so be thorough. In particular look for and separately list: (1) repayments to OTHER lenders/loan/finance companies, (2) insurance or funeral policy premiums, (3) monthly accounts with retail or furniture stores (e.g. a furniture store account, appliance store account, clothing account), (4) credit card or store card payments, (5) any other recurring third-party debit order. For each one, set type to the closest match (Loan, Insurance Policy, Retail/Furniture Account, Credit Card, Store Card, or Other), creditor_name to the actual company/institution name as it appears on the statement (e.g. 'XYZ Finance', 'Old Mutual', 'Furniture City') -- use 'Unknown' only if the statement genuinely gives no identifiable name, description for any extra detail (e.g. policy/account number if visible), and monthly_amount for its typical monthly deduction. Do not include normal living expenses like groceries, fuel, or airtime here -- only recurring obligations to a specific third-party creditor.\n"
            . "- existing_commitments_total: sum of all existing_commitments monthly_amount values.\n"
            . "- expense_breakdown: total (not averaged) expenses across the whole period, split into exactly these five buckets: bank_fees (account/service/monthly fees), transfers (send money, debit orders, collections -- including the existing_commitments debit orders above), cash_withdrawals (ATM/cash withdrawals), living_expenses (purchases, payments to stores, electricity, airtime), and other (anything not fitting the above). Every debit transaction must land in exactly one bucket, and the five bucket totals must sum to the total of all debits across the period.\n"
            . "- transactions: every transaction line you can read from the statement(s), each with date (YYYY-MM-DD), description (the narration as printed), amount (always positive), type (Debit or Credit), category (Income for income credits, otherwise the matching expense_breakdown bucket name: Bank Fees, Transfer, Cash Withdrawal, Living Expenses, or Other), and running_balance (the balance shown after that line, or null if not printed).\n"
            . "- risk_flags: short plain-English flags for anything a loan officer should notice, e.g. \"High bank fees\", \"Low balance mid-month\", \"Irregular income\", \"Frequent NSF events\" -- empty list if nothing stands out.\n"
            . "- nsf_count: number of insufficient-funds / bounced-payment / dishonoured-debit events visible in the statement(s).\n"
            . "- summary: a short (2-4 sentence) plain-English narrative a loan officer can read directly, calling out anything of concern (irregular income, frequent NSFs, high existing debt load) or reassuring (stable income, healthy balance).\n\n"
            . 'If a figure cannot be determined from the document(s), use 0 (or an empty list) rather than guessing.';

        $payload = [
            'model' => $model,
            'input' => [[
                'role' => 'user',
                'content' => array_merge([['type' => 'input_text', 'text' => $prompt]], $fileParts),
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'bank_statement_analysis',
                    'schema' => self::RESPONSE_SCHEMA,
                    'strict' => true,
                ],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'Could not reach OpenAI: ' . $curlError];
        }

        $body = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $error = $body['error']['message'] ?? ('OpenAI returned HTTP ' . $httpCode);
            return ['success' => false, 'error' => $error];
        }

        $jsonText = self::extractOutputText($body);
        if ($jsonText === null) {
            return ['success' => false, 'error' => 'OpenAI response did not contain the expected analysis output.'];
        }

        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'OpenAI returned analysis that could not be parsed.'];
        }

        return [
            'success' => true,
            'format' => $format,
            'documentIds' => array_map(fn ($d) => (int) $d['id'], $toSend),
            'data' => $data,
            'modelUsed' => $model,
        ];
    }

    private static function extractOutputText(array $body): ?string
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
