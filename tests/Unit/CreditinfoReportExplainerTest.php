<?php

namespace Tests\Unit;

use App\Models\NotificationSetting;
use App\Services\CreditinfoReportExplainer;
use App\Support\CreditinfoPlainWords;
use PHPUnit\Framework\TestCase;

/**
 * The AI explanation of a Creditinfo report. Nothing here calls the real AI provider: the request is handed to a fake transport, so these tests
 * check (1) what would be SENT -- only the anonymous copy, never a name, ID, address, phone, e-mail or lender name -- and (2) what is done with
 * what comes BACK: nothing the AI says is shown as it is, and a figure that cannot be found in the report is flagged.
 */
class CreditinfoReportExplainerTest extends TestCase
{
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        // The explainer logs provider failures; keep that out of the test output.
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'explainer_test_error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
    }

    // ---- fixtures ----------------------------------------------------------------------------------------------------

    /** A report in Creditinfo's section layout with personal details planted in it. */
    private function report(): array
    {
        return [
            'dashboard' => ['score' => 531, 'grade' => 'E2', 'probabilityOfDefault' => 76, 'previousScore' => 506, 'reasonCodes' => ['HMP1', 'LWM1']],
            'individual' => ['surname' => 'Shikongo', 'firstName' => 'Maria', 'nationalId' => '80050412345', 'dateOfBirth' => '1980-05-04'],
            'subjectInfoHistory' => [['address' => '12 Independence Avenue, Windhoek', 'phone' => '0811234567', 'email' => 'maria.shikongo@example.com']],
            'contracts' => [
                ['subscriberName' => 'First National Bank of Namibia', 'contractType' => 'Personal loan', 'status' => 'Open', 'contractCode' => 'FNB-998877',
                    'outstandingBalance' => 109204.35, 'overdueAmount' => 43757.85, 'daysPastDue' => 1461],
                ['subscriberName' => 'Bank Windhoek', 'contractType' => 'Credit card', 'status' => 'Open', 'contractCode' => 'BW-445566',
                    'outstandingBalance' => 5200, 'overdueAmount' => 0, 'daysPastDue' => 0],
            ],
            'inquiries' => [['subscriberName' => 'Letshego Financial Services', 'date' => '2026-10-02']],
        ];
    }

    /** What the planted personal details look like once they are inside a request. */
    private const PLANTED = [
        'Shikongo', 'Maria', '80050412345', '1980-05-04', 'Independence Avenue', '0811234567', 'maria.shikongo@example.com',
        'First National Bank', 'Bank Windhoek', 'Letshego', 'FNB-998877', 'BW-445566',
    ];

    /** A good answer to the report above. */
    private function answer(array $override = []): array
    {
        return array_replace([
            'risk_level' => 'very_high',
            'summary' => 'This record shows a very high risk. The score is 531.',
            'score' => 531,
            'grade' => 'E2',
            'probability_of_default_percent' => 76,
            'trend_note' => 'The score went up from 506 to 531, but it is still low.',
            'findings' => [
                ['status' => 'bad', 'title' => 'Far behind on one loan', 'detail' => 'Lender A has N$43,757.85 overdue, for 1,461 days.', 'uncertain' => false],
                ['status' => 'good', 'title' => 'Credit card is up to date', 'detail' => 'Lender B has nothing overdue.', 'uncertain' => false],
            ],
            'reasons' => [['code' => 'HMP1', 'meaning' => 'whatever the AI wrote']],
            'payment_months' => [['month' => '2026-09', 'state' => 'late'], ['month' => '2026-10', 'state' => 'ok']],
            'accounts' => [
                ['kind' => 'Personal loan', 'lender' => 'Lender A', 'owes' => 109204.35, 'overdue' => 43757.85, 'days_late' => 1461, 'status' => 'behind'],
                ['kind' => 'Credit card', 'lender' => 'Lender B', 'owes' => 5200, 'overdue' => 0, 'days_late' => 0, 'status' => 'on_time'],
            ],
            'total_owed' => null,
            'total_overdue' => null,
            'closed_accounts_note' => null,
            'check_next' => ['Ask why the payments on Lender A stopped.'],
            'uncertainties' => [],
        ], $override);
    }

    private function settings(string $key = 'sk-test-key', string $model = 'test-model'): NotificationSetting
    {
        return new class($key, $model) extends NotificationSetting {
            public function __construct(private string $key, private string $model)
            {
            }

            public function get(string $key, string $default = ''): string
            {
                return $key === 'OPENAI_API_KEY' ? $this->key : ($key === 'OPENAI_MODEL' ? $this->model : $default);
            }
        };
    }

    private function explainer(?callable $transport = null, string $key = 'sk-test-key'): CreditinfoReportExplainer
    {
        return new CreditinfoReportExplainer($transport, $this->settings($key));
    }

    /** A transport that records the request and answers with the given AI answer. */
    private function answering(array $answer, ?array &$captured = null): callable
    {
        return function (array $payload, string $apiKey) use ($answer, &$captured): array {
            $captured = ['payload' => $payload, 'key' => $apiKey];
            return ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]]]), 'error' => null];
        };
    }

    /** A transport that must never be reached. */
    private function neverCalled(): callable
    {
        return function (): array {
            $this->fail('Nothing should have been sent to the AI provider');
        };
    }

    /**
     * normalise() on a handwritten anonymous copy of the data.
     *
     * @param array<string, string> $lenders alias => real name, as the anonymiser would hand over
     */
    private function normalise(array $answer, ?array $data = null, array $lenders = []): array
    {
        $data ??= ['dashboard' => ['score' => 531, 'grade' => 'E2', 'probabilityOfDefault' => 76, 'previousScore' => 506],
            'contracts' => [['overdueAmount' => 43757.85, 'outstandingBalance' => 109204.35, 'daysPastDue' => 1461], ['overdueAmount' => 0, 'outstandingBalance' => 5200, 'daysPastDue' => 0]]];
        return $this->explainer()->normalise($answer, $data, (string) json_encode($data), $lenders);
    }

    // ---- what is sent ------------------------------------------------------------------------------------------------

    public function testOnlyTheAnonymousCopyIsSent(): void
    {
        $sent = null;
        $result = $this->explainer($this->answering($this->answer(), $sent))->explain($this->report());

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $request = (string) json_encode($sent['payload']);
        foreach (self::PLANTED as $secret) {
            $this->assertStringNotContainsString($secret, $request, "'$secret' must never leave the system");
        }
        $text = $sent['payload']['input'][0]['content'][0]['text'];
        foreach (self::PLANTED as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('"Lender A"', $text);
        $this->assertStringContainsString('"Lender B"', $text);
        $this->assertStringContainsString('"score":531', $text, 'the credit record itself does go to the AI');
        $this->assertStringContainsString('"overdueAmount":43757.85', $text);
    }

    public function testTheWholeResponseObjectIsUnwrappedBeforeAnonymising(): void
    {
        $plain = $this->explainer($this->answering($this->answer()))->explain($this->report());
        $wrapped = $this->explainer($this->answering($this->answer()))->explain(['status' => 'Finished', 'report' => $this->report()]);

        $this->assertTrue($wrapped['success']);
        $this->assertSame($plain['explanation']['meta']['input_hash'], $wrapped['explanation']['meta']['input_hash']);
        $this->assertSame($plain['explanation']['meta']['sections'], $wrapped['explanation']['meta']['sections']);
    }

    public function testTheRequestUsesTheSavedKeyAndModelAndKeepsNothingAtTheProvider(): void
    {
        $sent = null;
        $this->explainer($this->answering($this->answer(), $sent))->explain($this->report());

        $this->assertSame('sk-test-key', $sent['key']);
        $this->assertSame('test-model', $sent['payload']['model']);
        $this->assertFalse($sent['payload']['store'], 'OpenAI must not keep the request');
        $this->assertSame('json_schema', $sent['payload']['text']['format']['type']);
        $this->assertTrue($sent['payload']['text']['format']['strict']);
        $this->assertStringContainsString('N$', $sent['payload']['instructions']);
        $this->assertStringContainsString('Never invent', $sent['payload']['instructions']);
        $this->assertStringContainsString('Never tell the lender to approve or decline', $sent['payload']['instructions']);
        $this->assertSame('input_text', $sent['payload']['input'][0]['content'][0]['type']);
    }

    public function testTheMetaListsOnlyTheSectionsThatWereSent(): void
    {
        $result = $this->explainer($this->answering($this->answer()))->explain($this->report());

        $this->assertSame(['dashboard', 'contracts', 'inquiries'], $result['explanation']['meta']['sections']);
        $this->assertSame(hash('sha256', json_encode((new \App\Services\CreditinfoReportMinimizer())->minimize($this->report()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $result['explanation']['meta']['input_hash']);
    }

    public function testWithoutAnApiKeyNothingIsSentAndTheErrorSaysWhereToAddOne(): void
    {
        $result = $this->explainer($this->neverCalled(), '')->explain($this->report());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Settings > AI Settings', $result['error']);
    }

    public function testAReportThatCannotBeMadeAnonymousIsNotSent(): void
    {
        $report = $this->report();
        // A 13-digit number used as a field name cannot be scrubbed from inside a key, so the anonymiser refuses the whole report.
        $report['contracts'][0]['8005041234567'] = 'x';

        $result = $this->explainer($this->neverCalled())->explain($report);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nothing was sent', $result['error']);
    }

    public function testAReportWithNoCreditRecordSectionsHasNothingToExplain(): void
    {
        $result = $this->explainer($this->neverCalled())->explain([
            'individual' => ['surname' => 'Shikongo'],
            'subjectInfoHistory' => [['phone' => '0811234567']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no credit record sections', $result['error']);
    }

    public function testTheSchemaMeetsOpenAisStrictModeRules(): void
    {
        $check = function (array $node, string $path) use (&$check): void {
            $type = $node['type'] ?? null;
            if ($type === 'object') {
                $this->assertFalse($node['additionalProperties'] ?? true, "$path must forbid extra properties");
                $this->assertEqualsCanonicalizing(array_keys($node['properties']), $node['required'], "$path must list every property as required");
                foreach ($node['properties'] as $name => $child) {
                    $check($child, "$path.$name");
                }
            }
            if ($type === 'array') {
                $this->assertArrayHasKey('items', $node, "$path needs items");
                $check($node['items'], $path . '[]');
            }
        };
        $check(CreditinfoReportExplainer::schema(), 'schema');

        $schema = CreditinfoReportExplainer::schema();
        $this->assertSame(CreditinfoPlainWords::riskLevels(), $schema['properties']['risk_level']['enum']);
    }

    // ---- what comes back ---------------------------------------------------------------------------------------------

    public function testAGoodAnswerIsKeptAndNothingIsFlagged(): void
    {
        $e = $this->normalise($this->answer());

        $this->assertSame('very_high', $e['risk_level']);
        $this->assertSame(531.0, $e['score']);
        $this->assertSame('E2', $e['grade']);
        $this->assertSame(76.0, $e['probability_of_default']);
        $this->assertSame(0, $e['meta']['unverified_figures']);
        $this->assertCount(2, $e['findings']);
        $this->assertFalse($e['findings'][0]['unverified']);
        $this->assertSame('bad', $e['findings'][0]['status']);
        $this->assertCount(2, $e['accounts']);
        $this->assertSame(1461, $e['accounts'][0]['days_late']);
        $this->assertSame('behind', $e['accounts'][0]['status']);
        $this->assertSame([['month' => '2026-09', 'state' => 'late'], ['month' => '2026-10', 'state' => 'ok']], $e['months']);
        $this->assertSame(['Ask why the payments on Lender A stopped.'], $e['check_next']);
        $this->assertSame([], $e['uncertainties']);
        $this->assertArrayNotHasKey('model', $e['meta'], 'the name of the AI model is kept secret');
        $this->assertSame(CreditinfoReportExplainer::VERSION, $e['version']);
        $this->assertSame(['owed' => null, 'overdue' => null], $e['totals']);
        $this->assertSame([], $e['lenders']);
    }

    public function testTheNameOfTheAiModelIsNotKeptAnywhereInTheExplanation(): void
    {
        $result = $this->explainer($this->answering($this->answer()))->explain($this->report());

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('test-model', (string) json_encode($result['explanation']), 'the model is secret: not in the page data, the stored explanation or anything built from it');
    }

    public function testTheRealLenderNamesComeBackWithTheExplanationAndNeverGoToTheProvider(): void
    {
        $sent = null;
        $result = $this->explainer($this->answering($this->answer(), $sent))->explain($this->report());

        $this->assertSame(['Lender A' => 'First National Bank of Namibia', 'Lender B' => 'Bank Windhoek', 'Lender C' => 'Letshego Financial Services'], $result['explanation']['lenders']);
        $request = (string) json_encode($sent['payload']);
        foreach (['First National Bank', 'Bank Windhoek', 'Letshego'] as $name) {
            $this->assertStringNotContainsString($name, $request, "'$name' must never be sent");
        }
    }

    public function testLenderNamesAreCleanedAndOnlyWellFormedLabelsAreKept(): void
    {
        $e = $this->normalise($this->answer(), null, [
            'Lender A' => "  <b>Capital</b>   Credit\nNamibia ",
            'Lender 27' => 'Twenty-Seventh',
            'Lender AB' => 'bad label',
            'lender b' => 'bad label',
            'Somebody' => 'bad label',
            'Lender C' => '',
            'Lender D' => 12345,
            'Lender E' => str_repeat('x', 300),
        ]);

        $this->assertSame('Capital Credit Namibia', $e['lenders']['Lender A']);
        $this->assertSame('Twenty-Seventh', $e['lenders']['Lender 27']);
        $this->assertSame(['Lender A', 'Lender 27', 'Lender E'], array_keys($e['lenders']));
        $this->assertSame(120, mb_strlen($e['lenders']['Lender E']), 'a very long name is cut');
    }

    public function testNoMoreThanSixtyLenderNamesAreKept(): void
    {
        $lenders = [];
        foreach (range(1, 90) as $i) {
            $lenders['Lender ' . $i] = 'Name ' . $i;
        }

        $this->assertCount(60, $this->normalise($this->answer(), null, $lenders)['lenders']);
    }

    // ---- totals for the open accounts ---------------------------------------------------------------------------------

    public function testTotalsThatTheReportStatesAreKept(): void
    {
        $data = ['dashboard' => ['totalOutstandingAmount' => 125200.35, 'pastDueAmountSum' => 44907.85]];

        $e = $this->normalise($this->answer(['total_owed' => 125200.35, 'total_overdue' => 44907.85]), $data);

        $this->assertSame(['owed' => 125200.35, 'overdue' => 44907.85], $e['totals']);
    }

    public function testATotalMayBeRoundedToTheRandLikeAnyAmount(): void
    {
        $data = ['dashboard' => ['totalOutstandingAmount' => 125200.35]];

        $this->assertSame(125200.0, $this->normalise($this->answer(['total_owed' => 125200]), $data)['totals']['owed']);
    }

    public function testATotalThatIsNotInTheReportIsNotShownAndIsCounted(): void
    {
        $data = ['dashboard' => ['totalOutstandingAmount' => 125200.35]];

        $e = $this->normalise($this->answer(['total_owed' => 99999, 'total_overdue' => 5555.5]), $data);

        $this->assertSame(['owed' => null, 'overdue' => null], $e['totals'], 'a total the AI worked out itself is not a fact from the report');
        $notes = implode(' ', $e['uncertainties']);
        $this->assertStringContainsString('The total owed the AI quoted could not be found', $notes);
        $this->assertStringContainsString('The total overdue the AI quoted could not be found', $notes);
        $this->assertSame($this->unverifiedWithoutTotals($data) + 2, $e['meta']['unverified_figures'], 'each total that cannot be found is counted once');
    }

    public function testTotalsOfTheWrongTypeOrRangeAreIgnored(): void
    {
        $data = ['dashboard' => ['x' => 1]];

        foreach ([['lots', -5], [1.0E12, 99999999999.0], [true, [5]]] as [$owed, $overdue]) {
            $e = $this->normalise($this->answer(['total_owed' => $owed, 'total_overdue' => $overdue]), $data);

            $this->assertSame(['owed' => null, 'overdue' => null], $e['totals'], json_encode([$owed, $overdue]));
            $this->assertSame(0, $e['meta']['unverified_figures'] - $this->unverifiedWithoutTotals($data), 'a value that is not a plausible amount is dropped quietly, not counted');
        }
    }

    private function unverifiedWithoutTotals(array $data): int
    {
        return $this->normalise($this->answer(), $data)['meta']['unverified_figures'];
    }

    public function testTheSchemaAsksForTheTotalsAndTheAccounts(): void
    {
        $schema = CreditinfoReportExplainer::schema();

        $this->assertArrayHasKey('total_owed', $schema['properties']);
        $this->assertArrayHasKey('total_overdue', $schema['properties']);
        $this->assertSame(['number', 'null'], $schema['properties']['total_owed']['type']);
        $this->assertContains('total_owed', $schema['required']);
        $this->assertContains('total_overdue', $schema['required']);
    }

    public function testTheInstructionsAskForEveryOpenAccountAndForbidAddingUpTotals(): void
    {
        $sent = null;
        $this->explainer($this->answering($this->answer(), $sent))->explain($this->report());

        $instructions = $sent['payload']['instructions'];
        $this->assertStringContainsString('EACH open account', $instructions);
        $this->assertStringContainsString('ONLY when the data itself states a total', $instructions);
        $this->assertStringContainsString('Never add the accounts up yourself', $instructions);
    }

    /**
     * @dataProvider riskLevelCases
     */
    public function testTheRiskLevelFollowsCreditinfosGradeOrScoreNotTheAi(string $said, ?int $score, ?string $grade, string $expected, bool $noteExpected): void
    {
        $data = ['dashboard' => array_filter(['score' => $score, 'grade' => $grade], fn ($v) => $v !== null)];
        $e = $this->normalise($this->answer(['risk_level' => $said, 'score' => $score, 'grade' => $grade, 'probability_of_default_percent' => null,
            'summary' => 'x', 'trend_note' => null, 'findings' => [], 'accounts' => [], 'reasons' => [], 'payment_months' => [], 'check_next' => []]), $data);

        $this->assertSame($expected, $e['risk_level']);
        $this->assertSame($noteExpected, (bool) array_filter($e['uncertainties'], fn ($u) => str_contains($u, 'follows Creditinfo')));
    }

    public static function riskLevelCases(): array
    {
        return [
            'AI says low but the grade is E2' => ['low', 531, 'E2', 'very_high', true],
            'AI agrees with the grade' => ['very_high', 531, 'E2', 'very_high', false],
            'no grade: the score band decides' => ['low', 600, null, 'high', true],
            'band edge 709' => ['low', 709, null, 'very_low', true],
            'band edge 677' => ['average', 677, null, 'low', true],
            'band edge 641' => ['high', 641, null, 'average', true],
            'band edge 574' => ['very_high', 574, null, 'high', true],
            'just under 574' => ['high', 573, null, 'very_high', true],
            'neither: the AI\'s word is kept' => ['average', null, null, 'average', false],
            'no record stays no record when the grade is only "not scored"' => ['no_record', null, 'XX', 'no_record', false],
            'a scored level cannot hide a "not scored" grade' => ['very_low', null, 'XX', 'not_scored', true],
            'an unknown level becomes not scored' => ['banana', null, null, 'not_scored', false],
            'grade A beats everything' => ['very_high', 750, 'A1', 'very_low', true],
            'the grade wins when it and the score disagree' => ['low', 600, 'E2', 'very_high', true],
            'the grade wins the other way round too' => ['high', 531, 'B2', 'low', true],
        ];
    }

    /**
     * @dataProvider summaryWordings
     */
    public function testASummaryThatUsesAnotherRiskWordThanTheLevelShownIsFlaggedButNotRewritten(string $summary, string $level, string $grade, ?string $expectedPhrase): void
    {
        $data = ['dashboard' => ['grade' => $grade]];
        $e = $this->normalise($this->answer(['risk_level' => $level, 'grade' => $grade, 'score' => null, 'probability_of_default_percent' => null, 'summary' => $summary]), $data);

        $notes = array_values(array_filter($e['uncertainties'], fn ($u) => str_starts_with($u, 'The summary says')));
        if ($expectedPhrase === null) {
            $this->assertSame([], $notes, $summary);
        } else {
            $this->assertCount(1, $notes, $summary);
            $this->assertStringContainsString('"' . $expectedPhrase . '"', $notes[0]);
            $this->assertStringContainsString('Go by the level shown', $notes[0]);
        }
        $this->assertSame($summary, $e['summary'], 'the AI\'s words are never rewritten');
    }

    public static function summaryWordings(): array
    {
        return [
            'the same words' => ['This credit record shows a very high risk.', 'very_high', 'E2', null],
            'one level lower than the banner' => ['This credit record shows a high risk.', 'very_high', 'E2', 'high risk'],
            'one level higher than the banner' => ['This credit record shows a very high risk.', 'high', 'D1', 'very high risk'],
            'the opposite' => ['This credit record shows a low risk.', 'very_high', 'E2', 'low risk'],
            'capital letters' => ['This credit record shows a HIGH RISK.', 'very_high', 'E2', 'high risk'],
            'very low is not low' => ['This credit record shows a very low risk.', 'low', 'B1', 'very low risk'],
            'low is not very low' => ['This credit record shows a low risk.', 'very_low', 'A1', 'low risk'],
            'an average record' => ['This credit record shows an average risk.', 'average', 'C2', null],
            'the first wrong phrase is named' => ['A very high risk, not a low risk, and not an average risk.', 'very_high', 'E2', 'low risk'],
            'no risk phrase at all' => ['This credit record shows many late payments.', 'very_high', 'E2', null],
            'the word risk on its own' => ['There is a risk that payments stop.', 'very_high', 'E2', null],
            'not scored has no band to compare' => ['This credit record shows a high risk.', 'not_scored', 'XX', null],
        ];
    }

    public function testSectionsOfUnwrapsTheStoredResponseOnlyWhenItIsWrapped(): void
    {
        $sections = ['dashboard' => ['score' => 531]];

        $this->assertSame($sections, CreditinfoReportExplainer::sectionsOf($sections));
        $this->assertSame($sections, CreditinfoReportExplainer::sectionsOf(['status' => 'Finished', 'report' => $sections]));
        $this->assertSame(['report' => 'not an object', 'dashboard' => []], CreditinfoReportExplainer::sectionsOf(['report' => 'not an object', 'dashboard' => []]));
    }

    public function testHeadlineFiguresThatAreNotInTheReportAreNotShown(): void
    {
        $e = $this->normalise($this->answer(['score' => 600, 'grade' => 'A1', 'probability_of_default_percent' => 12]));

        $this->assertNull($e['score']);
        $this->assertNull($e['grade']);
        $this->assertNull($e['probability_of_default']);
        $this->assertGreaterThanOrEqual(3, $e['meta']['unverified_figures']);
        $text = implode(' ', $e['uncertainties']);
        $this->assertStringContainsString('score', $text);
        $this->assertStringContainsString('grade', $text);
        $this->assertStringContainsString('probability of default', $text);
    }

    public function testAGradeIsNotFoundInsideALongerCode(): void
    {
        $data = ['dashboard' => ['grade' => 'E22', 'note' => 'XE2Y']];
        $e = $this->normalise($this->answer(['grade' => 'E2', 'score' => null, 'probability_of_default_percent' => null]), $data);

        $this->assertNull($e['grade'], '"E2" is not "E22"');
    }

    public function testAProbabilityStoredAsAFractionMayBeQuotedAsAPercentage(): void
    {
        $data = ['dashboard' => ['score' => 531, 'probabilityOfDefault' => 0.7612]];

        foreach ([76.12, 76.0, 0.7612] as $quoted) {
            $e = $this->normalise($this->answer(['probability_of_default_percent' => $quoted]), $data);
            $this->assertNotNull($e['probability_of_default'], "$quoted should be accepted");
        }
        $this->assertNull($this->normalise($this->answer(['probability_of_default_percent' => 77]), $data)['probability_of_default']);
    }

    /**
     * @dataProvider proseFigures
     * @param string[] $expectedMissing
     */
    public function testFiguresInSentencesAreLookedUpInTheReport(string $sentence, array $expectedMissing): void
    {
        $explainer = $this->explainer();
        $data = ['dashboard' => ['score' => 531, 'grade' => 'E2', 'probabilityOfDefault' => 76, 'previousScore' => 506],
            'contracts' => [['overdueAmount' => 43757.85, 'outstandingBalance' => 109204.35, 'daysPastDue' => 1461]]];
        $known = $explainer->knownNumbers((string) json_encode($data));

        $this->assertSame($expectedMissing, $explainer->unmatchedFigures($sentence, $known), $sentence);
    }

    public static function proseFigures(): array
    {
        return [
            'exact amount' => ['Lender A is N$43,757.85 behind.', []],
            'amount rounded to the rand' => ['Lender A is N$43,758 behind.', []],
            'invented amount' => ['Lender A is N$43,000 behind.', ['43,000']],
            'two invented amounts' => ['Owes N$1,000 and N$2,000.', ['1,000', '2,000']],
            'the same invented amount twice counts once' => ['N$99,999 here and N$99,999 there.', ['99,999']],
            'zero is always fine' => ['N$0 is overdue.', []],
            'amount without the N$ sign' => ['In total 109,204 is owed.', []],
            'invented amount without the N$ sign' => ['In total 109,999 is owed.', ['109,999']],
            'exact days' => ['It is 1,461 days late.', []],
            'invented days' => ['It is 1,462 days late.', ['1,462']],
            'an everyday day count is not a figure' => ['Payments count as late after 90 days.', []],
            'a small day count is not checked' => ['It is 7 days late.', []],
            'known score' => ['The score is 531.', []],
            'invented score' => ['The score is 532.', ['532']],
            'earlier score' => ['It went up from 506.', []],
            'a year is not a figure' => ['In 2026 the score was 531.', []],
            'small counts are not figures' => ['There are 3 open accounts and 12 months of history.', []],
            'known percentage' => ['About 76% of people like this default.', []],
            'invented percentage' => ['About 80% of people like this default.', ['80']],
            'the grade scale is known' => ['Grade E means a score between 250 and 574.', []],
            'plain words' => ['Nothing to report here.', []],
        ];
    }

    public function testAPercentageInASentenceMatchesAFractionInTheReport(): void
    {
        $explainer = $this->explainer();
        $known = $explainer->knownNumbers((string) json_encode(['dashboard' => ['probabilityOfDefault' => 0.7612]]));

        $this->assertSame([], $explainer->unmatchedFigures('About 76% of similar people default.', $known));
        $this->assertSame([], $explainer->unmatchedFigures('About 76.12% of similar people default.', $known));
        $this->assertSame(['80'], $explainer->unmatchedFigures('About 80% of similar people default.', $known));
    }

    public function testASentenceWithAnInventedFigureIsFlaggedAndCounted(): void
    {
        $e = $this->normalise($this->answer(['findings' => [
            ['status' => 'bad', 'title' => 'Overdue', 'detail' => 'Lender A has N$99,999 overdue.', 'uncertain' => false],
            ['status' => 'good', 'title' => 'Card is fine', 'detail' => 'Nothing overdue on Lender B.', 'uncertain' => false],
        ]]));

        $this->assertTrue($e['findings'][0]['unverified']);
        $this->assertFalse($e['findings'][1]['unverified']);
        $this->assertSame(1, $e['meta']['unverified_figures']);
    }

    public function testFiguresInTheSummaryTrendClosedNoteAndSuggestionsAreCheckedToo(): void
    {
        $e = $this->normalise($this->answer([
            'summary' => 'The score is 999.',
            'trend_note' => 'It rose from 111 to 531.',
            'closed_accounts_note' => 'Two closed accounts with N$7,777 paid.',
            'check_next' => ['Ask about the N$8,888.'],
        ]));

        $this->assertSame(4, $e['meta']['unverified_figures'], '999, 111, 7,777 and 8,888');
    }

    public function testAnAccountsFiguresAreCheckedAndFlagged(): void
    {
        $e = $this->normalise($this->answer(['accounts' => [
            ['kind' => 'Personal loan', 'lender' => 'Lender A', 'owes' => 109204.35, 'overdue' => 43757.85, 'days_late' => 1461, 'status' => 'behind'],
            ['kind' => 'Overdraft', 'lender' => 'Lender C', 'owes' => 77777, 'overdue' => 500, 'days_late' => 88, 'status' => 'behind'],
        ]]));

        $this->assertFalse($e['accounts'][0]['unverified']);
        $this->assertTrue($e['accounts'][1]['unverified']);
        $this->assertSame(3, $e['meta']['unverified_figures'], 'owes, overdue and days of the second account');
    }

    /**
     * @dataProvider accountStatusCases
     */
    public function testAnAccountIsBehindWhenItsNumbersSayAndNotWhenTheAiSays(string $said, ?float $overdue, ?float $days, string $expected): void
    {
        $data = ['contracts' => [['overdue' => 500, 'days' => 30, 'zero' => 0]]];
        $e = $this->normalise($this->answer(['accounts' => [['kind' => 'Loan', 'lender' => 'Lender A', 'owes' => null, 'overdue' => $overdue, 'days_late' => $days, 'status' => $said]]]), $data);

        $this->assertSame($expected, $e['accounts'][0]['status']);
    }

    public static function accountStatusCases(): array
    {
        return [
            'AI says on time but money is overdue' => ['on_time', 500.0, 0.0, 'behind'],
            'AI says on time but days are late' => ['on_time', 0.0, 30.0, 'behind'],
            'AI says behind but nothing is overdue' => ['behind', 0.0, 0.0, 'on_time'],
            'the numbers are missing: the AI\'s word is kept' => ['behind', null, null, 'behind'],
            'only half the numbers: the AI\'s word is kept' => ['on_time', 0.0, null, 'on_time'],
            'an invalid word becomes unknown' => ['banana', null, null, 'unknown'],
        ];
    }

    public function testOnlyLenderAliasesAreAcceptedAsLenderLabels(): void
    {
        $e = $this->normalise($this->answer(['accounts' => [
            ['kind' => 'Loan', 'lender' => 'Standard Bank Namibia', 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown'],
            ['kind' => 'Loan', 'lender' => ' Lender C ', 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown'],
            ['kind' => 'Loan', 'lender' => 'Lender 12', 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown'],
        ]]));

        $this->assertNull($e['accounts'][0]['lender'], 'an invented lender name is never shown');
        $this->assertSame('Lender C', $e['accounts'][1]['lender']);
        $this->assertSame('Lender 12', $e['accounts'][2]['lender']);
    }

    public function testTextIsPlainShortAndOnOneLine(): void
    {
        $long = str_repeat('word ', 300);
        $e = $this->normalise($this->answer([
            'summary' => "A <b>bold</b> claim <script>alert(1)</script>\n\n  on   several   lines.",
            'findings' => [['status' => 'info', 'title' => $long, 'detail' => $long, 'uncertain' => false]],
        ]));

        $this->assertStringNotContainsString('<', $e['summary']);
        $this->assertStringNotContainsString("\n", $e['summary']);
        $this->assertStringNotContainsString('  ', $e['summary']);
        $this->assertLessThanOrEqual(90, mb_strlen($e['findings'][0]['title']));
        $this->assertLessThanOrEqual(420, mb_strlen($e['findings'][0]['detail']));
        $this->assertStringEndsWith('…', $e['findings'][0]['detail']);
    }

    public function testFindingsWithAnUnknownStatusBecomeInfoAndEmptyOnesAreDropped(): void
    {
        $e = $this->normalise($this->answer(['findings' => [
            ['status' => 'catastrophic', 'title' => 'Odd status', 'detail' => 'Fine.', 'uncertain' => 1],
            ['status' => 'bad', 'title' => '   ', 'detail' => '', 'uncertain' => false],
            'not an object',
        ]]));

        $this->assertCount(1, $e['findings']);
        $this->assertSame('info', $e['findings'][0]['status']);
        $this->assertTrue($e['findings'][0]['uncertain']);
    }

    public function testPaymentMonthsAreValidatedSortedAndLimitedToTwelve(): void
    {
        $months = [
            ['month' => '2026-03', 'state' => 'late'], ['month' => '2026-01', 'state' => 'ok'], ['month' => '2026-13', 'state' => 'ok'],
            ['month' => 'March', 'state' => 'ok'], ['month' => '2026-02', 'state' => 'weird'], ['month' => '2026-01', 'state' => 'no_data'],
        ];
        $e = $this->normalise($this->answer(['payment_months' => $months]));

        $this->assertSame([['month' => '2026-01', 'state' => 'no_data'], ['month' => '2026-03', 'state' => 'late']], $e['months'], 'invalid rows dropped, a repeated month replaced, oldest first');

        $many = [];
        foreach (range(1, 18) as $i) {
            $many[] = ['month' => date('Y-m', strtotime("2025-01-01 +$i months")), 'state' => 'ok'];
        }
        $e = $this->normalise($this->answer(['payment_months' => $many]));
        $this->assertCount(12, $e['months']);
        $this->assertSame(date('Y-m', strtotime('2025-01-01 +18 months')), $e['months'][11]['month'], 'the most recent twelve are kept');
    }

    public function testKnownReasonCodesUseOurOwnWordingAndUnknownOnesKeepTheAis(): void
    {
        $e = $this->normalise($this->answer(['reasons' => [
            ['code' => 'hmp1', 'meaning' => 'something the AI made up'],
            ['code' => 'ZZZ9', 'meaning' => 'A new reason Creditinfo added.'],
            ['code' => null, 'meaning' => 'A reason without a code.'],
            ['code' => 'bad code!', 'meaning' => 'Code with odd characters.'],
            ['code' => 'NPI2', 'meaning' => ''],
        ]]));

        $this->assertSame('HMP1', $e['reasons'][0]['code']);
        $this->assertSame(CreditinfoPlainWords::reasonMeaning('HMP1'), $e['reasons'][0]['meaning']);
        $this->assertSame(['code' => 'ZZZ9', 'meaning' => 'A new reason Creditinfo added.'], $e['reasons'][1]);
        $this->assertNull($e['reasons'][2]['code']);
        $this->assertNull($e['reasons'][3]['code']);
        $this->assertSame(CreditinfoPlainWords::reasonMeaning('NPI2'), $e['reasons'][4]['meaning'], 'a known code needs no wording from the AI');
    }

    public function testListsAreCapped(): void
    {
        $finding = ['status' => 'info', 'title' => 'T', 'detail' => 'D', 'uncertain' => false];
        $account = ['kind' => 'Loan', 'lender' => 'Lender A', 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown'];
        $e = $this->normalise($this->answer([
            'findings' => array_fill(0, 15, $finding),
            'accounts' => array_fill(0, 25, $account),
            'reasons' => array_fill(0, 12, ['code' => 'ZZZ9', 'meaning' => 'x']),
            'check_next' => array_fill(0, 6, 'Ask.'),
            'uncertainties' => array_fill(0, 9, 'Unclear.'),
        ]));

        $this->assertCount(10, $e['findings']);
        $this->assertCount(20, $e['accounts']);
        $this->assertCount(8, $e['reasons']);
        $this->assertCount(3, $e['check_next']);
        $this->assertCount(5, $e['uncertainties']);
    }

    public function testAnswersOfTheWrongShapeDoNotBreakTheExplanation(): void
    {
        $e = $this->normalise([
            'risk_level' => ['x'], 'summary' => ['not text'], 'score' => 'five hundred', 'grade' => 5, 'probability_of_default_percent' => '76',
            'trend_note' => 7, 'findings' => 'none', 'reasons' => null, 'payment_months' => 'no', 'accounts' => [5, null],
            'closed_accounts_note' => [], 'check_next' => 'ask', 'uncertainties' => [['nested']],
        ]);

        $this->assertSame('not_scored', $e['risk_level']);
        $this->assertSame('', $e['summary']);
        $this->assertNull($e['score']);
        $this->assertNull($e['grade']);
        $this->assertNull($e['probability_of_default']);
        $this->assertNull($e['trend_note']);
        $this->assertSame([], $e['findings']);
        $this->assertSame([], $e['reasons']);
        $this->assertSame([], $e['months']);
        $this->assertSame([], $e['accounts']);
        $this->assertNull($e['closed_note']);
        $this->assertSame([], $e['check_next']);
        $this->assertSame([], $e['uncertainties']);
    }

    public function testAScoreOutsideTheRealRangeIsNotShown(): void
    {
        $data = ['dashboard' => ['score' => 5310]];
        $e = $this->normalise($this->answer(['score' => 5310, 'grade' => null, 'probability_of_default_percent' => 250]), $data);

        $this->assertNull($e['score']);
        $this->assertNull($e['probability_of_default']);
    }

    // ---- when the provider does not deliver --------------------------------------------------------------------------

    public function testAnAnswerInTheShortOutputTextFormIsRead(): void
    {
        $answer = $this->answer();
        $transport = fn () => ['status' => 200, 'body' => json_encode(['output_text' => json_encode($answer)]), 'error' => null];

        $result = $this->explainer($transport)->explain($this->report());

        $this->assertTrue($result['success']);
        $this->assertSame('very_high', $result['explanation']['risk_level']);
    }

    public function testAnUnreachableProviderGivesAFriendlyError(): void
    {
        $result = $this->explainer(fn () => ['status' => 0, 'body' => null, 'error' => 'Operation timed out after 100001 milliseconds'])->explain($this->report());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not reach the AI provider', $result['error']);
        $this->assertStringContainsString('Try again', $result['error']);
    }

    public function testARejectedKeyTellsTheUserToCheckTheKeyWithoutRevealingIt(): void
    {
        $result = $this->explainer(fn () => ['status' => 401, 'body' => json_encode(['error' => ['message' => 'Incorrect API key provided: sk-test-key']]), 'error' => null])->explain($this->report());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Settings > AI Settings', $result['error']);
        $this->assertStringNotContainsString('sk-test-key', $result['error']);
    }

    public function testAProviderErrorNeverPassesTheProvidersMessageToTheScreen(): void
    {
        $result = $this->explainer(fn () => ['status' => 500, 'body' => json_encode(['error' => ['message' => 'Internal detail: org-AbC123 quota']]), 'error' => null])->explain($this->report());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
        $this->assertStringNotContainsString('org-AbC123', $result['error']);
    }

    /**
     * @dataProvider unusableAnswers
     */
    public function testAnAnswerThatCannotBeUsedGivesAFriendlyError(array $response, string $expectedPart): void
    {
        $result = $this->explainer(fn () => $response)->explain($this->report());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($expectedPart, $result['error']);
    }

    public static function unusableAnswers(): array
    {
        return [
            'the body is not JSON' => [['status' => 200, 'body' => '<html>gateway</html>', 'error' => null], 'could not be read'],
            'the answer was cut short' => [['status' => 200, 'body' => json_encode(['status' => 'incomplete', 'output' => []]), 'error' => null], 'cut short'],
            'there is no output at all' => [['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => []]), 'error' => null], 'could be read'],
            'the output is not JSON' => [['status' => 200, 'body' => json_encode(['output_text' => 'Sorry, I cannot help with that.']), 'error' => null], 'could be read'],
            'the output is JSON but not an object' => [['status' => 200, 'body' => json_encode(['output_text' => '"hello"']), 'error' => null], 'could be read'],
        ];
    }
}
