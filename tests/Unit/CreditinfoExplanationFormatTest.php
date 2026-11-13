<?php

namespace Tests\Unit;

use App\Support\CreditinfoExplanationFormat as Format;
use App\Support\CreditinfoPlainWords as Words;
use PHPUnit\Framework\TestCase;

/**
 * The fixed wording and formatting of the plain-language credit report page. None of it comes from the AI.
 */
class CreditinfoExplanationFormatTest extends TestCase
{
    /** @dataProvider moneyCases */
    public function testMoney(?float $amount, string $expected): void
    {
        $this->assertSame($expected, Format::money($amount));
    }

    public static function moneyCases(): array
    {
        return [
            'whole amount has no decimals' => [109204.0, 'N$109,204'],
            'cents are kept' => [43757.85, 'N$43,757.85'],
            'a tiny rounding error is still whole' => [5199.999999, 'N$5,200'],
            'zero' => [0.0, 'N$0'],
            'a million' => [1136984.0, 'N$1,136,984'],
            'one cent' => [0.01, 'N$0.01'],
            'missing' => [null, '—'],
        ];
    }

    /** @dataProvider daysCases */
    public function testHumanDays(int $days, string $expected): void
    {
        $this->assertSame($expected, Format::humanDays($days));
    }

    public static function daysCases(): array
    {
        return [
            'none' => [0, '0 days'],
            'one day' => [1, '1 day'],
            'a few days' => [12, '12 days'],
            'just under a month' => [29, '29 days'],
            'a month' => [30, 'about 1 month'],
            'six weeks is still about a month' => [45, 'about 1 month'],
            'two months' => [61, 'about 2 months'],
            'nine months' => [275, 'about 9 months'],
            'almost a year rounds up to a year' => [364, 'about 1 year'],
            'a year' => [365, 'about 1 year'],
            'a year and a bit' => [400, 'about 1 year and 1 month'],
            'four years exactly' => [1461, 'about 4 years'],
            'four years and three months' => [1553, 'about 4 years and 3 months'],
            'eleven and a half months of the next year rounds into the next year' => [1824, 'about 5 years'],
            'negative is treated as none' => [-5, '0 days'],
        ];
    }

    /** @dataProvider latenessCases */
    public function testLateness(int $days, string $expected): void
    {
        $this->assertSame($expected, Format::lateness($days));
    }

    public static function latenessCases(): array
    {
        return [
            'none' => [0, '0 days'], 'one day' => [1, '1 day'], 'a few days' => [12, '12 days'], 'just under a month' => [29, '29 days'],
            'a month' => [30, '30 days (about 1 month)'], 'four years' => [1461, '1,461 days (about 4 years)'], 'negative' => [-3, '0 days'],
        ];
    }

    /** @dataProvider lenderTexts */
    public function testTheLendersRealNamesAreBroughtBackIntoATextWrittenWithAliases(string $text, string $expected): void
    {
        $names = ['Lender A' => 'Capital Credit Namibia', 'Lender B' => 'Savanna Retail $1 \\0 & Sons', 'Lender 27' => 'Twenty-Seventh Lender'];

        $this->assertSame($expected, Format::withLenderNames($text, $names));
    }

    public static function lenderTexts(): array
    {
        return [
            'a plain label' => ['Lender A has N$5 overdue.', 'Capital Credit Namibia has N$5 overdue.'],
            'a possessive' => ["Lender A's loan is late.", "Capital Credit Namibia's loan is late."],
            'two labels in one text' => ['Lender A and Lender B', 'Capital Credit Namibia and Savanna Retail $1 \\0 & Sons'],
            'a name with characters that mean something in a replacement' => ['Lender B', 'Savanna Retail $1 \\0 & Sons'],
            'a numbered label' => ['Lender 27 is new.', 'Twenty-Seventh Lender is new.'],
            'a label with no known name stays as it is' => ['Lender Z owes N$1.', 'Lender Z owes N$1.'],
            'a longer label is not a match for a shorter one' => ['Lender AB', 'Lender AB'],
            'letter case matters' => ['lender a and LENDER A', 'lender a and LENDER A'],
            'not a label at the start of a word' => ['MyLender A', 'MyLender A'],
            'a label at the very end' => ['The loan from Lender A', 'The loan from Capital Credit Namibia'],
            'nothing to replace' => ['No lenders here.', 'No lenders here.'],
            'an empty text' => ['', ''],
        ];
    }

    public function testNamesAreNotSubstitutedTwiceAndBadEntriesAreIgnored(): void
    {
        $this->assertSame('Lender B', Format::withLenderNames('Lender A', ['Lender A' => 'Lender B', 'Lender B' => 'Somebody']), 'one pass only: a name that looks like a label stays');
        $this->assertSame('Lender A', Format::withLenderNames('Lender A', ['Lender A' => '']), 'an empty name leaves the label');
        $this->assertSame('Lender A', Format::withLenderNames('Lender A', ['Lender A' => 12345]), 'a name that is not text leaves the label');
        $this->assertSame('Lender A', Format::withLenderNames('Lender A', []));
    }

    /** @return array<int, array<string, mixed>> */
    private static function accounts(): array
    {
        return [
            ['kind' => 'Retail account', 'lender' => 'Lender B', 'owes' => 11195.0, 'overdue' => 1150.0, 'days_late' => 31, 'status' => 'behind'],
            ['kind' => 'Personal loan', 'lender' => 'Lender A', 'owes' => 109204.35, 'overdue' => 43757.85, 'days_late' => 1461, 'status' => 'behind'],
            ['kind' => 'Cash loan', 'lender' => 'Lender C', 'owes' => 4801.0, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'on_time'],
        ];
    }

    private const NAMES = ['Lender A' => 'Capital Credit Namibia', 'Lender B' => 'Savanna Retail Finance', 'Lender C' => 'Kalahari Micro Loans'];

    public function testTheLoanPositionAddsUpTheListedAccounts(): void
    {
        $p = Format::loanPosition(self::accounts());

        $this->assertSame(3, $p['count']);
        $this->assertSame(2, $p['behind']);
        $this->assertSame(1, $p['on_time']);
        $this->assertSame(0, $p['unclear']);
        $this->assertSame(125200.35, $p['owed']);
        $this->assertSame(44907.85, $p['overdue']);
        $this->assertSame('listed', $p['owed_source']);
        $this->assertSame('listed', $p['overdue_source']);
        $this->assertFalse($p['differs']);
        $this->assertSame('Personal loan', $p['worst']['kind'], 'the biggest overdue amount');
    }

    public function testATotalStatedByTheReportWinsOverTheSumOfTheListedAccounts(): void
    {
        $p = Format::loanPosition(self::accounts(), ['owed' => 200000.0, 'overdue' => 44907.85]);

        $this->assertSame(200000.0, $p['owed']);
        $this->assertSame('report', $p['owed_source']);
        $this->assertSame(44907.85, $p['overdue']);
        $this->assertSame('report', $p['overdue_source']);
        $this->assertTrue($p['differs'], 'the report says 200,000 is owed but the accounts shown add up to 125,200.35: one is probably missing');
    }

    public function testTheReportsOverdueTotalWinsOverTheListedSum(): void
    {
        $p = Format::loanPosition(self::accounts(), ['overdue' => 50000.0]);

        $this->assertSame(50000.0, $p['overdue']);
        $this->assertSame('report', $p['overdue_source']);
    }

    public function testATotalWithinARandOfTheListedAccountsIsNotADifference(): void
    {
        $this->assertFalse(Format::loanPosition(self::accounts(), ['owed' => 125200.0, 'overdue' => 44908.0])['differs']);
        $this->assertTrue(Format::loanPosition(self::accounts(), ['owed' => 125202.0])['differs']);
        $this->assertTrue(Format::loanPosition(self::accounts(), ['overdue' => 50000.0])['differs']);
    }

    public function testTheLoanPositionCopesWithMissingFiguresAndOddEntries(): void
    {
        $this->assertSame(0, Format::loanPosition([])['count']);
        $this->assertNull(Format::loanPosition([])['owed']);
        $this->assertNull(Format::loanPosition([])['worst']);

        $p = Format::loanPosition([
            ['kind' => 'Loan', 'owes' => null, 'overdue' => null, 'days_late' => null, 'status' => 'unknown'],
            'not an account',
            ['kind' => 'Card', 'owes' => 100.1, 'overdue' => null, 'days_late' => 0, 'status' => 'unknown'],
            ['kind' => 'Card', 'owes' => 200.2, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'weird'],
        ]);
        $this->assertSame(3, $p['count'], 'a row that is not an account is not counted');
        $this->assertSame(3, $p['unclear']);
        $this->assertSame(300.3, $p['owed'], 'floats are added and rounded to cents');
        $this->assertSame(0.0, $p['overdue'], 'only one account said how much is overdue (nothing)');
        $this->assertNull($p['worst'], 'nothing is overdue anywhere');

        $this->assertNull(Format::loanPosition([['owes' => null, 'overdue' => null, 'status' => 'unknown']])['owed'], 'no figure at all stays unknown, not zero');
    }

    public function testTheWorstAccountIsTheLargestOverdueAmountThenTheMostDaysLate(): void
    {
        $accounts = [
            ['kind' => 'A', 'overdue' => 100.0, 'days_late' => 10, 'status' => 'behind'],
            ['kind' => 'B', 'overdue' => 100.0, 'days_late' => 90, 'status' => 'behind'],
            ['kind' => 'C', 'overdue' => 50.0, 'days_late' => 900, 'status' => 'behind'],
        ];
        $this->assertSame('B', Format::loanPosition($accounts)['worst']['kind']);

        $onlyDays = [['kind' => 'X', 'overdue' => null, 'days_late' => 5, 'status' => 'behind'], ['kind' => 'Y', 'overdue' => null, 'days_late' => 40, 'status' => 'behind']];
        $this->assertSame('Y', Format::loanPosition($onlyDays)['worst']['kind'], 'no amounts: the longest delay');
    }

    public function testTheAccountsAreGroupedAndSortedForReading(): void
    {
        $groups = Format::groupAccounts([
            ['kind' => 'small behind', 'overdue' => 10.0, 'days_late' => 5, 'owes' => 9000.0, 'status' => 'behind'],
            ['kind' => 'up to date, owes little', 'owes' => 100.0, 'status' => 'on_time'],
            ['kind' => 'big behind', 'overdue' => 5000.0, 'days_late' => 5, 'owes' => 10.0, 'status' => 'behind'],
            ['kind' => 'unclear', 'owes' => 7.0, 'status' => 'unknown'],
            ['kind' => 'up to date, owes a lot', 'owes' => 90000.0, 'status' => 'on_time'],
            'not an account',
            ['kind' => 'late for longer', 'overdue' => 10.0, 'days_late' => 400, 'status' => 'behind'],
            ['kind' => 'no status', 'owes' => 8.0],
        ]);

        $this->assertSame(['big behind', 'late for longer', 'small behind'], array_column($groups['behind'], 'kind'), 'largest overdue first, then the longer delay');
        $this->assertSame(['up to date, owes a lot', 'up to date, owes little'], array_column($groups['on_time'], 'kind'));
        $this->assertSame(['no status', 'unclear'], array_column($groups['unclear'], 'kind'), 'status unknown or missing: the third group');
    }

    public function testTheLoanSentenceTellsTheStoryInPlainWordsWithTheLendersNames(): void
    {
        $sentence = Format::loanSentence(Format::loanPosition(self::accounts()), self::NAMES);

        $this->assertSame(
            'The applicant has 3 open loans or accounts. 2 of them are behind on payments, with N$44,907.85 overdue on the accounts shown. '
            . 'The biggest overdue amount is N$43,757.85 (Personal loan, Capital Credit Namibia), 1,461 days (about 4 years) late. '
            . 'The accounts shown add up to N$125,200.35 still owed.',
            $sentence
        );
    }

    public function testTheLoanSentenceUsesTheReportsOwnTotalsWhenItHasThem(): void
    {
        $sentence = Format::loanSentence(Format::loanPosition(self::accounts(), ['owed' => 125200.35, 'overdue' => 44907.85]), self::NAMES);

        $this->assertStringContainsString('with N$44,907.85 overdue in total.', $sentence);
        $this->assertStringContainsString('In total N$125,200.35 is still owed.', $sentence);
        $this->assertStringNotContainsString('on the accounts shown', $sentence);
    }

    /** @dataProvider loanSentences */
    public function testTheLoanSentenceForOtherSituations(array $accounts, array $totals, string $expected): void
    {
        $this->assertSame($expected, Format::loanSentence(Format::loanPosition($accounts, $totals), self::NAMES));
    }

    public static function loanSentences(): array
    {
        return [
            'no accounts, no sentence' => [[], [], ''],
            'one loan that is up to date' => [
                [['kind' => 'Cash loan', 'lender' => 'Lender C', 'owes' => 4801.0, 'overdue' => 0.0, 'days_late' => 0, 'status' => 'on_time']], [],
                'The applicant has 1 open loan or account. It is not behind on payments. The accounts shown add up to N$4,801 still owed.',
            ],
            'two loans, both up to date' => [
                [['kind' => 'A', 'owes' => 10.0, 'status' => 'on_time'], ['kind' => 'B', 'owes' => 20.5, 'status' => 'on_time']], [],
                'The applicant has 2 open loans or accounts. None of them is behind on payments. The accounts shown add up to N$30.50 still owed.',
            ],
            'one loan that is behind, a short delay' => [
                [['kind' => 'Cash loan', 'lender' => 'Lender C', 'owes' => 900.0, 'overdue' => 300.0, 'days_late' => 12, 'status' => 'behind']], [],
                'The applicant has 1 open loan or account. It is behind on payments, with N$300 overdue on the accounts shown. The biggest overdue amount is N$300 (Cash loan, Kalahari Micro Loans), 12 days late. The accounts shown add up to N$900 still owed.',
            ],
            'a delay of one day' => [
                [['kind' => 'Card', 'owes' => 50.0, 'overdue' => 5.0, 'days_late' => 1, 'status' => 'behind']], [],
                'The applicant has 1 open loan or account. It is behind on payments, with N$5 overdue on the accounts shown. The biggest overdue amount is N$5 (Card), 1 day late. The accounts shown add up to N$50 still owed.',
            ],
            'every account is behind' => [
                [['kind' => 'A', 'owes' => 10.0, 'overdue' => 1.0, 'days_late' => 2, 'status' => 'behind'], ['kind' => 'B', 'owes' => 10.0, 'overdue' => 2.0, 'days_late' => 3, 'status' => 'behind']], [],
                'The applicant has 2 open loans or accounts. All of them are behind on payments, with N$3 overdue on the accounts shown. The biggest overdue amount is N$2 (B), 3 days late. The accounts shown add up to N$20 still owed.',
            ],
            'behind but no amounts known' => [
                [['kind' => 'A', 'overdue' => null, 'owes' => null, 'days_late' => 40, 'status' => 'behind'], ['kind' => 'B', 'owes' => null, 'status' => 'on_time']], [],
                'The applicant has 2 open loans or accounts. 1 of them is behind on payments.',
            ],
            'status not clear' => [
                [['kind' => 'A', 'owes' => 100.0, 'status' => 'unknown']], [],
                'The applicant has 1 open loan or account. None is shown as behind on payments. The accounts shown add up to N$100 still owed.',
            ],
            'only the report totals are known' => [
                [['kind' => 'A', 'owes' => null, 'overdue' => null, 'days_late' => 0, 'status' => 'on_time']], ['owed' => 5000.0, 'overdue' => 0.0],
                'The applicant has 1 open loan or account. It is not behind on payments. In total N$5,000 is still owed.',
            ],
            'a lender that is not known keeps its label' => [
                [['kind' => 'Loan', 'lender' => 'Lender Q', 'owes' => 10.0, 'overdue' => 10.0, 'days_late' => 0, 'status' => 'behind']], [],
                'The applicant has 1 open loan or account. It is behind on payments, with N$10 overdue on the accounts shown. The biggest overdue amount is N$10 (Loan, Lender Q). The accounts shown add up to N$10 still owed.',
            ],
        ];
    }

    public function testMonthLabels(): void
    {
        $this->assertSame('Sep', Format::monthLabel('2026-09'));
        $this->assertSame('Sep 2026', Format::monthLabel('2026-09', true));
        $this->assertSame('Dec', Format::monthLabel('2025-12'));
        $this->assertSame('2026-13', Format::monthLabel('2026-13'), 'not a month: unchanged');
        $this->assertSame('March', Format::monthLabel('March'));
    }

    public function testEveryStatusHasAToneAnIconAndAWord(): void
    {
        foreach (['bad' => 'danger', 'watch' => 'warning', 'good' => 'success', 'info' => 'info', 'weird' => 'info'] as $status => $tone) {
            $this->assertSame($tone, Format::statusTone($status));
            $this->assertStringStartsWith('mdi-', Format::statusIcon($status));
            $this->assertNotSame('', Format::statusWord($status));
        }
        $this->assertSame(4, count(array_unique([Format::statusIcon('bad'), Format::statusIcon('watch'), Format::statusIcon('good'), Format::statusIcon('info')])), 'colour is never the only signal');
        $this->assertSame(4, count(array_unique([Format::statusWord('bad'), Format::statusWord('watch'), Format::statusWord('good'), Format::statusWord('info')])));
    }

    public function testEveryRiskLevelHasALabelAndAnIcon(): void
    {
        foreach (Words::riskLevels() as $level) {
            $this->assertNotSame('Not clear', Words::riskLabel($level), $level);
            $this->assertStringStartsWith('mdi-', Format::levelIcon($level));
        }
        $this->assertSame('Not clear', Words::riskLabel('banana'));
    }

    public function testMonthStates(): void
    {
        $this->assertSame('Paid on time', Format::monthState('ok')['word']);
        $this->assertSame('Late or missed', Format::monthState('late')['word']);
        $this->assertSame('No information', Format::monthState('no_data')['word']);
        $this->assertSame('No information', Format::monthState('?')['word']);
        $this->assertCount(3, array_unique([Format::monthState('ok')['icon'], Format::monthState('late')['icon'], Format::monthState('no_data')['icon']]));
    }

    // ---- the fixed words ---------------------------------------------------------------------------------------------

    public function testTheGradeScaleIsCreditinfosAndHasNoGapsOrOverlaps(): void
    {
        $scale = Words::gradeScale();

        $this->assertSame(['very_low', 'low', 'average', 'high', 'very_high'], array_column($scale, 'level'));
        $this->assertSame(Words::SCORE_MAX, $scale[0]['to']);
        $this->assertSame(Words::SCORE_MIN, $scale[4]['from']);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($scale[$i]['from'], $scale[$i + 1]['to'], 'each band starts where the next one ends');
        }
    }

    /** @dataProvider scoreLevels */
    public function testLevelForScore(float $score, string $expected): void
    {
        $this->assertSame($expected, Words::levelForScore($score));
    }

    public static function scoreLevels(): array
    {
        return [
            [900, 'very_low'], [709, 'very_low'], [708.9, 'low'], [677, 'low'], [676.9, 'average'], [641, 'average'],
            [640.9, 'high'], [574, 'high'], [573.9, 'very_high'], [531, 'very_high'], [250, 'very_high'], [100, 'very_high'],
        ];
    }

    /** @dataProvider gaugePositions */
    public function testTheGaugePutsEachGradeInItsOwnFifth(?float $score, ?float $expected): void
    {
        $this->assertEquals($expected, Words::gaugePosition($score));
    }

    public static function gaugePositions(): array
    {
        return [
            'no score' => [null, null],
            'the very bottom' => [250, 0.0],
            'middle of grade E' => [412, 10.0],
            'top of grade E is the start of D' => [574, 20.0],
            'middle of D' => [607.5, 30.0],
            'the start of C' => [641, 40.0],
            'the start of B' => [677, 60.0],
            'the start of A' => [709, 80.0],
            'the very top' => [900, 100.0],
            'below the scale is clamped' => [100, 0.0],
            'above the scale is clamped' => [1200, 100.0],
        ];
    }

    public function testTheGaugeNeverMovesBackwardsAsTheScoreRises(): void
    {
        $last = -1.0;
        for ($score = 250; $score <= 900; $score += 3) {
            $position = Words::gaugePosition((float) $score);
            $this->assertGreaterThanOrEqual($last, $position, "score $score");
            $last = $position;
        }
    }

    public function testKnownReasonCodesAreWrittenOutAndUnknownOnesAreNot(): void
    {
        foreach (['HMP1', 'LWM1', 'AMO1', 'MSM1', 'NPI2', ' hmp1 '] as $code) {
            $this->assertNotNull(Words::reasonMeaning($code), $code);
        }
        $this->assertNull(Words::reasonMeaning('ZZZ9'));
    }

    public function testTheGlossaryExplainsTheWordsOnTheReport(): void
    {
        $terms = array_column(Words::glossary(), 'term');
        foreach (['Credit score', 'Probability of default', 'Trend', 'Days past due', 'Public default', 'Credit enquiry', 'No record found'] as $needed) {
            $this->assertNotEmpty(preg_grep('/' . preg_quote($needed, '/') . '/i', $terms), $needed);
        }
        foreach (Words::glossary() as $entry) {
            $this->assertNotSame('', trim($entry['meaning']));
        }
    }
}
