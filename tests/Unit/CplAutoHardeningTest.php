<?php

namespace Tests\Unit;

use App\Controllers\CplSettingController;
use App\Services\CplAutoSelector;
use App\Services\CplAutoSubmissionService;
use App\Services\CplFileValidator;
use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LoanFollowupFlow;
use App\Support\AsciiText;
use App\Support\CplAutoSubmitArgs;
use PHPUnit\Framework\TestCase;

/**
 * The pure rules around the automatic CPL submission: the command line, the last check of the file bytes, the zero-instalment closure rule, the plain-ASCII
 * rule, the month arithmetic and the settings that need the approval permission. No database, no network.
 */
class CplAutoHardeningTest extends TestCase
{
    // --------------------------------------------------------------- command line (item 6)

    public function testTheArgumentParserAcceptsExactlyTheDocumentedShapes(): void
    {
        $this->assertSame(['kind' => 'daily', 'dry_run' => false, 'date' => null], CplAutoSubmitArgs::parse(['bin/cpl_auto_submit.php', '--daily']));
        $this->assertSame(['kind' => 'monthly', 'dry_run' => true, 'date' => '2026-11-30'], CplAutoSubmitArgs::parse(['x', '--monthly', '--dry-run', '--date=2026-11-30']));
        $this->assertSame(['kind' => 'daily', 'dry_run' => true, 'date' => '2024-02-29'], CplAutoSubmitArgs::parse(['x', '--date=2024-02-29', '--dry-run', '--daily']));
    }

    /**
     * @dataProvider badArguments
     * @param string[] $args
     */
    public function testAnythingElseIsRefusedBeforeAnythingIsTouched(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CplAutoSubmitArgs::parse(array_merge(['bin/cpl_auto_submit.php'], $args));
    }

    /** @return array<string, array{0: string[]}> */
    public static function badArguments(): array
    {
        return [
            'nothing' => [[]],
            'both kinds' => [['--daily', '--monthly']],
            'only dry-run' => [['--dry-run']],
            'unknown flag' => [['--daily', '--force']],
            'positional word' => [['--daily', 'now']],
            'daily twice' => [['--daily', '--daily']],
            'dry-run twice' => [['--daily', '--dry-run', '--dry-run']],
            'date twice' => [['--daily', '--date=2026-01-01', '--date=2026-01-02']],
            'a prefix of a flag' => [['--dail']],
            'a value on a flag that takes none' => [['--daily=1']],
            'single dash' => [['-daily']],
            'date not real' => [['--daily', '--date=2026-02-30']],
            'date wrong shape' => [['--daily', '--date=26-11-01']],
            'date empty' => [['--daily', '--date=']],
            'date as separate word' => [['--daily', '--date', '2026-11-01']],
            'help' => [['--help']],
        ];
    }

    // ---------------------------------------------------- final file validation (item 7)

    private function dailyLine(string $date = '20181011', string $kind = 'R'): string
    {
        return $kind . str_repeat(' ', 709) . $date;
    }

    private function monthlyFile(int $records = 2, string $monthEnd = '2018-10-31'): string
    {
        $b = new CplRecordBuilder();
        $lines = [$b->header('NA02629', $monthEnd, 'Solid Desert Cash Loan')];
        for ($i = 0; $i < $records; $i++) {
            $lines[] = 'D' . str_repeat(' ', 699);
        }
        $lines[] = $b->trailer($records + 2);
        return implode("\r\n", $lines);
    }

    public function testAGoodDailyFileAndAGoodMonthlyFilePass(): void
    {
        $name = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $daily = $this->dailyLine() . "\r\n" . $this->dailyLine('20181011', 'C');
        $this->assertSame([], CplFileValidator::validate('daily', $daily, 2, $name, $name, '2018-10-11'));

        $mName = 'NA02629_ALL_L702_M_20181031_1_1.txt';
        $this->assertSame([], CplFileValidator::validate('monthly', $this->monthlyFile(), 2, $mName, $mName, '2018-10-31'));
    }

    public function testAWrongWidthIsRefused(): void
    {
        $name = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $p = CplFileValidator::validate('daily', substr($this->dailyLine(), 0, 717) . "\r\n" . $this->dailyLine(), 2, $name, $name, '2018-10-11');
        $this->assertNotEmpty($p);
        $this->assertStringContainsString('718', implode(' ', $p));
        $this->assertStringContainsString('line 1', implode(' ', $p));

        $mName = 'NA02629_ALL_L702_M_20181031_1_1.txt';
        $lines = explode("\r\n", $this->monthlyFile());
        $lines[1] .= ' ';
        $this->assertStringContainsString('700', implode(' ', CplFileValidator::validate('monthly', implode("\r\n", $lines), 2, $mName, $mName, '2018-10-31')));
    }

    public function testNonAsciiAndControlCharactersAreRefusedEvenWhenTheWidthHappensToBeRight(): void
    {
        $name = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $line = $this->dailyLine();
        // a two-byte character replacing two spaces keeps the byte width at 718 but is not ASCII
        $bad = substr($line, 0, 100) . "\u{E9}" . substr($line, 102);
        $this->assertSame(718, strlen($bad));
        $p = CplFileValidator::validate('daily', $bad, 1, $name, $name, '2018-10-11');
        $this->assertStringContainsString('printable ASCII', implode(' ', $p));
        $tab = substr($line, 0, 100) . "\t" . substr($line, 101);
        $this->assertStringContainsString('printable ASCII', implode(' ', CplFileValidator::validate('daily', $tab, 1, $name, $name, '2018-10-11')));
    }

    public function testTheLineCountMustEqualTheRecordCount(): void
    {
        $name = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $p = CplFileValidator::validate('daily', $this->dailyLine() . "\r\n" . $this->dailyLine(), 3, $name, $name, '2018-10-11');
        $this->assertStringContainsString('2 line(s) but 3 were expected', implode(' ', $p));
        $this->assertNotEmpty(CplFileValidator::validate('daily', '', 1, $name, $name, '2018-10-11'));
    }

    public function testTheMonthlyHeaderAndTrailerCountsAreChecked(): void
    {
        $mName = 'NA02629_ALL_L702_M_20181031_1_1.txt';
        $b = new CplRecordBuilder();
        $lines = explode("\r\n", $this->monthlyFile());
        $lines[count($lines) - 1] = $b->trailer(99);
        $this->assertStringContainsString('trailer count', implode(' ', CplFileValidator::validate('monthly', implode("\r\n", $lines), 2, $mName, $mName, '2018-10-31')));
        $this->assertStringContainsString('header', implode(' ', CplFileValidator::validate('monthly', $this->monthlyFile(2, '2018-09-30'), 2, $mName, $mName, '2018-10-31')));
        $noTrailer = implode("\r\n", array_slice(explode("\r\n", $this->monthlyFile()), 0, 3));
        $this->assertNotEmpty(CplFileValidator::validate('monthly', $noTrailer, 2, $mName, $mName, '2018-10-31'));
    }

    public function testTheFileNameMustBeTheExpectedLiveName(): void
    {
        $content = $this->dailyLine();
        $expected = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $this->assertNotEmpty(CplFileValidator::validate('daily', $content, 1, $expected, 'NA02629_ALL_L702_D_20181012_1_1.txt', '2018-10-11'));
        $this->assertNotEmpty(CplFileValidator::validate('daily', $content, 1, 'NA02629_ALL_T702_D_20181011_1_1.txt', 'NA02629_ALL_T702_D_20181011_1_1.txt', '2018-10-11'), 'a test name is never a live name');
        $this->assertSame([], CplFileValidator::validate('daily', $content, 1, $expected, $expected, '2018-10-11'));
    }

    public function testTheRecordBuilderPadsMultibyteTextSafely(): void
    {
        $b = new CplRecordBuilder();
        $header = $b->header("NA\u{E9}2629", '2018-10-31', "Sol\u{EF}d D\u{E9}sert \u{20AC} Loan");
        $this->assertSame(700, strlen($header));
        $this->assertTrue(AsciiText::isPrintableAscii($header));
        $this->assertStringContainsString('Solid Desert', $header);
    }

    // -------------------------------------------- closure rule (item 1) and the validator

    public function testTheValidatorRefusesAClosedAccountWithAnInstalment(): void
    {
        $fields = $this->goodFields();
        $this->assertSame([], $this->blocking($fields));
        foreach (['C', 'V', 'X', 'T'] as $status) {
            $f = ['status_code' => $status, 'status_date' => '20181025', 'current_balance' => 0, 'instalment_amount' => 100] + $fields;
            $f['status_code'] = $status;
            $f['instalment_amount'] = 100;
            $this->assertContains('instalment_amount', $this->blocking($f), 'status ' . $status . ' with an instalment');
            $f['instalment_amount'] = 0;
            $this->assertNotContains('instalment_amount', $this->blocking($f), 'status ' . $status . ' with a zero instalment');
        }
        // a written-off account must still carry an instalment (CUSTNAM074)
        $w = ['status_code' => 'W', 'status_date' => '20181025', 'instalment_amount' => 0] + $fields;
        $this->assertContains('instalment_amount', $this->blocking($w));
    }

    public function testADailyClosureRecordCarriesZeroInstalmentOverdueAndArrears(): void
    {
        $selector = new CplAutoSelector();
        $e = $this->entry(3, '2018-10-10', 2, 2);
        $sel = $selector->daily([$e], [CplAutoSelector::reportedKey(3, 'R') => true], '2018-12-31');
        $this->assertCount(1, $sel['records']);
        $c = $sel['records'][0]['fields'];
        $this->assertSame('C', $c['status_code']);
        $this->assertSame(0, $c['instalment_amount']);
        $this->assertSame(0, $c['amount_overdue']);
        $this->assertSame(0, $c['months_in_arrears']);
        $this->assertSame([], $this->blocking($c));
    }

    // ----------------------------------------------------------- month arithmetic (item 2)

    public function testMonthEndsNeverSkipAShortMonthAfterA31st(): void
    {
        $this->assertSame('2018-02-28', CplAutoSubmissionService::nextMonthEnd('2018-01-31'));
        $this->assertSame('2018-04-30', CplAutoSubmissionService::nextMonthEnd('2018-03-31'));
        $this->assertSame('2016-02-29', CplAutoSubmissionService::nextMonthEnd('2016-01-31'));
        $this->assertSame('2018-12-31', CplAutoSubmissionService::nextMonthEnd('2018-11-30'));
        $this->assertSame('2019-01-31', CplAutoSubmissionService::nextMonthEnd('2018-12-31'));
        $this->assertSame('2018-02-28', CplAutoSubmissionService::previousMonthEnd('2018-03-31'));
        $this->assertSame('2017-12-31', CplAutoSubmissionService::previousMonthEnd('2018-01-31'));
        $this->assertSame('2018-01-31', CplAutoSubmissionService::monthEnd('2018-01-15'));
        $this->assertSame(['2018-01-31', '2018-02-28', '2018-03-31', '2018-04-30'], CplAutoSubmissionService::monthEnds('2018-01-31', '2018-04-30'));
        $this->assertSame(['2018-01-31', '2018-02-28'], CplAutoSubmissionService::monthEnds('2018-01-10', '2018-12-31', 2));
    }

    public function testTheMonthWindowIsNotCutOffAtTheRunLimit(): void
    {
        $all = CplAutoSubmissionService::monthEnds('2016-01-31', '2018-11-30');
        $this->assertCount(35, $all, 'the list is complete; the per-run cap is applied later, after the Submitted months are skipped');
        $this->assertSame('2018-11-30', end($all));
        $this->assertSame(24, CplAutoSubmissionService::MAX_MONTHS_PER_RUN);
    }

    public function testOnlyALoanQuestionsBatchMayBeTakenOver(): void
    {
        $lq = CplAutoSubmissionService::MANUAL_LOAN_QUESTIONS_ORIGIN;
        $this->assertTrue(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'Local']));
        $this->assertTrue(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'Local', 'legacy_summary' => null]));
        $this->assertTrue(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'SolidFin', 'legacy_summary' => json_encode(['origin' => $lq])]));
        $this->assertFalse(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'SolidFin', 'legacy_summary' => json_encode(['origin' => 'Extracted from the old loan system'])]), 'a full-book batch');
        $this->assertFalse(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'SolidFin', 'legacy_summary' => json_encode(['sent' => 941])]));
        $this->assertFalse(CplAutoSubmissionService::isLoanQuestionsKind(['source' => 'Local', 'legacy_summary' => json_encode(['origin' => 'Something else'])]));
        $this->assertFalse(CplAutoSubmissionService::isLoanQuestionsKind([]));
    }

    public function testThePreviewPostedByThePageIsOnlyAStalenessCheck(): void
    {
        $measured = ['daily' => 100, 'monthly' => 50];
        $this->assertFalse(CplAutoSubmissionService::previewDiffers(['daily' => 100, 'monthly' => 50], $measured));
        $this->assertFalse(CplAutoSubmissionService::previewDiffers(['daily' => 110, 'monthly' => 45], $measured), 'exactly 10% is still the same page');
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 111, 'monthly' => 50], $measured));
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 100, 'monthly' => 44], $measured));
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 0, 'monthly' => 0], $measured), 'a posted 0 cannot make a big first file look small');
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(null, $measured), 'no posted numbers = a stale or forged page');
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 100], $measured));
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 'x', 'monthly' => 50], $measured));
        $this->assertFalse(CplAutoSubmissionService::previewDiffers(['daily' => 0, 'monthly' => 0], ['daily' => 0, 'monthly' => 0]));
        $this->assertTrue(CplAutoSubmissionService::previewDiffers(['daily' => 1, 'monthly' => 0], ['daily' => 0, 'monthly' => 0]));
    }

    public function testTheEnableActionNeverStoresThePostedPreview(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/app/Controllers/CplAutoController.php');
        $this->assertStringContainsString('previewForEnable(', $src);
        $this->assertStringContainsString('$preview = $check[\'preview\'];', $src, 'the stored preview is the server-measured one');
        $this->assertStringNotContainsString("\$preview = \$postedPreview", $src);
        $this->assertDoesNotMatchRegularExpression('/setEnabled\([^;]*\$postedPreview/s', $src);
    }

    // -------------------------------------------------------------- settings authorization (item 4)

    public function testTheRestrictedSettingsAreExactlyTheOnesThatDecideWhereAndWhetherFilesGo(): void
    {
        $keys = CplSettingController::restrictedKeys();
        foreach (['submission_environment', 'sftp_submission_enabled', 'sftp_directory', 'supplier_reference_number', 'recipient', 'cpl_auto_excluded_names', 'cpl_monthly_send_day', 'cpl_auto_alert_user_ids'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertContains('trading_name', $keys, 'the trading name is written into every monthly record');
        $this->assertContains('cpl_public_base_url', $keys);
        foreach (['submission_environment', 'sftp_submission_enabled', 'sftp_directory', 'supplier_reference_number', 'recipient', 'trading_name'] as $k) {
            $this->assertContains($k, CplSettingController::approvalRevokingKeys());
        }
    }

    public function testOnlyARealChangeOfARestrictedSettingIsReportedWithOldAndNewValue(): void
    {
        $current = ['submission_environment' => 'uat', 'trading_name' => 'A', 'recipient' => 'ALL', 'cpl_monthly_send_day' => '3'];
        $new = ['submission_environment' => 'production', 'trading_name' => 'B', 'recipient' => 'ALL', 'sftp_submission_enabled' => 'off', 'cpl_monthly_send_day' => '5'];
        $this->assertSame([
            'submission_environment' => ['uat', 'production'],
            'cpl_monthly_send_day' => ['3', '5'],
            'trading_name' => ['A', 'B'],
        ], CplSettingController::changedRestricted($current, $new));
        $this->assertSame([], CplSettingController::changedRestricted($current, ['cpl_version' => 'Z']), 'an unrestricted change needs no approval permission');
        $this->assertSame([], CplSettingController::changedRestricted([], ['submission_environment' => 'uat', 'sftp_submission_enabled' => 'off', 'recipient' => 'ALL']), 'the defaults are not a change');
    }

    // ------------------------------------------------------------- script wiring (item 5, 14)

    public function testTheSandboxAndDemoBuildsForceAutomaticSubmissionOff(): void
    {
        foreach (['sandbox_build.php', 'demo_seed.php'] as $script) {
            $src = (string) file_get_contents(ROOT_PATH . '/bin/' . $script);
            $this->assertStringContainsString("'cpl_auto_submit_enabled' => '0'", $src, $script);
            $this->assertStringContainsString("'submission_environment' => 'uat'", $src, $script);
            $this->assertStringContainsString("'cpl_auto_standing_approval' => ''", $src, $script);
        }
    }

    public function testTheMigrationChecksItsPrerequisitesAndSeedsTheNewSettings(): void
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/database/cpl_auto_submission.sql');
        foreach (['cpl_legacy_source.sql', 'cpl_uat_signoff.sql', 'FROM cpl_batches LIMIT 0', 'scheduled_job_heartbeats', 'cpl_auto_standing_approval', 'cpl_auto_halted_reason'] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        // the probes for the Loan Questions tables and the application columns the mapper reads, and the migration files that provide them
        foreach (['FROM loan_followup_instalments LIMIT 0', 'FROM loan_followup_payments LIMIT 0', 'FROM loan_followups LIMIT 0', 'purged_at', 'applicant_id_number', 'FROM loan_applications LIMIT 0',
            'loan_followups.sql', 'applicant_correction_and_purge.sql', 'cpl_public_base_url'] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        foreach (['settings.php', 'auto/enable.php', 'auto/enable.php.content'] as $view) {
            $this->assertFileExists(ROOT_PATH . '/app/Views/reports/cpl_export/' . $view);
        }
    }

    // --------------------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function goodFields(): array
    {
        return [
            'na_id' => '85051500123', 'non_na_id' => '', 'date_of_birth' => '19850515', 'gender' => 'F', 'surname' => 'Test', 'forename1' => 'Maria', 'forename2' => '',
            'residential_line1' => '1 Test Street', 'residential_line2' => 'Windhoek', 'residential_line3' => '', 'cellular_telephone' => '0812345678',
            'employer_detail' => 'Test Employer', 'income' => 9500, 'income_frequency' => 'M', 'account_no' => 'APP-T1', 'type_of_account' => 'P', 'terms' => 3,
            'payment_type' => '00', 'repayment_frequency' => 3, 'loan_reason_code' => 'O', 'date_account_opened' => '20181010', 'date_of_last_payment' => null,
            'opening_balance' => 1300, 'current_balance' => 1300, 'current_balance_indicator' => 'D', 'amount_overdue' => 0, 'instalment_amount' => 434,
            'months_in_arrears' => 0,
        ];
    }

    /** @return string[] */
    private function blocking(array $fields): array
    {
        return array_column(array_filter((new CplValidationService())->validate($fields), static fn ($i) => $i['severity'] === 'BLOCKING'), 'field');
    }

    /** @return array<string,mixed> */
    private function entry(int $fid, string $paidOut, int $months, int $paid): array
    {
        $schedule = (new LoanFollowupFlow())->schedule(1000.0, $months, $paidOut, 25);
        $instalments = [];
        $payments = [];
        foreach ($schedule as $i => $s) {
            $id = $fid * 100 + $s['instalment_no'];
            $isPaid = $i < $paid;
            $instalments[] = [
                'id' => $id, 'followup_id' => $fid, 'instalment_no' => $s['instalment_no'], 'due_date' => $s['due_date'], 'expected_amount' => $s['expected_amount'],
                'paid_amount' => $isPaid ? $s['expected_amount'] : null, 'paid_on' => $isPaid ? $s['due_date'] : null,
                'answered_at' => $isPaid ? $s['due_date'] . ' 09:00:00' : null, 'next_ask_at' => null,
            ];
            if ($isPaid) {
                $payments[$id] = [['amount' => (string) $s['expected_amount'], 'paid_on' => $s['due_date']]];
            }
        }
        return [
            'followup' => ['id' => $fid, 'application_id' => $fid, 'status' => 'Open', 'approved' => 'Yes', 'disbursed' => 'Yes', 'disbursed_on' => $paidOut, 'approved_amount' => '1000.00', 'term_months' => $months, 'pay_day' => 25],
            'application' => [
                'id' => $fid, 'application_no' => 'APP-T' . $fid, 'applicant_first_name' => 'Tester' . $fid, 'applicant_middle_name' => '', 'applicant_last_name' => 'Auto' . $fid,
                'applicant_id_number' => '9001011234' . ($fid % 10), 'applicant_gender' => 'Male', 'applicant_phone' => '0811234567', 'applicant_address' => '1 Test Street Windhoek',
                'employer_name' => 'Test Employer', 'gross_salary' => '12000', 'payment_day' => 25, 'requested_term_months' => $months,
            ],
            'instalments' => $instalments,
            'payments' => $payments,
        ];
    }
}
