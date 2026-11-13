<?php

namespace App\Support;

/**
 * The fixed, human-written half of the plain-language credit report page: what the grades mean, a glossary, and everyday wording for
 * Creditinfo's reason codes. None of this comes from the AI, so it reads the same on every report and can be checked once by a person.
 * The grade boundaries are Creditinfo's own (their "Score Grade Guide" on every report).
 */
final class CreditinfoPlainWords
{
    /** Creditinfo's score range and where each grade starts. */
    public const SCORE_MIN = 250;
    public const SCORE_MAX = 900;

    /** @var array<string, string> risk level => short label */
    private const RISK_LABELS = [
        'very_low' => 'Very low risk',
        'low' => 'Low risk',
        'average' => 'Average risk',
        'high' => 'High risk',
        'very_high' => 'Very high risk',
        'not_scored' => 'Not scored',
        'no_record' => 'No credit record',
    ];

    /**
     * Creditinfo's grade bands, best first.
     *
     * @return array<int, array{level:string, grades:string, label:string, from:int, to:int, meaning:string}>
     */
    public static function gradeScale(): array
    {
        return [
            ['level' => 'very_low', 'grades' => 'A1 to A3', 'label' => 'Very low risk', 'from' => 709, 'to' => 900, 'meaning' => 'A long record of paying on time and handling credit well.'],
            ['level' => 'low', 'grades' => 'B1 to B3', 'label' => 'Low risk', 'from' => 677, 'to' => 709, 'meaning' => 'Pays on time in general.'],
            ['level' => 'average', 'grades' => 'C1 to C3', 'label' => 'Average risk', 'from' => 641, 'to' => 677, 'meaning' => 'Some irregular payments, or a lot of borrowing already.'],
            ['level' => 'high', 'grades' => 'D1 to D3', 'label' => 'High risk', 'from' => 574, 'to' => 641, 'meaning' => 'A real chance of falling behind on a loan.'],
            ['level' => 'very_high', 'grades' => 'E1 to E3', 'label' => 'Very high risk', 'from' => 250, 'to' => 574, 'meaning' => 'Has already defaulted, or is badly behind on payments.'],
        ];
    }

    public static function riskLabel(string $level): string
    {
        return self::RISK_LABELS[$level] ?? 'Not clear';
    }

    /** @return string[] every risk level the explanation may use */
    public static function riskLevels(): array
    {
        return array_keys(self::RISK_LABELS);
    }

    /**
     * Where a score sits on the five-band gauge, as a percentage from the left edge: the worst grade (E) is the left-hand fifth and the best (A)
     * the right-hand fifth, every band drawn the same width so the narrow ones (B and C are only about 35 points wide) stay readable.
     * Inside its band the marker sits in proportion to the score.
     */
    public static function gaugePosition(?float $score): ?float
    {
        if ($score === null) {
            return null;
        }
        $clamped = max((float) self::SCORE_MIN, min((float) self::SCORE_MAX, $score));
        $bands = self::gradeScale(); // best first
        $count = count($bands);
        foreach ($bands as $index => $band) {
            if ($clamped >= $band['from'] || $index === $count - 1) {
                $fraction = max(0.0, min(1.0, ($clamped - $band['from']) / ($band['to'] - $band['from'])));
                return round((($count - 1 - $index) + $fraction) / $count * 100, 1);
            }
        }
        return null;
    }

    /**
     * The risk level that Creditinfo's own bands give a score. Used to double-check the AI's wording against the number it quotes.
     */
    public static function levelForScore(float $score): string
    {
        foreach (self::gradeScale() as $band) {
            if ($score >= $band['from']) {
                return $band['level'];
            }
        }
        return 'very_high';
    }

    /**
     * Everyday wording for the reason codes Creditinfo has given on reports so far. Unknown codes fall back to the AI's own wording.
     */
    public static function reasonMeaning(string $code): ?string
    {
        $known = [
            'HMP1' => 'The most recent record shows poor payment behaviour.',
            'LWM1' => 'Four or more payments were overdue in the last 12 months.',
            'AMO1' => 'A very large amount was overdue in the last 3 months.',
            'MSM1' => 'Three or more payments were overdue in the last 3 months.',
            'NPI2' => 'There were several payment problems in the last 12 months.',
        ];
        return $known[strtoupper(trim($code))] ?? null;
    }

    /**
     * The glossary shown under "What do these words mean?".
     *
     * @return array<int, array{term:string, meaning:string}>
     */
    public static function glossary(): array
    {
        return [
            ['term' => 'Credit score', 'meaning' => 'A number from 250 to 900 that Creditinfo works out from a person\'s payment history. Higher is better.'],
            ['term' => 'Grade (A to E)', 'meaning' => 'The score as one letter. A is the lowest risk and E is the highest. The number after the letter (1 to 3) splits each grade into smaller steps.'],
            ['term' => 'Probability of default', 'meaning' => 'Creditinfo\'s estimate of how likely it is that a person with a record like this will fail to repay a loan. 76% means about 76 in every 100 similar people.'],
            ['term' => 'Trend', 'meaning' => 'Whether the score went up or down since the month before. An arrow pointing up only means the score improved. It does not mean the score is good.'],
            ['term' => 'Overdue (arrears, past due)', 'meaning' => 'Money that should already have been paid but has not been.'],
            ['term' => 'Days past due', 'meaning' => 'How long the oldest unpaid amount has been overdue. 30 days is about 1 month and 365 days is 1 year.'],
            ['term' => 'Open account and closed account', 'meaning' => 'An open account is a loan or credit line that is still running. A closed account is finished or settled.'],
            ['term' => 'Public default', 'meaning' => 'A debt that stayed unpaid and was published on Creditinfo\'s list of defaults.'],
            ['term' => 'Court judgment', 'meaning' => 'A court order saying the person must pay a debt.'],
            ['term' => 'Dispute', 'meaning' => 'The person told Creditinfo that an entry on their report is wrong, and it is being looked into.'],
            ['term' => 'Credit enquiry', 'meaning' => 'Each time a lender looked at the person\'s credit record, usually because they applied for credit. Many enquiries in a short time can mean the person is struggling to get credit.'],
            ['term' => 'Reason codes', 'meaning' => 'Short codes from Creditinfo that give the main reasons behind the score. This page writes them out as sentences.'],
            ['term' => 'No record found', 'meaning' => 'Creditinfo has no credit history for this person. That is neither good nor bad. Ask for other proof, such as payslips or bank statements.'],
            ['term' => 'Not scored', 'meaning' => 'There is not enough information to give a score.'],
        ];
    }
}
