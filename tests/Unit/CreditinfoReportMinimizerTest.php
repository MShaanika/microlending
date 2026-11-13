<?php

namespace Tests\Unit;

use App\Services\CreditinfoReportMinimizer;
use PHPUnit\Framework\TestCase;

/**
 * The anonymous copy of a credit report is the only thing that ever goes to the AI analyser, so most of these tests plant identifying
 * details in awkward places (in field names the rules know, in values under harmless names, in free text) and check none survives, while
 * every fact the explanation needs (score, arrears, balances, defaults, enquiries) still does.
 *
 * The report below follows the section names of Creditinfo's own manual (dashboard, contracts, inquiries, judgements, individual ...).
 * Field names inside the sections are illustrative: the minimizer works from the SHAPE of a name, not from one fixed layout.
 */
class CreditinfoReportMinimizerTest extends TestCase
{
    private const PERSONAL = [
        'Jane Doe', 'DOE', '88040100488', '8005041272341', '0811556866', '264000813404345', 'jane.doe@example.com', '1 Test Street',
        'Otjozondjupa', 'Admin User', 'ENGEN NAMIBIA', '3221188003', '7000435866', 'P1234567', '1988-04-01',
        'OLD MUTUAL FINANCE', 'EXPRESS CREDIT', 'BANK WINDHOEK', 'STANDARD BANK', 'tok-91yX6g', '77D2-D6F8',
    ];

    private CreditinfoReportMinimizer $minimizer;

    protected function setUp(): void
    {
        $this->minimizer = new CreditinfoReportMinimizer();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $payments = [];
        foreach (['2026 Nov', '2026 Oct', '2026 Sep', '2026 Aug', '2026 Jul', '2026 Jun', '2026 May', '2026 Apr', '2026 Mar', '2026 Feb', '2026 Jan', '2025 Dec', '2025 Nov', '2025 Oct', '2025 Sep', '2025 Aug', '2025 Jul', '2025 Jun', '2025 May', '2025 Apr', '2025 Mar', '2025 Feb', '2025 Jan', '2024 Dec'] as $i => $period) {
            $payments[] = ['period' => $period, 'pastDue' => $i < 10 ? 23346.0 : 0.0, 'daysPastDue' => $i < 10 ? 273 : 0, 'currentBalance' => 23347.0];
        }

        return [
            'reportInfo' => ['created' => '2026-11-13T06:26:00Z', 'requestedBy' => 'Admin User', 'reportToken' => 'tok-91yX6g', 'reportReferenceNumber' => '77D2-D6F8'],
            'parameters' => ['idNumber' => '88040100488', 'searchIdNumber' => '88040100488', 'subjectToken' => 'tok-91yX6g'],
            'individual' => [
                'names' => 'Jane Doe', 'surname' => 'DOE', 'nationalId' => '88040100488', 'passportNumber' => 'P1234567', 'dateOfBirth' => '1988-04-01',
                'residentialAddress' => ['addressLine1' => '1 Test Street', 'addressLine3' => 'Otjozondjupa'],
                'contacts' => ['cellularTelephone' => '264000813404345', 'homeTelephone' => '0811556866', 'emailAddress' => 'jane.doe@example.com'],
                'employerDetail' => 'ENGEN NAMIBIA',
            ],
            'subjectInfoHistory' => ['historyList' => [['item' => 'Names', 'changedValue' => 'Jane Doe']]],
            'cip' => ['verified' => true],
            'dashboard' => [
                'scoring' => [
                    'score' => 480, 'scoreGrade' => 'E2', 'trend' => 'Up', 'probabilityOfDefault' => 76.05,
                    'reasonCodes' => [
                        ['reasonCode' => 'HMP1', 'description' => 'Last available snapshot shows bad payment behaviour'],
                        ['reasonCode' => 'LWM1', 'description' => '4 or more payments overdue during last 12 months'],
                    ],
                ],
                'inquiries' => ['inquiriesLast12Months' => 4, 'inquiringSubscribersLast12Months' => 2],
                'paymentProfile' => [
                    'worstCurrentArrears' => 365, 'worstArrearsLast12Months' => 275, 'pastDueAmountSum' => 109204.0, 'totalOutstandingAmount' => 1136984.0,
                    'installmentAmountSum' => 21920.0, 'numberOfOpenContracts' => 6, 'numberOfClosedContracts' => 3, 'numberOfDifferentCreditors' => 4,
                ],
            ],
            'contracts' => ['contractList' => [
                ['artificialEntityCode' => 'C1', 'subscriber' => 'OLD MUTUAL FINANCE (NAMIBIA) (PTY) LTD', 'contractCode' => '3221188003', 'typeOfContract' => 'Installment',
                    'phaseOfContract' => 'Open', 'totalAmount' => 25771.0, 'currentBalance' => 17496.0, 'pastDueAmount' => 1416.0, 'pastDueDays' => 30, 'startDate' => '2019-01-28', 'payments' => $payments],
                ['artificialEntityCode' => 'C2', 'subscriber' => 'Old Mutual Finance (Namibia) (Pty) Ltd', 'contractCode' => '7000435866', 'typeOfContract' => 'Installment',
                    'phaseOfContract' => 'Open', 'totalAmount' => 45828.0, 'currentBalance' => 23347.0, 'pastDueAmount' => 23346.0, 'pastDueDays' => 273, 'startDate' => '2019-10-03'],
                ['artificialEntityCode' => 'C4', 'subscriber' => 'BANK WINDHOEK - HOME LOANS', 'typeOfContract' => 'Installment', 'phaseOfContract' => 'Open',
                    'totalAmount' => 970590.0, 'currentBalance' => 1047915.0, 'pastDueAmount' => 32217.0, 'pastDueDays' => 61],
                ['artificialEntityCode' => 'C5', 'subscriber' => 'EXPRESS CREDIT CASH ADVANCE (PTY) LTD', 'typeOfContract' => 'Installment', 'phaseOfContract' => 'Closed',
                    'totalAmount' => 7065.0, 'currentBalance' => 0.0, 'pastDueAmount' => 0.0, 'pastDueDays' => 0],
            ]],
            'inquiries' => ['inquiryList' => [
                ['date' => '2026-03-02', 'subscriberName' => 'EXPRESS CREDIT CASH ADVANCE (PTY) LTD', 'inquiryReason' => 'CreditApplication'],
                ['date' => '2026-01-10', 'subscriberName' => 'STANDARD BANK NAMIBIA', 'inquiryReason' => 'CreditApplication'],
            ]],
            'judgements' => [],
            'disputes' => ['activeDisputes' => 0, 'falseDisputes' => 0],
            'publicDefaults' => ['numberOfPublicDefaults' => 2, 'totalAmount' => 43757.85, 'defaultList' => [['creditorName' => 'EXPRESS CREDIT', 'amount' => 20000.0, 'date' => '2024-05-01']]],
        ];
    }

    private function json(array $minimized): string
    {
        return (string) json_encode($minimized, JSON_UNESCAPED_UNICODE);
    }

    // ---- what is removed ---------------------------------------------------------------------------------------------

    public function testSectionsThatDescribeThePersonOrTheRequestAreDropped(): void
    {
        $minimized = $this->minimizer->minimize($this->report());

        foreach (['individual', 'subjectInfoHistory', 'cip', 'reportInfo', 'parameters'] as $section) {
            $this->assertArrayNotHasKey($section, $minimized, $section);
        }
        $this->assertSame(['dashboard', 'contracts', 'inquiries', 'disputes', 'publicDefaults'], array_keys($minimized), 'only sections about the credit record remain (the empty judgements section has nothing to say)');
    }

    public function testNothingThatIdentifiesThePersonOrTheirAccountsSurvives(): void
    {
        $json = $this->json($this->minimizer->minimize($this->report()));

        foreach (self::PERSONAL as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $json, 'must not leave the system: ' . $secret);
        }
    }

    public function testIdentifyingFieldsAreDroppedEvenInsideTheSectionsThatAreKept(): void
    {
        $report = ['dashboard' => ['scoring' => ['score' => 500],
            'holderName' => 'Jane Doe', 'fullName' => 'Jane Doe', 'dateOfBirth' => '1988-04-01', 'idNumber' => '88040100488', 'nationalID' => '88040100488',
            'accountNumber' => '123', 'cellularTelephone' => '0811556866', 'emailAddress' => 'a@b.com', 'employerDetail' => 'X', 'passportNumber' => 'P1',
            'postalCode' => '9000', 'contractCode' => '3221188003', 'requestedBy' => 'Admin', 'subjectToken' => 'abc', 'surname' => 'DOE']];

        $dashboard = $this->minimizer->minimize($report)['dashboard'];

        $this->assertSame(['scoring' => ['score' => 500]], $dashboard);
    }

    /** @dataProvider fieldsThatAreNeverSent */
    public function testFieldsThatCanNameAnyoneOrAnythingAreDroppedWhateverTheyHold(string $field): void
    {
        $report = ['dashboard' => ['scoring' => ['score' => 500], $field => 'AB-12 Some Value']];

        $this->assertSame(['scoring' => ['score' => 500]], $this->minimizer->minimize($report)['dashboard'], $field . ' must be dropped');
    }

    public static function fieldsThatAreNeverSent(): array
    {
        $names = [
            // an identifier on its own: no digits, no compound, only the word itself protects these
            'id', 'ids', 'subjectId', 'customerId', 'memberIds', 'ID',
            // free text and references
            'comment', 'comments', 'remark', 'remarks', 'note', 'notes', 'narrative', 'memo', 'message', 'explanation', 'contractRef', 'requestRef', 'refs',
            // the other people and places in a record
            'debtor', 'debtorDetails', 'defendant', 'respondent', 'consumerStatus', 'customerType', 'accountHolder', 'holderType', 'owner', 'applicantType', 'person', 'individualType',
            'attorney', 'lawyer', 'contactPerson', 'region', 'location', 'district',
            // links and court cases
            'profileUrl', 'detailsUri', 'link', 'href', 'caseNumber', 'caseNo', 'courtCase',
        ];
        return array_combine($names, array_map(fn (string $n) => [$n], $names));
    }

    public function testFieldsThatMerelyContainTheLettersOfADeniedWordAreKept(): void
    {
        $report = ['dashboard' => ['paidAmount' => 10, 'valid' => true, 'hidden' => 1, 'identified' => 2, 'numberOfOpenContracts' => 6, 'reasonCode' => 'HMP1', 'tenure' => 12, 'telecomSector' => 'x',
            'linked' => 1, 'persons' => 2, 'notified' => 3, 'regional' => 4, 'referral' => 5, 'description' => 'Last available snapshot shows bad payment behaviour']];

        $this->assertSame($report['dashboard'], $this->minimizer->minimize($report)['dashboard'], 'only whole words in a field name count');
    }

    public function testEmailsPhonesAndIdLikeNumbersInsideTextValuesAreReplaced(): void
    {
        $report = ['dashboard' => [
            'detailText' => 'Call 0811556866 or jane.doe@example.com about 88040100488, also +264 81 155 6866',
            'statusText' => 'Contract is active and open',
            'pastDue' => '109204.00',
            'pastDueText' => '109,204.00 NAD',
            'since' => '2019-01-28',
            'stamp' => '2026-11-13T06:26:40.7517984Z',
        ]];

        $dashboard = $this->minimizer->minimize($report)['dashboard'];

        $this->assertStringNotContainsString('0811556866', $dashboard['detailText']);
        $this->assertStringNotContainsString('jane.doe', $dashboard['detailText']);
        $this->assertStringNotContainsString('88040100488', $dashboard['detailText']);
        $this->assertStringNotContainsString('155 6866', $dashboard['detailText']);
        $this->assertStringContainsString('[removed]', $dashboard['detailText']);
        $this->assertStringContainsString('about', $dashboard['detailText'], 'the rest of the text is untouched');
        $this->assertSame('Contract is active and open', $dashboard['statusText']);
        $this->assertSame('109204.00', $dashboard['pastDue'], 'an amount is not an ID');
        $this->assertSame('109,204.00 NAD', $dashboard['pastDueText']);
        $this->assertSame('2019-01-28', $dashboard['since']);
        $this->assertSame('2026-11-13T06:26:40.7517984Z', $dashboard['stamp'], 'a timestamp is not a phone number');
    }

    public function testATextValueThatIsASentenceOrLongerIsRemovedWhole(): void
    {
        $limit = str_repeat('a', 120);
        $report = ['disputes' => ['disputeList' => [[
            'status' => 'Open',
            'reasonText' => $limit,
            'detailLine' => $limit . 'b',
            'story' => 'My name is Selma and I live in Windhoek, the bank made a mistake on my loan and I want this entry removed from my record as soon as possible please.',
        ]]]];

        $dispute = $this->minimizer->minimize($report)['disputes']['disputeList'][0];

        $this->assertSame('Open', $dispute['status']);
        $this->assertSame($limit, $dispute['reasonText'], 'a label of up to 120 characters is kept');
        $this->assertSame('[text removed]', $dispute['detailLine']);
        $this->assertSame('[text removed]', $dispute['story']);
        $this->assertStringNotContainsString('Selma', $this->json($this->minimizer->minimize($report)));
    }

    public function testALongWholeNumberUnderAnInnocentNameIsTreatedAsAnIdentifier(): void
    {
        $report = ['dashboard' => ['mystery' => 88040100488, 'otherMystery' => 8005041272341.0, 'worstArrears' => 365, 'totalOutstandingAmount' => 1136984, 'pastDueAmountSum' => 109204.5]];

        $dashboard = $this->minimizer->minimize($report)['dashboard'];

        $this->assertSame('[removed]', $dashboard['mystery']);
        $this->assertSame('[removed]', $dashboard['otherMystery']);
        $this->assertSame(365, $dashboard['worstArrears']);
        $this->assertSame(1136984, $dashboard['totalOutstandingAmount']);
        $this->assertSame(109204.5, $dashboard['pastDueAmountSum']);
    }

    // ---- lenders -----------------------------------------------------------------------------------------------------

    public function testLendersBecomeLetteredAliasesThatStayTheSameForTheSameLender(): void
    {
        $minimized = $this->minimizer->minimize($this->report());

        $contracts = $minimized['contracts']['contractList'];
        $this->assertSame('Lender A', $contracts[0]['subscriber']);
        $this->assertSame('Lender A', $contracts[1]['subscriber'], 'the same lender in another spelling or letter case is still Lender A');
        $this->assertSame('Lender B', $contracts[2]['subscriber']);
        $this->assertSame('Lender C', $contracts[3]['subscriber']);
        $this->assertSame('Lender C', $minimized['inquiries']['inquiryList'][0]['subscriberName'], 'aliases are shared across sections');
        $this->assertSame('Lender D', $minimized['inquiries']['inquiryList'][1]['subscriberName']);
        $this->assertSame('Lender E', $minimized['publicDefaults']['defaultList'][0]['creditorName'], 'a creditor name is aliased too (a different spelling is a different lender to us)');
    }

    public function testTheRealNameBehindEachAliasIsKeptHereAndNeverInTheCopy(): void
    {
        $minimized = $this->minimizer->minimize($this->report());

        $this->assertSame([
            'Lender A' => 'OLD MUTUAL FINANCE (NAMIBIA) (PTY) LTD',
            'Lender B' => 'BANK WINDHOEK - HOME LOANS',
            'Lender C' => 'EXPRESS CREDIT CASH ADVANCE (PTY) LTD',
            'Lender D' => 'STANDARD BANK NAMIBIA',
            'Lender E' => 'EXPRESS CREDIT',
        ], $this->minimizer->lenders(), 'the first spelling of each lender; "Old Mutual Finance (Namibia) (Pty) Ltd" in another letter case is the same lender');
        $json = $this->json($minimized);
        foreach ($this->minimizer->lenders() as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $json, 'a real lender name must not be in the copy: ' . $name);
        }
    }

    public function testTheLenderNamesBelongToTheLastReportOnly(): void
    {
        $this->minimizer->minimize(['contracts' => ['contractList' => [['subscriber' => 'FIRST LENDER']]]]);
        $this->assertSame(['Lender A' => 'FIRST LENDER'], $this->minimizer->lenders());

        $this->minimizer->minimize(['contracts' => ['contractList' => [['subscriber' => 'SECOND LENDER']]]]);
        $this->assertSame(['Lender A' => 'SECOND LENDER'], $this->minimizer->lenders(), 'one report never carries names over to the next');

        $this->minimizer->minimize(['individual' => ['names' => 'Jane Doe']]);
        $this->assertSame([], $this->minimizer->lenders(), 'a report with nothing to send has no lenders');
    }

    public function testALenderNameIsTrimmedButOtherwiseKeptAsWritten(): void
    {
        $this->minimizer->minimize(['contracts' => ['contractList' => [['subscriber' => "  Old Mutual Finance (Pty) Ltd \n"]]]]);

        $this->assertSame(['Lender A' => 'Old Mutual Finance (Pty) Ltd'], $this->minimizer->lenders());
    }

    public function testAliasLettersRestartForEveryReport(): void
    {
        $a = ['contracts' => ['contractList' => [['subscriber' => 'FIRST LENDER']]]];
        $b = ['contracts' => ['contractList' => [['subscriber' => 'SECOND LENDER']]]];

        $this->assertSame('Lender A', $this->minimizer->minimize($a)['contracts']['contractList'][0]['subscriber']);
        $this->assertSame('Lender A', $this->minimizer->minimize($b)['contracts']['contractList'][0]['subscriber'], 'one report never influences another');
    }

    public function testPlaintiffsClaimantsAndOriginatorsAreAliasedLikeLenders(): void
    {
        $report = ['judgements' => ['judgementList' => [
            ['plaintiff' => 'ACME FINANCE (PTY) LTD', 'amount' => 5000.0, 'judgementDate' => '2025-02-03'],
            ['claimant' => 'acme finance (pty) ltd', 'amount' => 700.0],
            ['originator' => 'OTHER LENDER CC', 'plaintiffAttorney' => 'Smith & Partners'],
        ]]];

        $list = $this->minimizer->minimize($report)['judgements']['judgementList'];

        $this->assertSame('Lender A', $list[0]['plaintiff']);
        $this->assertSame('Lender A', $list[1]['claimant'], 'the same company is the same lender');
        $this->assertSame('Lender B', $list[2]['originator']);
        $this->assertArrayNotHasKey('plaintiffAttorney', $list[2], 'a lawyer is a person, not a lender');
        $this->assertStringNotContainsStringIgnoringCase('acme', $this->json($this->minimizer->minimize($report)));
    }

    public function testALenderFieldThatAlsoHoldsAnAddressOrPhoneIsDroppedNotAliased(): void
    {
        $report = ['contracts' => ['contractList' => [['subscriberAddress' => '5 Some Road', 'subscriberPhone' => '0612345678', 'subscriberName' => 'LENDER X', 'pastDueDays' => 5]]]];

        $this->assertSame([['subscriberName' => 'Lender A', 'pastDueDays' => 5]], $this->minimizer->minimize($report)['contracts']['contractList']);
    }

    // ---- the credit facts stay ---------------------------------------------------------------------------------------

    public function testEveryFactTheExplanationNeedsIsKept(): void
    {
        $json = $this->json($this->minimizer->minimize($this->report()));

        foreach (['480', 'E2', '76.05', 'HMP1', 'bad payment behaviour', '109204', '1136984', '21920', '365', '275', '"numberOfOpenContracts":6', '43757.85', '23346', '273', 'Installment', '"activeDisputes":0', '2026-03-02'] as $fact) {
            $this->assertStringContainsString($fact, $json, 'must be kept: ' . $fact);
        }
    }

    // ---- shrinking ---------------------------------------------------------------------------------------------------

    public function testLongPaymentListsKeepTheNewestRowsWhateverTheirOrder(): void
    {
        $report = $this->report();
        $newestFirst = $this->minimizer->minimize($report)['contracts']['contractList'][0]['payments'];
        $this->assertCount(14, $newestFirst);
        $this->assertSame('2026 Nov', $newestFirst[0]['period']);
        $this->assertSame('2025 Oct', $newestFirst[13]['period']);

        $report['contracts']['contractList'][0]['payments'] = array_reverse($report['contracts']['contractList'][0]['payments']);   // oldest first
        $oldestFirst = $this->minimizer->minimize($report)['contracts']['contractList'][0]['payments'];
        $this->assertCount(14, $oldestFirst);
        $this->assertSame('2026 Nov', $oldestFirst[0]['period'], 'sorted so the 14 kept are the 14 newest');
        $this->assertSame('2025 Oct', $oldestFirst[13]['period']);
    }

    public function testShortListsAreUntouched(): void
    {
        $minimized = $this->minimizer->minimize($this->report());

        $this->assertCount(4, $minimized['contracts']['contractList']);
        $this->assertCount(2, $minimized['dashboard']['scoring']['reasonCodes']);
    }

    public function testListsWithoutDatesKeepTheirFirstEntries(): void
    {
        $report = ['contracts' => ['contractList' => array_map(fn (int $i) => ['pastDueDays' => $i], range(1, 30))]];

        $kept = $this->minimizer->minimize($report)['contracts']['contractList'];

        $this->assertCount(14, $kept);
        $this->assertSame(1, $kept[0]['pastDueDays']);
        $this->assertSame(14, $kept[13]['pastDueDays']);
    }

    public function testAHugeReportIsShrunkToTheLimitAndKeepsItsDashboard(): void
    {
        $report = ['dashboard' => ['scoring' => ['score' => 480, 'scoreGrade' => 'E2']], 'contracts' => ['contractList' => []]];
        for ($i = 0; $i < 400; $i++) {
            $report['contracts']['contractList'][] = ['pastDueDays' => $i, 'note' => str_repeat('word ', 40), 'payments' => array_map(fn (int $m) => ['period' => sprintf('2024-%02d', $m % 12 + 1), 'pastDue' => $m * 1.5], range(1, 30))];
        }

        $minimized = $this->minimizer->minimize($report, 20000);

        $this->assertLessThanOrEqual(20000, strlen($this->json($minimized)));
        $this->assertSame(480, $minimized['dashboard']['scoring']['score'], 'the dashboard is never the thing that is dropped');
    }

    public function testWhenListsCannotBeShortenedEnoughTheLargestOtherSectionIsDropped(): void
    {
        $wide = [];
        for ($i = 0; $i < 600; $i++) {
            $wide['field' . $i] = str_repeat('x', 30);   // an object, not a list: shortening lists cannot help
        }
        $report = ['dashboard' => ['scoring' => ['score' => 480]], 'contracts' => $wide, 'inquiries' => ['inquiryList' => [['pastDueDays' => 1]]]];

        $minimized = $this->minimizer->minimize($report, 3000);

        $this->assertArrayNotHasKey('contracts', $minimized, 'the largest section that is not the dashboard goes first');
        $this->assertArrayHasKey('inquiries', $minimized, 'only as much as needed is dropped');
        $this->assertSame(480, $minimized['dashboard']['scoring']['score']);
        $this->assertLessThanOrEqual(3000, strlen($this->json($minimized)));
    }

    public function testADashboardThatIsTooBigOnItsOwnIsKeptAndTheTrimmingStops(): void
    {
        $wide = [];
        for ($i = 0; $i < 600; $i++) {
            $wide['field' . $i] = str_repeat('x', 30);
        }
        $report = ['dashboard' => $wide, 'contracts' => ['contractList' => [['pastDueDays' => 1]]]];

        $minimized = $this->minimizer->minimize($report, 3000);

        $this->assertSame(['dashboard'], array_keys($minimized), 'everything else is dropped, but never the dashboard, and the loop ends');
        $this->assertCount(600, $minimized['dashboard']);
    }

    // ---- edges -------------------------------------------------------------------------------------------------------

    public function testNothingToExplainGivesAnEmptyCopy(): void
    {
        $this->assertSame([], $this->minimizer->minimize([]));
        $this->assertSame([], $this->minimizer->minimize(['individual' => ['names' => 'Jane Doe'], 'reportInfo' => ['created' => '2026-11-13']]), 'only personal sections: nothing at all goes out');
        $this->assertSame([], $this->minimizer->minimize(['dashboard' => [], 'contracts' => 'text']), 'empty or non-array sections are ignored');
    }

    public function testTheInputIsNeverChanged(): void
    {
        $report = $this->report();
        $before = serialize($report);

        $this->minimizer->minimize($report);

        $this->assertSame($before, serialize($report));
    }

    public function testAnIdentifierThatCouldNotBeRemovedStopsEverything(): void
    {
        // A field NAME that is itself an ID number cannot be recognised by its words and is not a value, so the last-line check must catch it.
        $report = ['dashboard' => ['8005041272341' => 1, 'scoring' => ['score' => 400]]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still contains something that looks like an identifier');
        $this->minimizer->minimize($report);
    }

    public function testAnEmailInsideAFieldNameAlsoStopsEverything(): void
    {
        $report = ['dashboard' => ['jane@example.com' => 1, 'scoring' => ['score' => 400]]];

        $this->expectException(\RuntimeException::class);
        $this->minimizer->minimize($report);
    }
}
