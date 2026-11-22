<?php

namespace Tests\Unit;

use App\Services\CreditCheckReadiness;
use App\Services\CreditinfoApiException;
use App\Services\CreditinfoErrorTranslator;
use App\Support\DateOfBirth;
use PHPUnit\Framework\TestCase;

class CreditCheckReadinessTest extends TestCase
{
    private const TODAY = '2026-11-22';

    private function app(array $over = []): array
    {
        return $over + ['applicant_first_name' => 'Alexander', 'applicant_last_name' => 'Iipinge', 'applicant_gender' => 'Male', 'applicant_phone' => '0811234567'];
    }

    public function testApplication70CaseIsBlockedWithAClearMessage(): void
    {
        $issues = CreditCheckReadiness::check($this->app(), '2026-11-19', '90010112345', 1, self::TODAY);
        $errors = CreditCheckReadiness::errors($issues);
        $this->assertCount(1, $errors);
        $this->assertSame('dob', $errors[0]['field']);
        $this->assertStringContainsString('0 years old', $errors[0]['problem']);
        $this->assertStringContainsString('Date of birth', CreditCheckReadiness::flashMessage($issues));
    }

    public function testAllProblemsAreReportedTogether(): void
    {
        $issues = CreditCheckReadiness::check($this->app(['applicant_first_name' => '', 'applicant_phone' => '12']), null, '', null, self::TODAY);
        $fields = array_column(CreditCheckReadiness::errors($issues), 'field');
        foreach (['id_number', 'first_name', 'gender', 'dob', 'phone'] as $f) {
            $this->assertContains($f, $fields);
        }
        $this->assertSame('your ID number, your first name, your gender, your date of birth and your mobile number', CreditCheckReadiness::clientPhrase($issues));
    }

    public function testGoodApplicationPasses(): void
    {
        $this->assertSame([], CreditCheckReadiness::check($this->app(), '1990-01-01', '90010112345', 1, self::TODAY));
    }

    public function testIdDobMismatchIsOnlyAWarning(): void
    {
        $issues = CreditCheckReadiness::check($this->app(), '1991-02-03', '90010112345', 1, self::TODAY);
        $this->assertSame([], CreditCheckReadiness::errors($issues));
        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['severity']);
    }

    public function testIdWithSymbolsBlocksButOtherLengthsOnlyWarn(): void
    {
        $this->assertNotSame([], CreditCheckReadiness::errors(CreditCheckReadiness::check($this->app(), '1990-01-01', '9001-0112345', 1, self::TODAY)));
        $this->assertNotSame([], CreditCheckReadiness::errors(CreditCheckReadiness::check($this->app(), '1990-01-01', '', 1, self::TODAY)));
        $issues = CreditCheckReadiness::check($this->app(), '1980-05-04', '8005041272341', 1, self::TODAY); // the vendor's own 13-digit test ID
        $this->assertSame([], CreditCheckReadiness::errors($issues));
        $this->assertSame('warning', $issues[0]['severity']);
    }

    public function testGenderTextIsNeverEchoed(): void
    {
        $issues = CreditCheckReadiness::check($this->app(['applicant_gender' => '<script>x</script>']), '1990-01-01', '90010112345', null, self::TODAY);
        $this->assertStringNotContainsString('script', json_encode($issues));
    }

    public function testAuthAndTimeoutFailuresAreNotBlamedOnTheApplicantData(): void
    {
        $auth = CreditinfoErrorTranslator::translate(new \App\Services\CreditinfoAuthException('Creditinfo authentication failed (HTTP 400).', 400));
        $this->assertStringContainsString('refused our login', $auth);
        $this->assertStringNotContainsString('National ID', $auth);
        $to = CreditinfoErrorTranslator::translate(new \App\Services\CreditinfoTimeoutException('Failed to reach the Creditinfo API: timed out'));
        $this->assertStringContainsString('may or may not have reached', $to);
    }

    public function testDateOfBirthRules(): void
    {
        $this->assertNull(DateOfBirth::check('1990-02-28', self::TODAY));
        $this->assertNull(DateOfBirth::check('2008-11-22', self::TODAY)); // exactly 18 today
        $this->assertNotNull(DateOfBirth::check('2008-11-23', self::TODAY)); // 17
        $this->assertNotNull(DateOfBirth::check('2027-01-01', self::TODAY)); // future
        $this->assertNotNull(DateOfBirth::check('1900-01-01', self::TODAY)); // 126
        $this->assertNotNull(DateOfBirth::check('2026-02-31', self::TODAY)); // not a real date
        $this->assertNotNull(DateOfBirth::check('19/05/1990', self::TODAY)); // wrong format
        $this->assertNotNull(DateOfBirth::check('', self::TODAY));
        $this->assertSame('1976-07-16', DateOfBirth::normalize('1976-07-16T00:00:00'));
    }

    public function testTranslatorNamesTheFieldFromVariousBodyShapes(): void
    {
        $bodies = [
            '{"status":"Error","message":"DateOfBirth cannot be empty"}',
            '{"errors":{"DateOfBirth":["Invalid"]}}',
            '[{"propertyName":"parameters.dateOfBirth","errorMessage":"x"}]',
        ];
        foreach ($bodies as $b) {
            $msg = CreditinfoErrorTranslator::translate(new CreditinfoApiException('x', 400, $b));
            $this->assertStringContainsString('Date of birth', $msg, $b);
            $this->assertStringNotContainsString('HTTP 400, status', $msg);
        }
    }

    public function testTranslatorFallsBackAndNeverEchoesTheBody(): void
    {
        $msg = CreditinfoErrorTranslator::translate(new CreditinfoApiException('x', 400, '{"detail":"John Smith 90010112345"}'));
        $this->assertStringContainsString('without saying which field', $msg);
        $this->assertStringNotContainsString('90010112345', $msg);
        $this->assertStringContainsString('login', CreditinfoErrorTranslator::translate(new CreditinfoApiException('x', 401, '')));
        $this->assertStringContainsString('too many', CreditinfoErrorTranslator::translate(new CreditinfoApiException('x', 429, '{}')));
        $this->assertStringContainsString('their side', str_replace('on its side', 'their side', CreditinfoErrorTranslator::translate(new CreditinfoApiException('x', 503, '<html>'))));
        $this->assertStringContainsString('No request was made', CreditinfoErrorTranslator::translate(new CreditinfoApiException('The Creditinfo integration is disabled.')));
    }
}
