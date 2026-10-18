<?php

namespace Tests\Unit;

use App\Controllers\DebitOrderCollexiaController;
use PHPUnit\Framework\TestCase;

/**
 * Pins DebitOrderCollexiaController::mandateDebtorExtraFields() -- the V4
 * mandate load/update installment additions (Desert Ledger Test Pack V4.0,
 * Configuration sheet: "Include dateOfBirth, gender, debtorTelephone where
 * applicable") -- via reflection, since it's a private static pure
 * function of its array argument and needs no DB (PHPUnit on this dev
 * machine can't touch it -- see CollexiaClientTest's docblock).
 */
class DebitOrderCollexiaControllerMandateFieldsTest extends TestCase
{
    private function call(array $debitOrder): array
    {
        $method = new \ReflectionMethod(DebitOrderCollexiaController::class, 'mandateDebtorExtraFields');
        $method->setAccessible(true);
        return $method->invoke(null, $debitOrder);
    }

    public function testIncludesAllThreeFieldsWhenFullyOnFile(): void
    {
        $fields = $this->call([
            'date_of_birth' => '1990-03-15',
            'gender' => 'Female',
            'phone' => '0817001001',
        ]);

        $this->assertSame([
            'dateOfBirth' => '19900315',
            'gender' => 2,
            'debtorTelephone' => '0817001001',
        ], $fields);
    }

    public function testMaleGenderMapsToCodeOne(): void
    {
        $fields = $this->call(['gender' => 'Male']);
        $this->assertSame(1, $fields['gender']);
    }

    public function testFemaleGenderMapsToCodeTwo(): void
    {
        $fields = $this->call(['gender' => 'Female']);
        $this->assertSame(2, $fields['gender']);
    }

    public function testOtherGenderIsOmittedRatherThanGuessed(): void
    {
        $fields = $this->call(['gender' => 'Other']);
        $this->assertArrayNotHasKey('gender', $fields);
    }

    public function testBlankDateOfBirthIsOmittedRatherThanSendingAFakeDate(): void
    {
        $fields = $this->call(['date_of_birth' => null]);
        $this->assertArrayNotHasKey('dateOfBirth', $fields);
    }

    public function testBlankPhoneIsOmittedRatherThanSendingAnEmptyString(): void
    {
        $fields = $this->call(['phone' => '']);
        $this->assertArrayNotHasKey('debtorTelephone', $fields);
    }

    public function testMissingKeysEntirelyProduceAnEmptyArrayNotAWarning(): void
    {
        $this->assertSame([], $this->call([]));
    }

    public function testDateOfBirthIsFormattedYmdNotTheRawDbDateFormat(): void
    {
        $fields = $this->call(['date_of_birth' => '1985-12-01']);
        $this->assertSame('19851201', $fields['dateOfBirth']);
    }

    // --- splitSuffix() -- see its own docblock: a letter, never a digit,
    // so a truncated userReference can never be misread as a real loan
    // reference from this app's purely-numeric "SDLOAN%04d" scheme. ---

    private function suffix(int $splitNo): string
    {
        $method = new \ReflectionMethod(DebitOrderCollexiaController::class, 'splitSuffix');
        $method->setAccessible(true);
        return $method->invoke(null, $splitNo);
    }

    public function testSplitOneIsLetterA(): void
    {
        $this->assertSame('A', $this->suffix(1));
    }

    public function testSplitTenIsLetterJ(): void
    {
        $this->assertSame('J', $this->suffix(10));
    }

    public function testEverySplitOneThroughTenIsADistinctLetter(): void
    {
        $suffixes = array_map(fn ($n) => $this->suffix($n), range(1, 10));
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'], $suffixes);
        $this->assertCount(10, array_unique($suffixes), 'Every split 1-10 must get its own distinct suffix.');
    }

    public function testEverySplitOneThroughTwentySixIsADistinctLetter(): void
    {
        $suffixes = array_map(fn ($n) => $this->suffix($n), range(1, 26));
        $this->assertCount(26, array_unique($suffixes));
        $this->assertSame('Z', $this->suffix(26));
    }

    public function testSuffixIsNeverADigit(): void
    {
        foreach (range(1, 26) as $n) {
            $this->assertFalse(ctype_digit($this->suffix($n)), "Split #$n's suffix must never be a digit -- see splitSuffix()'s docblock.");
        }
    }
}
