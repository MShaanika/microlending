<?php

namespace Tests\Feature;

use App\Core\View;
use App\Support\CreditinfoPlainWords as Words;
use PHPUnit\Framework\TestCase;

/**
 * What the Credit Bureau Assessment page shows in each state: not yet explained, explained, no AI key, a NIL report, a purged one, still
 * processing, failed. Renders the real template; any PHP notice, warning or deprecation inside it fails the test, and so does anything the AI
 * wrote reaching the page as markup.
 */
class CreditinfoAssessmentViewTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        ob_start();
        try {
            View::renderFragment('creditinfo/assessment', $data);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> */
    private function cache(array $override = []): array
    {
        return array_merge([
            'id' => 41, 'application_id' => 20, 'is_uat_test_id' => 0, 'search_outcome' => 'SubjectFound', 'report_status' => 'Finished',
            'workflow_id' => 'WF-REF-1', 'request_id' => 'REQ-REF-1', 'national_id_used' => '80050412345', 'created_at' => '2026-11-12 14:22:00', 'purged_at' => null,
        ], $override);
    }

    /** @return array<string, mixed> */
    private function data(array $override = []): array
    {
        return array_merge([
            'title' => 'Credit Bureau Assessment',
            'application' => ['id' => 20],
            'cache' => $this->cache(),
            'hasPdf' => false,
            'hasReportToken' => true,
            'reportData' => ['dashboard' => ['score' => 531, 'grade' => 'E2'], 'judgements' => []],
            'purged' => false,
            'retentionActive' => false,
            'retentionDays' => null,
            'explanation' => null,
            'aiConfigured' => true,
        ], $override);
    }

    /** An explanation in the shape CreditinfoReportExplainer::normalise() produces. */
    private function explanation(array $override = []): array
    {
        return array_replace([
            'version' => 1,
            'risk_level' => 'very_high',
            'summary' => 'This credit record shows a very high risk. Most of the debt is badly overdue.',
            'score' => 531.0,
            'grade' => 'E2',
            'probability_of_default' => 76.0,
            'trend_note' => 'The score went up from 506 to 531, but it is still low.',
            'findings' => [
                ['status' => 'bad', 'title' => 'Far behind on one loan', 'detail' => 'Lender A has N$43,757.85 overdue, for 1,461 days.', 'uncertain' => false, 'unverified' => false],
                ['status' => 'good', 'title' => 'Card is up to date', 'detail' => 'Lender B has nothing overdue.', 'uncertain' => true, 'unverified' => false],
                ['status' => 'watch', 'title' => 'Many enquiries', 'detail' => 'There were 9 enquiries in 12 months.', 'uncertain' => false, 'unverified' => true],
                ['status' => 'info', 'title' => 'No public defaults', 'detail' => '', 'uncertain' => false, 'unverified' => false],
            ],
            'reasons' => [['code' => 'HMP1', 'meaning' => 'The most recent record shows poor payment behaviour.'], ['code' => null, 'meaning' => 'A reason without a code.']],
            'months' => [['month' => '2026-01', 'state' => 'late'], ['month' => '2026-02', 'state' => 'ok'], ['month' => '2026-03', 'state' => 'no_data']],
            'accounts' => [
                ['kind' => 'Personal loan', 'lender' => 'Lender A', 'owes' => 109204.35, 'overdue' => 43757.85, 'days_late' => 1461, 'status' => 'behind', 'unverified' => false],
                ['kind' => 'Credit card', 'lender' => 'Lender B', 'owes' => 5200.0, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'on_time', 'unverified' => true],
                ['kind' => 'Overdraft', 'lender' => null, 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown', 'unverified' => false],
            ],
            'totals' => ['owed' => null, 'overdue' => null],
            'lenders' => ['Lender A' => 'Capital Credit Namibia', 'Lender B' => 'Bank Windhoek'],
            'closed_note' => 'Four closed accounts, all settled.',
            'check_next' => ['Ask why the payments on Lender A stopped.'],
            'uncertainties' => ['A field called "xyz" was not clear.'],
            'meta' => ['generated_at' => '2026-11-13 10:30:00', 'unverified_figures' => 2, 'input_hash' => 'h', 'sections' => ['dashboard', 'contracts']],
        ], $override);
    }

    // ---- a report, not yet explained ---------------------------------------------------------------------------------

    public function testAReportNotYetExplainedOffersTheButtonAndSaysWhatIsSent(): void
    {
        $html = $this->render($this->data());

        $this->assertStringContainsString('Explain this report', $html);
        $this->assertStringContainsString('/applications/20/creditinfo/explain"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('anonymous copy', $html);
        $this->assertStringContainsString('no names, ID numbers, addresses, phone numbers or lender names', $html);
        $this->assertStringContainsString('never approves or declines', $html);
        $this->assertStringContainsString('data-busy-label', $html, 'the long AI call needs a busy label on the button');
        $this->assertStringNotContainsString('class="card ci-verdict', $html);
        $this->assertStringContainsString('How to read a Creditinfo report', $html);
        $this->assertStringContainsString('Technical details', $html);
    }

    public function testWithoutAnAiKeyTheButtonIsDisabledAndSaysWhereToAddOne(): void
    {
        $html = $this->render($this->data(['aiConfigured' => false]));

        $this->assertStringContainsString('Settings &gt; AI Settings', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-info" disabled>/', $html);
        $this->assertStringNotContainsString('/creditinfo/explain"', $html, 'there must be no form that posts without a key');
    }

    public function testTheTechnicalDetailsShowExactlyWhatTheAiWouldReceive(): void
    {
        $copy = ['dashboard' => ['scoring' => ['score' => 531]], 'contracts' => [['subscriber' => 'Lender A', 'pastDueDays' => 1461]]];

        $html = $this->render($this->data(['aiCopy' => $copy, 'aiCopyState' => 'ok']));

        $this->assertStringContainsString('What the AI receives', $html);
        $this->assertStringContainsString('only this anonymous copy of the report is sent', $html);
        $this->assertStringContainsString('&quot;subscriber&quot;: &quot;Lender A&quot;', $html);
        $this->assertStringContainsString('&quot;pastDueDays&quot;: 1461', $html);
        $this->assertStringContainsString('do not use the button', $html, 'and what to do if it looks wrong');
    }

    public function testTheAnonymousCopyIsEscapedLikeEverythingElse(): void
    {
        $html = $this->render($this->data(['aiCopy' => ['dashboard' => ['x' => '<script>alert(1)</script>']], 'aiCopyState' => 'ok']));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /** @dataProvider reportsThatCannotBeSent */
    public function testAReportThatCannotBeSentSwitchesTheButtonOffAndSaysWhy(string $state, string $reason): void
    {
        $html = $this->render($this->data(['aiCopy' => [], 'aiCopyState' => $state]));

        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-info" disabled>/', $html);
        $this->assertStringNotContainsString('/creditinfo/explain"', $html);
        $this->assertSame(2, substr_count($html, $reason), 'said next to the button and again under "What the AI receives"');
        $this->assertStringNotContainsString('only this anonymous copy of the report is sent', $html);
    }

    public static function reportsThatCannotBeSent(): array
    {
        return [
            'an identifier could not be removed' => ['unsafe', 'cannot be made anonymous safely'],
            'no credit record sections' => ['empty', 'no credit record sections'],
        ];
    }

    public function testAnExplanationCannotBeRequestedAgainWhenTheReportCannotBeSent(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(), 'aiCopy' => [], 'aiCopyState' => 'unsafe']));

        $this->assertStringContainsString('Far behind on one loan', $html, 'the explanation already made is still shown');
        $this->assertStringNotContainsString('Explain again', $html);
        $this->assertStringNotContainsString('/creditinfo/explain"', $html);
    }

    public function testWithoutAKeyTheReasonIsTheKeyEvenIfTheCopyIsFine(): void
    {
        $html = $this->render($this->data(['aiConfigured' => false, 'aiCopy' => ['dashboard' => ['a' => 1]], 'aiCopyState' => 'ok']));

        $this->assertStringContainsString('Settings &gt; AI Settings', $html);
        $this->assertStringNotContainsString('cannot be made anonymous safely', $html);
        $this->assertStringContainsString('What the AI receives', $html, 'the copy can still be inspected');
    }

    // ---- an explanation ----------------------------------------------------------------------------------------------

    public function testAnExplanationShowsTheVerdictTheFactsTheFindingsAndTheAccounts(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        // the verdict
        $this->assertStringContainsString('ci-verdict ci-lv-very_high', $html);
        $this->assertStringContainsString('Very high risk', $html);
        $this->assertStringContainsString('This credit record shows a very high risk.', $html);
        $this->assertStringContainsString('>531<', $html);
        $this->assertStringContainsString('>E2<', $html);
        $this->assertStringContainsString('76%', $html);
        $this->assertStringContainsString('about 76 in every 100 similar people', $html);
        $this->assertStringContainsString('The score went up from 506 to 531', $html);
        // the gauge: five bands, the marker where the score is
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('Credit score 531.', $html);
        $this->assertStringContainsString('--ci-pos: ' . Words::gaugePosition(531.0) . '%', $html);
        $this->assertSame(5, substr_count($html, 'class="ci-seg '));
        $this->assertSame(1, substr_count($html, ' is-active'), 'only the applicant\'s band is lit');
        // findings with a word as well as a colour
        $this->assertStringContainsString('Far behind on one loan', $html);
        $this->assertStringContainsString('Serious', $html);
        $this->assertStringContainsString('Good sign', $html);
        $this->assertStringContainsString('Watch', $html);
        $this->assertStringContainsString('Note', $html);
        $this->assertStringContainsString('Not sure', $html);
        // reasons
        $this->assertStringContainsString('HMP1', $html);
        $this->assertStringContainsString('A reason without a code.', $html);
        // months
        $this->assertStringContainsString('Payments over the last 3 months', $html);
        $this->assertStringContainsString('Late or missed', $html);
        $this->assertStringContainsString('Paid on time', $html);
        $this->assertStringContainsString('<small>2026</small>', $html, 'the first month carries its year');
        // accounts, with the lenders' real names
        $this->assertStringContainsString('Capital Credit Namibia', $html);
        $this->assertStringContainsString('N$109,204.35', $html);
        $this->assertStringContainsString('N$43,757.85', $html);
        $this->assertStringContainsString('1,461 days', $html);
        $this->assertMatchesRegularExpression('/ci-tile is-bad"><span class="ci-tile-label">Behind on payments/', $html);
        $this->assertStringContainsString('about 4 years', $html);
        $this->assertStringContainsString('N$5,200', $html);
        $this->assertStringContainsString('Four closed accounts, all settled.', $html);
        // the real names replace the labels in the AI's own words too
        $this->assertStringContainsString('Capital Credit Namibia has N$43,757.85 overdue, for 1,461 days.', $html);
        $this->assertStringContainsString('Bank Windhoek has nothing overdue.', $html);
        $this->assertStringNotContainsString('Lender A has N$43,757.85', $html);
        // suggestions, the AI note and the way to ask again
        $this->assertStringContainsString('You could ask the applicant', $html);
        $this->assertStringContainsString('Ask why the payments on Capital Credit Namibia stopped.', $html);
        $this->assertStringContainsString('Explained by the AI analyser on 13 Nov 2026 10:30', $html);
        $this->assertStringContainsString('Explain again', $html);
        $this->assertStringNotContainsString('Explain this report', $html);
    }

    // ---- loans and accounts right now ----------------------------------------------------------------------------------

    public function testTheLoansCardStatesWhatIsOverdueAndWhatIsActiveWithTheAmountsAndTheRealNames(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertStringContainsString('Loans and accounts right now', $html);
        // the figures at the top
        $this->assertMatchesRegularExpression('/Open accounts<\/span><span class="ci-tile-value">3</', $html);
        $this->assertMatchesRegularExpression('/Behind on payments<\/span><span class="ci-tile-value">1</', $html);
        $this->assertMatchesRegularExpression('/Still owed<\/span><span class="ci-tile-value">N\$114,404\.35</', $html, 'the accounts shown add up: 109,204.35 + 5,200');
        $this->assertMatchesRegularExpression('/Overdue<\/span><span class="ci-tile-value">N\$43,757\.85</', $html);
        $this->assertStringContainsString('added up from the accounts below', $html);
        // the sentences a loan officer can read out
        $this->assertStringContainsString('The applicant has 3 open loans or accounts. 1 of them is behind on payments, with N$43,757.85 overdue on the accounts shown.', $html);
        $this->assertStringContainsString('The biggest overdue amount is N$43,757.85 (Personal loan, Capital Credit Namibia), 1,461 days (about 4 years) late.', $html);
        $this->assertStringContainsString('The accounts shown add up to N$114,404.35 still owed.', $html);
        // the groups, in the order that matters
        $overdue = strpos($html, 'Overdue now');
        $active = strpos($html, 'Active and up to date');
        $unclear = strpos($html, 'Open, status not clear');
        $this->assertNotFalse($overdue);
        $this->assertGreaterThan($overdue, $active);
        $this->assertGreaterThan($active, $unclear);
        $this->assertStringContainsString('1 account, N$43,757.85 overdue', $html);
        $this->assertStringContainsString('1 account, N$5,200 still owed', $html);
        // each lender by name, with its amounts
        $this->assertSame(1, substr_count($html, '<strong>Capital Credit Namibia</strong>'));
        $this->assertSame(1, substr_count($html, '<strong>Bank Windhoek</strong>'));
        $this->assertStringContainsString('Lender not named', $html, 'an account with no lender label says so');
    }

    public function testTheTableLeadsWithWhatIsOverdueAndEveryRowHasAsManyCellsAsTheHeader(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertMatchesRegularExpression('/<th>Lender and account<\/th><th class="text-end">Overdue<\/th><th>How late<\/th><th class="text-end">Still owes<\/th>/', $html, 'the overdue amount comes before what is still owed');
        $this->assertSame(3, substr_count($html, '<th colspan="4" scope="colgroup">'), 'one heading row per group, as wide as the table');
        preg_match('/<table class="table ci-accounts.*?<\/table>/s', $html, $table);
        preg_match_all('/<tr>\s*<td>.*?<\/tr>/s', $table[0], $rows);
        $this->assertCount(3, $rows[0]);
        foreach ($rows[0] as $row) {
            $this->assertSame(4, substr_count($row, '<td'), 'four cells, like the header');
        }
        $this->assertStringContainsString('<br><span class="text-muted small">Personal loan</span>', $html, 'the kind of account sits under the lender');
        // On a phone each figure is shown with its own label (CSS turns the rows into blocks), so every figure cell must carry one.
        $this->assertSame(3, substr_count($html, 'data-label="Overdue"'));
        $this->assertSame(3, substr_count($html, 'data-label="How late"'));
        $this->assertSame(3, substr_count($html, 'data-label="Still owes"'));
        $this->assertStringContainsString('.ci-plain .ci-accounts td[data-label]::before { content: attr(data-label);', $html);
    }

    public function testALongAmountGetsAWideTileSoItIsNeverCutInTheMiddle(): void
    {
        $wide = $this->render($this->data(['explanation' => $this->explanation(['totals' => ['owed' => 12136984.5, 'overdue' => 5.0]])]));
        $short = $this->render($this->data(['explanation' => $this->explanation(['totals' => ['owed' => 5000.0, 'overdue' => 5.0]])]));

        $this->assertStringContainsString('class="ci-tile ci-tile-money is-wide"><span class="ci-tile-label">Still owed</span><span class="ci-tile-value">N$12,136,984.50<', $wide);
        $this->assertStringContainsString('class="ci-tile ci-tile-money"><span class="ci-tile-label">Still owed</span><span class="ci-tile-value">N$5,000<', $short);
        $this->assertDoesNotMatchRegularExpression('/ci-tile-money[^"]*is-wide"><span class="ci-tile-label">Overdue/', $wide, 'a short amount keeps a normal tile');
    }

    public function testTheLoansCardComesBeforeTheFindings(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertLessThan(strpos($html, 'In plain words'), strpos($html, 'Loans and accounts right now'), 'the first thing after the verdict');
        $this->assertGreaterThan(strpos($html, 'class="card ci-verdict'), strpos($html, 'Loans and accounts right now'));
    }

    public function testTotalsFromTheReportAreShownAsTheReportsAndADifferenceIsFlagged(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['totals' => ['owed' => 200000.0, 'overdue' => 43757.85]])]));

        $this->assertMatchesRegularExpression('/Still owed<\/span><span class="ci-tile-value">N\$200,000</', $html);
        $this->assertStringContainsString('from the credit report', $html);
        $this->assertStringContainsString('In total N$200,000 is still owed.', $html);
        $this->assertStringContainsString('do not match the accounts listed below', $html, 'the report says 200,000 but the accounts shown add up to 114,404.35');

        $agree = $this->render($this->data(['explanation' => $this->explanation(['totals' => ['owed' => 114404.35, 'overdue' => 43757.85]])]));
        $this->assertStringNotContainsString('do not match the accounts listed below', $agree);
    }

    public function testWhenNothingIsOverdueTheCardSaysSoInPlainWords(): void
    {
        $accounts = [['kind' => 'Cash loan', 'lender' => 'Lender B', 'owes' => 4801.0, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'on_time', 'unverified' => false]];
        $html = $this->render($this->data(['explanation' => $this->explanation(['accounts' => $accounts])]));

        $this->assertStringContainsString('It is not behind on payments.', $html);
        $this->assertStringNotContainsString('Overdue now', $html, 'no group for accounts that are not overdue');
        $this->assertStringContainsString('Active and up to date', $html);
        $this->assertMatchesRegularExpression('/Behind on payments<\/span><span class="ci-tile-value">0</', $html);
        $this->assertStringContainsString('none overdue', $html);
        $this->assertMatchesRegularExpression('/ci-tile is-good"><span class="ci-tile-label">Behind on payments/', $html);
        $this->assertMatchesRegularExpression('/ci-tile-money is-good"><span class="ci-tile-label">Overdue/', $html, 'nothing overdue is green, not red');
    }

    public function testAnOldExplanationWithoutNamesOrTotalsStillRendersWithTheLabels(): void
    {
        $old = $this->explanation();
        unset($old['lenders'], $old['totals']);

        $html = $this->render($this->data(['explanation' => $old]));

        $this->assertStringContainsString('<strong>Lender A</strong>', $html);
        $this->assertStringContainsString('Lender A has N$43,757.85 overdue', $html);
        $this->assertStringContainsString('Loans and accounts right now', $html);
    }

    public function testOnlyTheClosedNoteIsShownWhenThereAreNoOpenAccounts(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['accounts' => []])]));

        $this->assertStringContainsString('Loans and accounts right now', $html);
        $this->assertStringContainsString('Four closed accounts, all settled.', $html);
        $this->assertStringNotContainsString('class="ci-tiles"', $html);
        $this->assertStringNotContainsString('<table class="table ci-accounts', $html);
    }

    public function testNoOpenAccountsAndNoClosedNoteMeansNoCard(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['accounts' => [], 'closed_note' => null])]));

        $this->assertStringNotContainsString('Loans and accounts right now', $html);
    }

    public function testTheReportsTotalsAloneAreEnoughForTheFigures(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['accounts' => [], 'closed_note' => null, 'totals' => ['owed' => 5000.0, 'overdue' => 0.0]])]));

        $this->assertStringContainsString('Loans and accounts right now', $html);
        $this->assertMatchesRegularExpression('/Still owed<\/span><span class="ci-tile-value">N\$5,000</', $html);
        $this->assertStringNotContainsString('Open accounts</span>', $html, 'no account count when no account is listed');
    }

    public function testLenderNamesFromTheReportAreEscapedLikeAnythingElse(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['lenders' => ['Lender A' => '<script>alert("n")</script> Finance', 'Lender B' => 'B & Sons "Ltd"']])]));

        $this->assertStringNotContainsString('<script>alert("n")', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;n&quot;)&lt;/script&gt; Finance', $html);
        $this->assertStringContainsString('B &amp; Sons &quot;Ltd&quot;', $html);
    }

    public function testADelayUnderAMonthHasNoSecondLineAndOneDayIsSingular(): void
    {
        $accounts = [
            ['kind' => 'Loan one', 'lender' => 'Lender A', 'owes' => 10.0, 'overdue' => 5.0, 'days_late' => 12, 'status' => 'behind', 'unverified' => false],
            ['kind' => 'Loan two', 'lender' => 'Lender B', 'owes' => 10.0, 'overdue' => 5.0, 'days_late' => 1, 'status' => 'behind', 'unverified' => false],
        ];
        $html = $this->render($this->data(['explanation' => $this->explanation(['accounts' => $accounts])]));

        $this->assertStringContainsString('12 days', $html);
        $this->assertStringContainsString('1 day', $html);
        $this->assertStringNotContainsString('about 12 days', $html);
        $this->assertDoesNotMatchRegularExpression('/\b1 days\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/12 days<br>/', $html, 'the everyday wording is only added from a month on');
    }

    public function testTheNameOfTheAiModelIsNeverPrintedEvenWhenAnOldExplanationStoredIt(): void
    {
        $old = $this->explanation(['meta' => ['model' => 'secret-model-name', 'generated_at' => '2026-11-13 10:30:00', 'unverified_figures' => 0]]);

        $html = $this->render($this->data(['explanation' => $old]));

        $this->assertStringNotContainsString('secret-model-name', $html);
        $this->assertStringContainsString('Explained by the AI analyser on 13 Nov 2026 10:30', $html);
    }

    public function testTheAiNoteSaysTheNamesWerePutBackAndNotSent(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertStringContainsString('no names, ID numbers, addresses, phone numbers or lender names were sent', $html);
        $this->assertStringContainsString("lenders' names were put back on this page from the report", $html);
    }

    public function testFiguresThatCouldNotBeMatchedAreFlaggedForTheReader(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertStringContainsString('Worth double-checking', $html);
        $this->assertStringContainsString('2 figures', $html);
        $this->assertSame(2, substr_count($html, '>Check figures<'), 'one flagged finding and one flagged account');
        $this->assertStringContainsString('A field called &quot;xyz&quot; was not clear.', $html);

        $clean = $this->explanation(['uncertainties' => [], 'meta' => ['unverified_figures' => 0, 'generated_at' => '2026-11-13 10:30:00'],
            'findings' => [['status' => 'bad', 'title' => 'T', 'detail' => 'D', 'uncertain' => false, 'unverified' => false]],
            'accounts' => [['kind' => 'Loan', 'lender' => 'Lender A', 'owes' => 1.0, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'on_time', 'unverified' => false]]]);
        $html = $this->render($this->data(['explanation' => $clean]));
        $this->assertStringNotContainsString('Worth double-checking', $html);
        $this->assertStringNotContainsString('Check figures', $html);
    }

    public function testUnmatchedFiguresAloneAreEnoughToShowTheWarning(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['uncertainties' => [], 'meta' => ['unverified_figures' => 3, 'generated_at' => '2026-11-13 10:30:00']])]));

        $this->assertStringContainsString('Worth double-checking', $html);
        $this->assertStringContainsString('<strong>3 figures</strong>', $html);
    }

    public function testASingularFigureIsWrittenInTheSingular(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['meta' => ['unverified_figures' => 1, 'generated_at' => '2026-11-13 10:30:00']])]));

        $this->assertStringContainsString('<strong>1 figure</strong>', $html);
    }

    public function testNothingTheAiWroteReachesThePageAsMarkup(): void
    {
        $evil = '<script>alert("x")</script><img src=x onerror=alert(1)>';
        $html = $this->render($this->data(['explanation' => $this->explanation([
            'summary' => $evil, 'trend_note' => $evil, 'closed_note' => $evil, 'grade' => 'E2',
            'findings' => [['status' => 'bad', 'title' => $evil, 'detail' => $evil, 'uncertain' => false, 'unverified' => false]],
            'reasons' => [['code' => $evil, 'meaning' => $evil]],
            'accounts' => [['kind' => $evil, 'lender' => $evil, 'owes' => 1.0, 'overdue' => 1.0, 'days_late' => 40, 'status' => 'behind', 'unverified' => false]],
            'check_next' => [$evil], 'uncertainties' => [$evil],
            'lenders' => ['Lender A' => $evil],
            'meta' => ['generated_at' => '2026-11-13 10:30:00', 'unverified_figures' => 0],
        ])]));

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    }

    public function testAMinimalOrDamagedStoredExplanationStillRenders(): void
    {
        foreach ([
            ['risk_level' => 'high'],
            ['risk_level' => 'high', 'findings' => [5, null, ['title' => 'Only a title']], 'accounts' => ['x', ['kind' => 'Loan']], 'months' => [3, ['month' => '2026-02']], 'reasons' => ['x', ['code' => 'ZZZ9']], 'check_next' => [5, ['a']], 'uncertainties' => [7], 'meta' => []],
            [],
        ] as $stored) {
            $html = $this->render($this->data(['explanation' => $stored]));
            $this->assertNotEmpty($html);
        }
        $html = $this->render($this->data(['explanation' => ['risk_level' => 'high']]));
        $this->assertStringContainsString('High risk', $html);
    }

    /** @dataProvider levels */
    public function testEveryRiskLevelShowsItsOwnLabelAndColour(string $level, string $label, bool $gauge): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['risk_level' => $level, 'score' => null, 'grade' => null, 'probability_of_default' => null])]));

        $this->assertStringContainsString('ci-verdict ci-lv-' . $level, $html);
        $this->assertStringContainsString($label, $html);
        $this->assertSame($gauge, str_contains($html, 'class="ci-gauge"'));
        if ($gauge) {
            $this->assertStringNotContainsString('class="ci-marker"', $html, 'no score, no marker');
            $this->assertSame(1, substr_count($html, ' is-active'));
        }
    }

    public static function levels(): array
    {
        return [
            ['very_low', 'Very low risk', true], ['low', 'Low risk', true], ['average', 'Average risk', true], ['high', 'High risk', true],
            ['very_high', 'Very high risk', true], ['not_scored', 'Not scored', false], ['no_record', 'No credit record', false],
        ];
    }

    public function testTheMarkerIsPositionedWithinTheGauge(): void
    {
        foreach ([250.0, 574.0, 709.0, 900.0] as $score) {
            $html = $this->render($this->data(['explanation' => $this->explanation(['score' => $score])]));
            $this->assertStringContainsString('--ci-pos: ' . Words::gaugePosition($score) . '%', $html, "score $score");
        }
    }

    public function testTheScoreFactsAppearOnlyWhenTheyAreKnown(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['score' => null, 'grade' => null, 'probability_of_default' => null])]));

        $this->assertStringNotContainsString('class="ci-facts"', $html);
        $this->assertStringNotContainsString('Credit score</span>', $html);

        $html = $this->render($this->data(['explanation' => $this->explanation(['score' => null, 'grade' => 'D1', 'probability_of_default' => 0.4])]));
        $this->assertStringContainsString('>D1<', $html);
        $this->assertStringContainsString('fewer than 1 in 100', $html);
        $this->assertStringNotContainsString('Credit score</span>', $html);
    }

    public function testAnExplanationWithoutKeyFiguresLeavesOutTheEmptyBoxes(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(['months' => [], 'accounts' => [], 'closed_note' => null, 'check_next' => [], 'reasons' => [], 'findings' => [], 'uncertainties' => [], 'trend_note' => null, 'meta' => ['unverified_figures' => 0, 'generated_at' => '2026-11-13 10:30:00']])]));

        foreach (['Payments over the last', 'Loans and accounts right now', 'You could ask the applicant', 'Why the score is what it is', 'In plain words', 'Worth double-checking'] as $heading) {
            $this->assertStringNotContainsString($heading, $html, $heading);
        }
    }

    // ---- the other states --------------------------------------------------------------------------------------------

    public function testANilReportSaysNoRecordWithoutAskingTheAi(): void
    {
        $html = $this->render($this->data(['reportData' => null, 'cache' => $this->cache(['search_outcome' => 'SubjectNotFound', 'report_status' => 'Unknown'])]));

        $this->assertStringContainsString('No credit record found', $html);
        $this->assertStringContainsString('does not mean the person is a bad risk', $html);
        $this->assertStringContainsString('payslips', $html);
        $this->assertStringNotContainsString('/creditinfo/explain"', $html);
        $this->assertStringNotContainsString('Explain this report', $html);
        $this->assertStringContainsString('How to read a Creditinfo report', $html);
    }

    public function testAPurgedReportCannotBeExplained(): void
    {
        $html = $this->render($this->data(['reportData' => null, 'purged' => true, 'cache' => $this->cache(['purged_at' => '2026-11-01 00:00:00'])]));

        $this->assertStringContainsString('purged under the data retention policy', $html);
        $this->assertStringNotContainsString('/creditinfo/explain"', $html);
        $this->assertStringNotContainsString('Explain this report', $html);
        $this->assertStringContainsString('Technical details', $html);
    }

    public function testAnExplanationIsNotShownOnceTheReportItExplainsHasBeenPurged(): void
    {
        $html = $this->render($this->data(['reportData' => null, 'purged' => true, 'explanation' => $this->explanation()]));

        $this->assertStringNotContainsString('class="card ci-verdict', $html);
        $this->assertStringNotContainsString('Far behind on one loan', $html);
    }

    public function testAReportStillProcessingSaysSo(): void
    {
        $html = $this->render($this->data(['reportData' => null, 'cache' => $this->cache(['report_status' => 'InProgress'])]));

        $this->assertStringContainsString('still preparing this report', $html);
        $this->assertStringNotContainsString('Explain this report', $html);
    }

    /** @dataProvider failedStatuses */
    public function testAFailedReportSaysWhatToDo(string $status): void
    {
        $html = $this->render($this->data(['reportData' => null, 'cache' => $this->cache(['report_status' => $status])]));

        $this->assertStringContainsString('could not return this report (status: ' . $status . ')', $html);
        $this->assertStringContainsString('run the credit bureau check again', $html);
    }

    public static function failedStatuses(): array
    {
        return [['Error'], ['Rejected'], ['Timeout']];
    }

    public function testNoReportContentAtAllHasAPlainFallback(): void
    {
        $html = $this->render($this->data(['reportData' => null, 'cache' => $this->cache(['report_status' => 'Unknown'])]));

        $this->assertStringContainsString('No report content is available for this enquiry.', $html);
    }

    // ---- what the old page showed is still there ---------------------------------------------------------------------

    public function testTheTechnicalDetailsKeepEverythingTheOldPageShowed(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation(), 'reportData' => ['report' => ['dashboard' => ['score' => 531, 'marker' => 'RAW-SECTION-VALUE'], 'contracts' => [], 'judgements' => []]]]));

        foreach (['Workflow Reference', 'WF-REF-1', 'Request Reference', 'REQ-REF-1', 'National ID Used', 'Enquiry Date', '12 Nov 2026 14:22', 'Report sections', 'CreditinfoReportPlus', 'RAW-SECTION-VALUE', 'Awaiting Compliance Configuration'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('8005••••••', $html);
        $this->assertStringNotContainsString('80050412345', $html, 'only the first four digits of the ID are ever shown');
        $this->assertStringNotContainsString('<h6 class="mb-1">Contracts</h6>', $html, 'empty sections are not listed');
    }

    public function testTheRetentionNoteNamesTheDaysWhenAPolicyIsActive(): void
    {
        $html = $this->render($this->data(['retentionActive' => true, 'retentionDays' => 90]));

        $this->assertStringContainsString('Creditinfo Report Retention: 90 days after decision.', $html);
        $this->assertStringContainsString('removed with it', $html, 'the explanation goes when the report goes');
        $this->assertStringNotContainsString('Awaiting Compliance Configuration', $html);
    }

    public function testThePdfButtonsAreStillThere(): void
    {
        $withPdf = $this->render($this->data(['hasPdf' => true]));
        $this->assertStringContainsString("Download Creditinfo's PDF", $withPdf);
        $this->assertStringContainsString('/creditinfo/pdf"', $withPdf);
        $this->assertStringContainsString('/creditinfo/pdf/generate"', $this->render($this->data(['hasPdf' => false, 'hasReportToken' => true])));
        $this->assertStringNotContainsString('/creditinfo/pdf/generate"', $this->render($this->data(['hasPdf' => false, 'hasReportToken' => false])));
    }

    public function testTheUatBannerIsStillShownForATestId(): void
    {
        $this->assertStringContainsString('UAT TEST DATA', $this->render($this->data(['cache' => $this->cache(['is_uat_test_id' => 1])])));
        $this->assertStringNotContainsString('UAT TEST DATA', $this->render($this->data()));
    }

    public function testTheGradeScaleAndGlossaryAreExplainedOnThePage(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        $this->assertStringContainsString('The grade scale', $html);
        foreach (Words::gradeScale() as $band) {
            $this->assertStringContainsString($band['from'] . ' to ' . $band['to'], $html);
            $this->assertStringContainsString($band['meaning'], $html);
        }
        $this->assertStringContainsString('(this applicant)', $html);
        $this->assertSame(1, substr_count($html, '(this applicant)'));
        foreach (Words::glossary() as $entry) {
            $this->assertStringContainsString(htmlspecialchars($entry['term'], ENT_QUOTES), $html);
        }
    }

    public function testEveryTableOptsOutOfTheAutomaticDataTablesToolbar(): void
    {
        $html = $this->render($this->data(['explanation' => $this->explanation()]));

        preg_match_all('/<table\b[^>]*>/', $html, $tables);
        $this->assertNotEmpty($tables[0]);
        foreach ($tables[0] as $tag) {
            $this->assertStringContainsString('no-datatable', $tag, 'the template adds Copy/CSV/Excel buttons to every other table: ' . $tag);
        }
    }
}
