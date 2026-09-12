<?php

namespace Tests\Unit;

use App\Services\CplRecordBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CplRecordBuilder's byte positions/lengths/alignment against
 * CPLv1-1.pdf's own Monthly Layout table (pp.17-18) and Header/Trailer
 * Composition tables (pp.15,18) -- every position asserted here was read
 * directly from the spec, not guessed. No DB involved -- pure formatting.
 */
class CplRecordBuilderTest extends TestCase
{
    private CplRecordBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CplRecordBuilder();
    }

    public function testRecordIsExactly700Characters(): void
    {
        $line = $this->builder->record(['surname' => 'Nangolo']);
        $this->assertSame(700, strlen($line));
    }

    public function testFieldPositionsMatchTheSpecExactly(): void
    {
        $line = $this->builder->record([
            'data' => 'D',
            'non_na_id' => '',
            'na_id' => '7408285107012',
            'gender' => 'M',
            'date_of_birth' => '1974-08-28',
            'branch_code' => 'HO',
            'account_no' => 'LN-260910-431B50',
            'surname' => 'Nangolo',
            'title' => 'MR',
            'forename1' => 'Petrus',
            'status_code' => 'W',
            'repayment_frequency' => 3,
            'terms' => 6,
        ]);

        // Position 1: DATA indicator (1-1, A1).
        $this->assertSame('D', substr($line, 0, 1));
        // Position 15-30: NA ID, numeric but LEFT-aligned/space-filled (the spec's stated exception).
        $this->assertSame('7408285107012   ', substr($line, 14, 16));
        // Position 31: GENDER (A1).
        $this->assertSame('M', substr($line, 30, 1));
        // Position 32-39: DATE OF BIRTH, CCYYMMDD.
        $this->assertSame('19740828', substr($line, 31, 8));
        // Position 40-47: BRANCH CODE, A8, right-aligned/space-filled.
        $this->assertSame('      HO', substr($line, 39, 8));
        // Position 48-72: ACCOUNT NO, A25, right-aligned/space-filled.
        $this->assertSame(str_pad('LN-260910-431B50', 25, ' ', STR_PAD_LEFT), substr($line, 47, 25));
        // Position 77-101: SURNAME, A25, left-aligned/space-filled.
        $this->assertSame(str_pad('Nangolo', 25, ' ', STR_PAD_RIGHT), substr($line, 76, 25));
        // Position 433-434: STATUS CODE, A2, left-aligned.
        $this->assertSame('W ', substr($line, 432, 2));
        // Position 435-436: REPAYMENT FREQUENCY, N2, zero-filled.
        $this->assertSame('03', substr($line, 434, 2));
        // Position 437-440: TERMS, N4, zero-filled.
        $this->assertSame('0006', substr($line, 436, 4));
    }

    public function testMissingFieldsAreBlankOrZeroFilledPerAlignment(): void
    {
        $line = $this->builder->record([]);
        // An untouched numeric field (e.g. OPENING BALANCE, 394-402, N9) is zero-filled.
        $this->assertSame('000000000', substr($line, 393, 9));
        // An untouched alpha field (e.g. SURNAME, 77-101, A25) is space-filled.
        $this->assertSame(str_repeat(' ', 25), substr($line, 76, 25));
        // An untouched date field (e.g. DATE OF BIRTH) is all-zero, not zero-length.
        $this->assertSame('00000000', substr($line, 31, 8));
    }

    public function testValueLongerThanFieldWidthIsTruncatedNotShiftedOutOfPosition(): void
    {
        $line = $this->builder->record([
            'surname' => str_repeat('X', 40), // field is only A25
            'title' => 'MR', // the field immediately after surname
        ]);
        $this->assertSame(str_repeat('X', 25), substr($line, 76, 25));
        // TITLE (102-106) must still land in its own slot, unaffected by the truncated surname.
        $this->assertSame('MR   ', substr($line, 101, 5));
    }

    public function testDateHelperFormatsAsCcyymmddOrZeroFillsWhenAbsent(): void
    {
        $this->assertSame('20260930', $this->builder->date('2026-09-30'));
        $this->assertSame('00000000', $this->builder->date(null));
        $this->assertSame('00000000', $this->builder->date(''));
    }

    public function testHeaderCompositionMatchesTheSpecExactly(): void
    {
        $header = $this->builder->header('AA0101', '2026-09-30', 'Solid Desert Cash Loan');

        $this->assertSame(700, strlen($header));
        $this->assertSame('H', substr($header, 0, 1));
        // Position 2-11: SUPPLIER REFERENCE NUMBER, A10, right-aligned (the spec's explicit exception).
        $this->assertSame('    AA0101', substr($header, 1, 10));
        // Position 12-19: MONTH END DATE.
        $this->assertSame('20260930', substr($header, 11, 8));
        // Position 20-21: VERSION NUMBER, always "06" for Layout v1 (702).
        $this->assertSame('06', substr($header, 19, 2));
        // Position 30-89: TRADING NAME/BRAND NAME, A60, left-aligned.
        $this->assertSame(str_pad('Solid Desert Cash Loan', 60, ' ', STR_PAD_RIGHT), substr($header, 29, 60));
    }

    public function testTrailerReflectsRecordCountIncludingHeaderAndTrailerThemselves(): void
    {
        $trailer = $this->builder->trailer(123);
        $this->assertSame(700, strlen($trailer));
        $this->assertSame('T', substr($trailer, 0, 1));
        // Position 2-10: NUMBER OF RECORDS, N9, zero-filled.
        $this->assertSame('000000123', substr($trailer, 1, 9));
    }

    public function testDailyRecordIs718CharactersWithNoHeaderOrTrailerConcept(): void
    {
        $line = $this->builder->dailyRecord(['data' => 'R', 'surname' => 'Nangolo'], 'AA0101', '2026-09-15');

        // CPLv1-1.pdf pp.19-20: Daily = Monthly's 700 chars + 10 (Supplier
        // Reference Number) + 8 (Transaction Date) = 718 total.
        $this->assertSame(718, strlen($line));
        $this->assertSame('R', substr($line, 0, 1));
        // The first 700 characters are built by the exact same field map as a Monthly record.
        $this->assertSame(str_pad('Nangolo', 25, ' ', STR_PAD_RIGHT), substr($line, 76, 25));
        // Position 701-710: SUPPLIER REFERENCE NUMBER, A10, right-aligned.
        $this->assertSame('    AA0101', substr($line, 700, 10));
        // Position 711-718: TRANSACTION DATE, N8, CCYYMMDD.
        $this->assertSame('20260915', substr($line, 710, 8));
    }
}
