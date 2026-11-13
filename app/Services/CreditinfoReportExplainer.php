<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Support\CreditinfoPlainWords;

/**
 * Asks the AI analyser to explain a Creditinfo credit report in plain words, for loan officers who are not credit experts.
 *
 * What is sent: ONLY the anonymous copy made by CreditinfoReportMinimizer -- no names, ID numbers, addresses, contact details or lender
 * names (lenders are "Lender A", "Lender B" ...). The request also tells the provider not to store it (store = false).
 *
 * What comes back is never trusted as it is:
 *  - the answer must fit a strict schema and is then cleaned field by field (allowed values only, lengths capped, no markup);
 *  - the risk level is not the AI's opinion: it follows Creditinfo's own grade or score band;
 *  - the headline facts (score, grade, probability of default) are shown only if they can be found in the report data;
 *  - every other figure the AI quotes (amounts, days, percentages) is looked up in the report data. A figure that cannot be found is
 *    counted and flagged, and the page tells the reader to check the technical details.
 *
 * The same API, key and model settings as AiBankStatementAnalyzer (Settings > AI Settings).
 */
class CreditinfoReportExplainer
{
    public const VERSION = 2;

    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_FINDINGS = 10;
    private const MAX_REASONS = 8;
    private const MAX_ACCOUNTS = 20;
    private const MAX_NOTES = 5;
    private const MAX_LENDERS = 60;

    /** Day counts that turn up in everyday explanations ("after 90 days") and are not figures taken from the report. */
    private const GENERIC_DAY_COUNTS = [30, 60, 90, 120, 180, 360, 365];

    private const INSTRUCTIONS = <<<'TXT'
You explain Creditinfo Namibia credit bureau reports to loan officers at a small Namibian lender. The loan officers are not credit experts and many are not native English speakers. Turn the report data into a short, accurate explanation in plain words.

Rules:
1. Use only facts that are in the data you are given. Never invent, estimate or work out a figure that is not there, and never add figures together yourself. If something is not in the data, leave it out or use null, and say what was unclear in "uncertainties".
2. Write short, simple sentences (reading level of a 12-year-old). Avoid jargon. If a credit word such as arrears, past due or public default is needed, explain it in the same sentence in everyday words.
3. Money is Namibian dollars. Write N$ followed by the exact amount with thousands separators, for example N$109,204 or N$43,757.85. Do not round, shorten or convert amounts. Quote days, counts, percentages and the score exactly as they appear in the data.
4. Describe the credit record, not the person's character. Never tell the lender to approve or decline, and never suggest an interest rate or a loan size.
5. Creditinfo's grades: A (709 to 900) very low risk, B (677 to 709) low risk, C (641 to 677) average risk, D (574 to 641) high risk, E (250 to 574) very high risk, XX not scored. Choose risk_level from the grade or score in the data.
6. When it helps, say overdue days as months or years (30 days is about 1 month, 365 days is 1 year), but keep the exact number of days in the account fields.
7. Lenders appear as Lender A, Lender B and so on. Use these labels. Names, ID numbers and contact details were removed on purpose, so never ask for them.
8. If the data shows that no record was found for the person, use risk_level no_record and explain in the findings that having no credit history is neither good nor bad.
9. Findings: 4 to 8 items, the most important first. status bad means a serious problem, watch means worth attention, good means a positive point, info means neutral context. Set uncertain to true when you are not sure what a field means.
10. payment_months: only if the data has a month-by-month payment record; one entry for each of the most recent 12 months, with state late when any payment was missed or overdue that month, ok when payments were on time, and no_data when there is no information. If the order or the meaning of that monthly record is not obvious from the data, return an empty list rather than guess. Otherwise return an empty list.
11. accounts: one entry for EACH open account, including the ones that are fully up to date: a loan, credit card, overdraft, retail or furniture account, cash advance and so on (kind in plain words such as Personal loan, Home loan, Credit card, Overdraft, Cash advance, Retail account, Other). Give what is still owed (owes), the amount overdue (overdue) and the days overdue (days_late) exactly as in the data, or null when the data does not say. Closed or settled accounts are not listed here: summarise them in closed_accounts_note, one sentence, or null.
12. check_next: up to 3 neutral things a loan officer could ask the applicant about, based on what the record shows. Do not give decisions.
13. Cover these when the data has them, the most serious first: how much is overdue and for how long on each account; the total overdue and the total owed; recent credit enquiries (many in a short time can mean the person is struggling to get credit); public defaults, court judgments and disputes; the payment pattern over the last months; how long the credit history is.
14. Do not call something a good sign just because it is not bad. A good sign needs evidence in the data, such as an account paid on time, no defaults or a long clean history. Having several accounts is neutral, not good.
15. Begin the summary with "This credit record". Never write "this person is". Say what matters most in one or two sentences, and if you name the risk, use exactly the wording of risk_level (very low, low, average, high or very high risk).
16. total_owed and total_overdue: fill them ONLY when the data itself states a total for the open accounts (for example a dashboard figure for the total outstanding or the total past due). Never add the accounts up yourself. Use null when the data states no total.
TXT;

    /** @var callable|null fn(array $payload, string $apiKey): array{status:int, body:?string, error:?string} */
    private $transport;
    private NotificationSetting $settings;
    private CreditinfoReportMinimizer $minimizer;

    public function __construct(?callable $transport = null, ?NotificationSetting $settings = null, ?CreditinfoReportMinimizer $minimizer = null)
    {
        $this->transport = $transport;
        $this->settings = $settings ?? new NotificationSetting();
        $this->minimizer = $minimizer ?? new CreditinfoReportMinimizer();
    }

    /**
     * @param array<string,mixed> $report the stored report (the "report" part of Creditinfo's response, or the whole response)
     * @return array{success: bool, explanation?: array<string,mixed>, error?: string}
     */
    public function explain(array $report): array
    {
        $apiKey = $this->settings->get('OPENAI_API_KEY');
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'AI explanations are not set up yet. Add an API key under Settings > AI Settings.'];
        }
        $model = $this->settings->get('OPENAI_MODEL', 'gpt-6-luna');

        try {
            $anonymous = $this->minimizer->minimize(self::sectionsOf($report));
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => 'The report could not be made anonymous safely, so nothing was sent to the AI.'];
        }
        if ($anonymous === []) {
            return ['success' => false, 'error' => 'This report has no credit record sections to explain.'];
        }
        $dataJson = (string) json_encode($anonymous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = ($this->transport ?? [$this, 'send'])($this->payload($model, $dataJson), $apiKey);
        if (($response['error'] ?? null) !== null && ($response['body'] ?? null) === null) {
            return ['success' => false, 'error' => 'Could not reach the AI provider (' . mb_substr((string) $response['error'], 0, 120) . '). Try again in a moment.'];
        }

        $status = (int) ($response['status'] ?? 0);
        $body = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300) {
            // The provider's own message helps whoever reads the log; anything shaped like an API key is blanked out first.
            $providerMessage = is_array($body) && is_string($body['error']['message'] ?? null) ? $body['error']['message'] : '';
            error_log('Creditinfo report explanation: AI provider answered HTTP ' . $status . ' ' . mb_substr((string) preg_replace('/sk-[A-Za-z0-9_\-*]+/', 'sk-...', $providerMessage), 0, 200));
            return ['success' => false, 'error' => $status === 401 ? 'The AI provider did not accept the saved API key. Check it under Settings > AI Settings.' : 'The AI provider could not produce the explanation (HTTP ' . $status . '). Try again in a moment.'];
        }
        if (!is_array($body)) {
            return ['success' => false, 'error' => 'The AI provider answered in a way that could not be read.'];
        }
        if (($body['status'] ?? 'completed') === 'incomplete') {
            return ['success' => false, 'error' => 'The explanation was cut short. Try again.'];
        }

        $text = $this->outputText($body);
        $raw = $text !== null ? json_decode($text, true) : null;
        if (!is_array($raw)) {
            return ['success' => false, 'error' => 'The AI did not return an explanation that could be read. Try again.'];
        }

        return ['success' => true, 'explanation' => $this->normalise($raw, $anonymous, $dataJson, $this->minimizer->lenders())];
    }

    /**
     * The report sections out of what was stored: the "report" object of Creditinfo's response, or the sections themselves.
     *
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    public static function sectionsOf(array $stored): array
    {
        return isset($stored['report']) && is_array($stored['report']) ? $stored['report'] : $stored;
    }

    // ---- the request -------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function payload(string $model, string $dataJson): array
    {
        return [
            'model' => $model,
            'instructions' => self::INSTRUCTIONS,
            'input' => [[
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => "Explain this anonymised Creditinfo report for a loan officer. The report data (JSON) follows.\n\n" . $dataJson]],
            ]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'credit_report_explanation', 'schema' => self::schema(), 'strict' => true]],
            // Nothing is kept at the provider.
            'store' => false,
            // A ceiling against a runaway answer, not a target: a reasoning model's hidden thinking counts against it too.
            'max_output_tokens' => 16000,
        ];
    }

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['risk_level', 'summary', 'score', 'grade', 'probability_of_default_percent', 'trend_note', 'findings', 'reasons', 'payment_months', 'accounts', 'total_owed', 'total_overdue', 'closed_accounts_note', 'check_next', 'uncertainties'],
            'properties' => [
                'risk_level' => ['type' => 'string', 'enum' => CreditinfoPlainWords::riskLevels(), 'description' => 'From Creditinfo\'s grade or score in the data.'],
                'summary' => ['type' => 'string', 'description' => 'One or two plain sentences: what this record says overall.'],
                'score' => $nullableNumber + ['description' => 'The Creditinfo score exactly as in the data, or null.'],
                'grade' => $nullableString + ['description' => 'The Creditinfo grade exactly as in the data (for example E2), or null.'],
                'probability_of_default_percent' => $nullableNumber + ['description' => 'Creditinfo\'s probability of default as a percentage exactly as in the data, or null.'],
                'trend_note' => $nullableString + ['description' => 'One plain sentence about the score trend, including what the earlier score was if the data shows it, or null.'],
                'findings' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'title', 'detail', 'uncertain'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['bad', 'watch', 'good', 'info']],
                        'title' => ['type' => 'string', 'description' => 'At most 8 words.'],
                        'detail' => ['type' => 'string', 'description' => 'One or two short sentences with the exact figures.'],
                        'uncertain' => ['type' => 'boolean'],
                    ],
                ]],
                'reasons' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'meaning'],
                    'properties' => ['code' => $nullableString, 'meaning' => ['type' => 'string', 'description' => 'One plain sentence.']],
                ]],
                'payment_months' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['month', 'state'],
                    'properties' => ['month' => ['type' => 'string', 'description' => 'YYYY-MM'], 'state' => ['type' => 'string', 'enum' => ['ok', 'late', 'no_data']]],
                ]],
                'accounts' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'lender', 'owes', 'overdue', 'days_late', 'status'],
                    'properties' => [
                        'kind' => $string,
                        'lender' => ['type' => 'string', 'description' => 'Lender A, Lender B ... exactly as in the data.'],
                        'owes' => $nullableNumber,
                        'overdue' => $nullableNumber,
                        'days_late' => $nullableNumber,
                        'status' => ['type' => 'string', 'enum' => ['behind', 'on_time', 'unknown']],
                    ],
                ]],
                'total_owed' => $nullableNumber + ['description' => 'The total still owed on open accounts, ONLY if the data states such a total; never add the accounts up yourself; otherwise null.'],
                'total_overdue' => $nullableNumber + ['description' => 'The total overdue (past due) on open accounts, ONLY if the data states such a total; never add up yourself; otherwise null.'],
                'closed_accounts_note' => $nullableString,
                'check_next' => ['type' => 'array', 'items' => $string],
                'uncertainties' => ['type' => 'array', 'items' => $string, 'description' => 'Anything in the data you could not make sense of.'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int, body:?string, error:?string}
     */
    private function send(array $payload, string $apiKey): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 100,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $body === false ? null : (string) $body, 'error' => $body === false ? $error : null];
    }

    /** @param array<string,mixed> $body */
    private function outputText(array $body): ?string
    {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }
        foreach ($body['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }
        return null;
    }

    // ---- checking what comes back ------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $raw what the AI returned
     * @param array<string,mixed> $anonymous the anonymous copy that was sent
     * @param array<string,string> $lenders alias => real lender name, from the anonymiser: kept with the explanation (encrypted) so the page can
     *        put the names back; they were never sent to the AI
     * @return array<string,mixed>
     */
    public function normalise(array $raw, array $anonymous, string $dataJson, array $lenders = []): array
    {
        $known = $this->knownNumbers($dataJson);
        $unmatched = 0;
        $check = function (string $text) use ($known, &$unmatched): bool {
            $bad = $this->unmatchedFigures($text, $known);
            $unmatched += count($bad);
            return $bad !== [];
        };
        $uncertainties = [];

        // The three headline facts are shown in large type, so each must be found in the report data itself. One that cannot be found is
        // left out (and counted) instead of being shown as a fact.
        $score = $this->number($raw['score'] ?? null, 0, 1000);
        if ($score !== null && !$this->isKnown($score, $known)) {
            $score = null;
            $unmatched++;
            $uncertainties[] = 'The score the AI quoted could not be found in the report data, so it is not shown.';
        }
        $grade = $this->grade($raw['grade'] ?? null);
        if ($grade !== null && !preg_match('/(?<![A-Za-z0-9])' . preg_quote($grade, '/') . '(?![A-Za-z0-9])/i', $dataJson)) {
            $grade = null;
            $unmatched++;
            $uncertainties[] = 'The grade the AI quoted could not be found in the report data, so it is not shown.';
        }
        $pd = $this->number($raw['probability_of_default_percent'] ?? null, 0, 100);
        if ($pd !== null && !$this->isKnown($pd, $known, true, true)) {
            $pd = null;
            $unmatched++;
            $uncertainties[] = 'The probability of default the AI quoted could not be found in the report data, so it is not shown.';
        }

        // Totals for the open accounts count only when the report itself states them (a sum the AI worked out is not a fact from the report).
        $totals = [];
        foreach (['total_owed' => 'owed', 'total_overdue' => 'overdue'] as $field => $label) {
            $value = $this->number($raw[$field] ?? null, 0, 1.0E10);
            if ($value !== null && !$this->isKnown($value, $known, true)) {
                $value = null;
                $unmatched++;
                $uncertainties[] = 'The total ' . $label . ' the AI quoted could not be found in the report data, so it is not shown.';
            }
            $totals[$label] = $value;
        }

        [$level, $note] =$this->riskLevel(is_string($raw['risk_level'] ?? null) ? $raw['risk_level'] : '', $score, $grade);
        if ($note !== null) {
            $uncertainties[] = $note;
        }

        $summary = $this->text($raw['summary'] ?? '', 600);
        $check($summary);
        $phrase = $this->riskWordingConflict($summary, $level);
        if ($phrase !== null) {
            $uncertainties[] = 'The summary says "' . $phrase . '", but the risk level shown is "' . CreditinfoPlainWords::riskLabel($level) . '", which follows Creditinfo\'s own grade. Go by the level shown.';
        }
        $trend = $this->text($raw['trend_note'] ?? '', 300);
        $check($trend);

        $findings = [];
        foreach (array_slice(is_array($raw['findings'] ?? null) ? $raw['findings'] : [], 0, self::MAX_FINDINGS) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $title = $this->text($f['title'] ?? '', 90);
            $detail = $this->text($f['detail'] ?? '', 420);
            if ($title === '' && $detail === '') {
                continue;
            }
            $findings[] = [
                'status' => in_array($f['status'] ?? '', ['bad', 'watch', 'good', 'info'], true) ? $f['status'] : 'info',
                'title' => $title,
                'detail' => $detail,
                'uncertain' => !empty($f['uncertain']),
                'unverified' => $check($title . ' ' . $detail),
            ];
        }

        $reasons = [];
        foreach (array_slice(is_array($raw['reasons'] ?? null) ? $raw['reasons'] : [], 0, self::MAX_REASONS) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $code = isset($r['code']) && is_string($r['code']) && preg_match('/^[A-Za-z0-9]{2,8}$/', trim($r['code'])) ? strtoupper(trim($r['code'])) : null;
            $meaning = $code !== null ? CreditinfoPlainWords::reasonMeaning($code) : null;
            if ($meaning === null) {
                $meaning = $this->text($r['meaning'] ?? '', 220);
                $check($meaning);
            }
            if ($meaning !== '') {
                $reasons[] = ['code' => $code, 'meaning' => $meaning];
            }
        }

        $months = [];
        foreach (is_array($raw['payment_months'] ?? null) ? $raw['payment_months'] : [] as $m) {
            if (is_array($m) && isset($m['month'], $m['state']) && is_string($m['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m['month'])
                && in_array($m['state'], ['ok', 'late', 'no_data'], true)) {
                $months[$m['month']] = ['month' => $m['month'], 'state' => $m['state']];
            }
        }
        ksort($months);
        $months = array_slice(array_values($months), -12);

        $accounts = [];
        foreach (array_slice(is_array($raw['accounts'] ?? null) ? $raw['accounts'] : [], 0, self::MAX_ACCOUNTS) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $owes = $this->number($a['owes'] ?? null, 0, 1.0E10);
            $overdue = $this->number($a['overdue'] ?? null, 0, 1.0E10);
            $days = $this->number($a['days_late'] ?? null, 0, 30000);
            $lender = isset($a['lender']) && is_string($a['lender']) && preg_match('/^Lender [A-Z0-9]{1,3}$/', trim($a['lender'])) ? trim($a['lender']) : null;
            $unverified = false;
            foreach ([[$owes, true], [$overdue, true], [$days, false]] as [$value, $rounded]) {
                if ($value !== null && !$this->isKnown($value, $known, $rounded)) {
                    $unverified = true;
                    $unmatched++;
                }
            }
            $accounts[] = [
                'kind' => $this->text($a['kind'] ?? '', 40) ?: 'Account',
                'lender' => $lender,
                'owes' => $owes,
                'overdue' => $overdue,
                'days_late' => $days === null ? null : (int) round($days),
                'status' => $this->accountStatus(is_string($a['status'] ?? null) ? $a['status'] : '', $overdue, $days),
                'unverified' => $unverified,
            ];
        }

        $closed = $this->text($raw['closed_accounts_note'] ?? '', 300);
        $check($closed);

        $checkNext = [];
        foreach (array_slice(is_array($raw['check_next'] ?? null) ? $raw['check_next'] : [], 0, 3) as $line) {
            $clean = $this->text($line, 200);
            if ($clean !== '') {
                $check($clean);
                $checkNext[] = $clean;
            }
        }
        foreach (array_slice(is_array($raw['uncertainties'] ?? null) ? $raw['uncertainties'] : [], 0, self::MAX_NOTES) as $line) {
            $clean = $this->text($line, 200);
            if ($clean !== '') {
                $uncertainties[] = $clean;
            }
        }

        // The real names of the lenders, for the page only. Only well-formed labels are kept and the names are cleaned like any other text.
        $names = [];
        foreach ($lenders as $alias => $name) {
            if (count($names) >= self::MAX_LENDERS) {
                break;
            }
            if (is_string($alias) && is_string($name) && preg_match('/^Lender ([A-Z]|\d{1,3})$/', $alias)) {
                $clean = $this->text($name, 120);
                if ($clean !== '') {
                    $names[$alias] = $clean;
                }
            }
        }

        return [
            'version' => self::VERSION,
            'risk_level' => $level,
            'summary' => $summary,
            'score' => $score,
            'grade' => $grade,
            'probability_of_default' => $pd,
            'trend_note' => $trend !== '' ? $trend : null,
            'findings' => $findings,
            'reasons' => $reasons,
            'months' => $months,
            'accounts' => $accounts,
            'totals' => $totals,
            'lenders' => $names,
            'closed_note' => $closed !== '' ? $closed : null,
            'check_next' => $checkNext,
            'uncertainties' => $uncertainties,
            'meta' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'unverified_figures' => $unmatched,
                'input_hash' => hash('sha256', $dataJson),
                'sections' => array_keys($anonymous),
            ],
        ];
    }

    /**
     * The risk level is Creditinfo's, not the AI's: the grade decides when there is one, else the score band, else what the AI said.
     * "No record" is kept when the AI said so and Creditinfo only says "not scored" (a person with no history cannot be scored).
     *
     * @return array{0:string, 1:?string} the level and, when it differs from what the AI said, a note for the reader
     */
    private function riskLevel(string $said, ?float $score, ?string $grade): array
    {
        $said = in_array($said, CreditinfoPlainWords::riskLevels(), true) ? $said : 'not_scored';

        $fromGrade = null;
        if ($grade !== null) {
            $fromGrade = ['A' => 'very_low', 'B' => 'low', 'C' => 'average', 'D' => 'high', 'E' => 'very_high'][$grade[0]] ?? ($grade === 'XX' ? 'not_scored' : null);
        }
        $derived = $fromGrade ?? ($score !== null ? CreditinfoPlainWords::levelForScore($score) : null);

        if ($derived === null || $derived === $said || ($derived === 'not_scored' && $said === 'no_record')) {
            return [$derived === null || ($derived === 'not_scored' && $said === 'no_record') ? $said : $derived, null];
        }
        return [$derived, 'The risk level shown follows Creditinfo\'s own ' . ($fromGrade !== null ? 'grade' : 'score band') . ', not the wording of the AI.'];
    }

    /**
     * The first risk phrase in the summary that disagrees with the risk level shown (a summary that says "high risk" under a "Very high risk"
     * banner), or null. Only the five scored levels are compared. The text is not rewritten: "not a low risk" must keep its meaning.
     */
    private function riskWordingConflict(string $summary, string $level): ?string
    {
        $levels = ['very low' => 'very_low', 'low' => 'low', 'average' => 'average', 'high' => 'high', 'very high' => 'very_high'];
        if (!in_array($level, $levels, true)) {
            return null;
        }
        if (preg_match_all('/\b(very low|very high|low|average|high) risk\b/i', $summary, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($levels[strtolower($match[1])] !== $level) {
                    return strtolower($match[0]);
                }
            }
        }
        return null;
    }

    /** The numbers decide whether an account is behind; the AI's word is used only when the numbers say nothing. */
    private function accountStatus(string $said, ?float $overdue, ?float $days): string
    {
        if (($overdue !== null && $overdue > 0) || ($days !== null && $days > 0)) {
            return 'behind';
        }
        if ($overdue !== null && $days !== null) {
            return 'on_time';
        }
        return in_array($said, ['behind', 'on_time', 'unknown'], true) ? $said : 'unknown';
    }

    private function grade(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $grade = strtoupper(trim($value));
        return preg_match('/^([A-E][1-3]|XX)$/', $grade) ? $grade : null;
    }

    private function number(mixed $value, float $min, float $max): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;
        return is_finite($value) && $value >= $min && $value <= $max ? round($value, 2) : null;
    }

    /** Plain text only: tags removed, white space collapsed, cut to a length without splitting a character. */
    private function text(mixed $value, int $maxChars): string
    {
        if (!is_string($value)) {
            return '';
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        return mb_strlen($clean) > $maxChars ? rtrim(mb_substr($clean, 0, $maxChars - 1)) . '…' : $clean;
    }

    // ---- looking figures up in the report ----------------------------------------------------------------------------

    /**
     * Every number that appears in the anonymous data (plus the fixed numbers of Creditinfo's grade scale), so a figure the AI quotes can be
     * matched against it. "rands" holds the same numbers rounded to a whole number, and "percents" the numbers between 0 and 1 as a percentage
     * (a probability of 0.76 is quoted as 76%).
     *
     * @return array{exact: array<string,true>, rands: array<string,true>, percents: array<string,true>}
     */
    public function knownNumbers(string $dataJson): array
    {
        $exact = [];
        $rands = [];
        $percents = [];
        $add = static function (float $v) use (&$exact, &$rands, &$percents): void {
            $exact[(string) round($v, 2)] = true;
            $rands[(string) round($v)] = true;
            if ($v > 0 && $v <= 1) {
                $percents[(string) round($v * 100, 2)] = true;
                $percents[(string) round($v * 100)] = true;
            }
        };

        foreach ([0, 100, CreditinfoPlainWords::SCORE_MIN, CreditinfoPlainWords::SCORE_MAX] as $fixed) {
            $add((float) $fixed);
        }
        foreach (CreditinfoPlainWords::gradeScale() as $band) {
            $add((float) $band['from']);
            $add((float) $band['to']);
        }

        $walk = function ($node) use (&$walk, $add): void {
            if (is_array($node)) {
                foreach ($node as $item) {
                    $walk($item);
                }
            } elseif (is_int($node) || is_float($node)) {
                $add((float) $node);
            } elseif (is_string($node) && preg_match_all('/-?\d[\d,]*(?:\.\d+)?/', $node, $m)) {
                foreach ($m[0] as $token) {
                    $add((float) str_replace(',', '', $token));
                }
            }
        };
        $walk(json_decode($dataJson, true));

        return ['exact' => $exact, 'rands' => $rands, 'percents' => $percents];
    }

    /**
     * @param array{exact: array<string,true>, rands: array<string,true>, percents: array<string,true>} $known
     * @param bool $rounded accept the amount rounded to a whole number (amounts and percentages, not scores or day counts)
     * @param bool $percent also accept a fraction in the data written as a percentage
     */
    private function isKnown(float $value, array $known, bool $rounded = false, bool $percent = false): bool
    {
        $key = (string) round($value, 2);
        if (isset($known['exact'][$key])) {
            return true;
        }
        if ($rounded && isset($known['rands'][(string) round($value)])) {
            return true;
        }
        return $percent && (isset($known['percents'][$key]) || ($rounded && isset($known['percents'][(string) round($value)])));
    }

    /**
     * Figures written in a sentence that do not appear in the report: amounts (N$...), percentages, day counts, and any other number of three
     * or more digits (a score, say). Small counts such as "6 accounts" or "10 of 12 months" are worked out by counting and cannot be looked
     * up, years are not figures, and everyday day counts ("after 90 days") are not taken from the report.
     *
     * @param array{exact: array<string,true>, rands: array<string,true>, percents: array<string,true>} $known
     * @return string[] the figures that could not be found
     */
    public function unmatchedFigures(string $text, array $known): array
    {
        $missing = [];
        $seen = [];
        $test = function (string $token, string $kind) use ($known, &$missing, &$seen): void {
            $key = $kind . ':' . $token;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $number = (float) str_replace(',', '', $token);
            $found = match ($kind) {
                'money' => $this->isKnown($number, $known, true),
                'percent' => $this->isKnown($number, $known, true, true),
                default => $this->isKnown($number, $known),
            };
            if (!$found) {
                $missing[] = $token;
            }
        };

        $rest = $text;
        if (preg_match_all('/N\$\s?(\d[\d,]*(?:\.\d+)?)/', $rest, $m)) {
            foreach ($m[1] as $token) {
                $test(rtrim($token, ','), 'money');
            }
            $rest = (string) preg_replace('/N\$\s?\d[\d,]*(?:\.\d+)?/', ' ', $rest);
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s?%/', $rest, $m)) {
            foreach ($m[1] as $token) {
                $test($token, 'percent');
            }
            $rest = (string) preg_replace('/\d+(?:\.\d+)?\s?%/', ' ', $rest);
        }
        if (preg_match_all('/(\d[\d,]*)\s+days?\b/', $rest, $m)) {
            foreach ($m[1] as $token) {
                $token = rtrim($token, ',');
                $days = (int) str_replace(',', '', $token);
                if ($days >= 10 && !in_array($days, self::GENERIC_DAY_COUNTS, true)) {
                    $test($token, 'count');
                }
            }
            $rest = (string) preg_replace('/\d[\d,]*\s+days?\b/', ' ', $rest);
        }
        if (preg_match_all('/(?<![\d.])(\d{3,}(?:,\d{3})*(?:\.\d+)?)(?![\d])/', $rest, $m)) {
            foreach ($m[1] as $token) {
                $isPlainYear = !str_contains($token, ',') && !str_contains($token, '.') && (int) $token >= 1990 && (int) $token <= 2100;
                if (!$isPlainYear) {
                    $test($token, str_contains($token, ',') ? 'money' : 'count');
                }
            }
        }

        return $missing;
    }
}
