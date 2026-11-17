<?php

namespace Tests\Unit;

use App\Services\CreditinfoPublicDefaultFileService;
use App\Services\PublicDefaultListingSheet;
use PHPUnit\Framework\TestCase;

class PublicDefaultListingSheetTest extends TestCase
{
    private const TODAY = '2026-11-17';

    private function row(array $o = []): array
    {
        return $o + [
            'first_name' => 'Maria', 'second_name' => 'Ndapewa', 'surname' => 'Shikongo', 'id_type' => 'National ID', 'id_number' => '85032700123',
            'dob' => '1985-03-27', 'account_no' => 'LN-1001', 'branch_code' => '0001', 'amount' => '4500', 'default_date' => '2026-08-01', 'status' => 'Handed Over',
            'employer' => 'Acme', 'occupation' => 'Clerk', 'cell' => '+264 81 123 4567', 'home_tel' => '', 'work_tel' => '',
            'postal1' => 'PO Box 2105', 'postal2' => 'Otjiwarongo', 'postal_code' => '', 'res1' => 'Hakahana', 'res2' => '', 'res_code' => '', 'work1' => '', 'work_code' => '',
        ];
    }

    private function parse(array $rows, string $branch = ''): array
    {
        return (new PublicDefaultListingSheet())->parse($rows, self::TODAY, $branch);
    }

    public function testAGoodRowBecomesTheExactThirtySixColumnLineWithActionA(): void
    {
        $sheet = new PublicDefaultListingSheet();
        $r = $sheet->parse([$this->row()], self::TODAY);
        $this->assertSame([], $r['rejected']);
        $line = $sheet->build($r['records'], 'SRN', new \DateTimeImmutable('2026-11-17'))[0];
        $f = explode('|', $line);

        $this->assertCount(36, $f);
        $this->assertSame(['SRN', '85032700123', 'NAM', '2', '19850327', '', 'Maria', 'Ndapewa', 'Shikongo', '0001', 'LN-1001', '4500', '20260801', '1'], array_slice($f, 0, 14));
        $this->assertSame('264811234567', $f[16], 'cellphone keeps digits only');
        $this->assertSame('PO Box 2105', $f[19]);
        $this->assertSame('Otjiwarongo', $f[20]);
        $this->assertSame('Hakahana', $f[24]);
        $this->assertSame(['20261117', 'A'], array_slice($f, 34, 2));
    }

    public function testTheFlatRendererMatchesTheDatabaseRendererForTheSamePerson(): void
    {
        $svc = new CreditinfoPublicDefaultFileService();
        $db = $svc->renderLine(
            ['default_status_category' => 'Collection', 'outstanding_amount' => 4500, 'default_date' => '2026-08-01'],
            ['id_number' => '85032700123', 'date_of_birth' => '1985-03-27', 'first_name' => 'Maria', 'middle_name' => 'Ndapewa', 'last_name' => 'Shikongo', 'phone' => '+264 81 123 4567', 'postal_address' => 'PO Box 2105', 'physical_address' => 'Hakahana'],
            ['loan_no' => 'LN-1001'], ['branch_code' => '0001'], ['employer_name' => 'Acme', 'job_title' => 'Clerk'], 'SRN', 'A', new \DateTimeImmutable('2026-11-17')
        );
        $flat = $svc->renderRecord([
            'id_type' => 2, 'id_number' => '85032700123', 'dob' => '1985-03-27', 'first_name' => 'Maria', 'second_name' => 'Ndapewa', 'surname' => 'Shikongo', 'branch_code' => '0001',
            'account_no' => 'LN-1001', 'amount' => 4500, 'default_date' => '2026-08-01', 'status_code' => 2, 'employer' => 'Acme', 'occupation' => 'Clerk', 'cell' => '+264 81 123 4567',
            'postal1' => 'PO Box 2105', 'res1' => 'Hakahana',
        ], 'SRN', 'A', new \DateTimeImmutable('2026-11-17'));

        $this->assertSame($db, $flat);
    }

    public function testEveryStatusMapsToTheVendorCode(): void
    {
        $expect = ['Handed Over' => 1, 'Collection' => 2, 'Write Off' => 3, 'Paid' => 4, 'Delinquent' => 5, 'Slow Payer' => 6, 'Absconded' => 7, 'Not Contactable' => 8];
        foreach ($expect as $label => $code) {
            $r = $this->parse([$this->row(['status' => $label])]);
            $this->assertSame($code, $r['records'][0]['status_code'], $label);
        }
        $this->assertCount(1, $this->parse([$this->row(['status' => 'handedover'])])['records'], 'case and spacing do not matter');
    }

    public function testRowsThatCannotBeUsedAreRefusedWithAReason(): void
    {
        $bad = [
            ['id_number' => '8503270012'],                         // 10 digits
            ['id_number' => '8503270012A'],                        // letters in a national id
            ['id_type' => 'Driver'],                               // unknown type
            ['dob' => '27/03/1985'],                               // not ISO
            ['dob' => '2026-09-01'],                               // after default date
            ['dob' => '2012-03-27', 'id_number' => '12032700123'], // 14 years old
            ['default_date' => '2026-12-01'],                      // future
            ['amount' => '4,5'], ['amount' => '0'], ['amount' => 'abc'], ['amount' => '900000'],
            ['status' => 'Gone'],
            ['cell' => '12'],
            ['surname' => ''],
            ['account_no' => 'LN 1001'],
        ];
        foreach ($bad as $i => $o) {
            $r = $this->parse([$this->row($o)]);
            $this->assertCount(0, $r['records'], 'case ' . $i . ' ' . json_encode($o));
            $this->assertCount(1, $r['rejected'], 'case ' . $i);
            $this->assertNotSame('', $r['rejected'][0]['why']);
        }
    }

    public function testPassportsAndTheBranchDefault(): void
    {
        $r = $this->parse([$this->row(['id_type' => 'Passport', 'id_number' => 'p 1234567', 'branch_code' => '', 'dob' => '1980-01-01'])], '0007');
        $this->assertSame([], $r['rejected']);
        $this->assertSame(1, $r['records'][0]['id_type']);
        $this->assertSame('P1234567', $r['records'][0]['id_number']);
        $this->assertSame('0007', $r['records'][0]['branch_code']);

        $none = $this->parse([$this->row(['branch_code' => ''])]);
        $this->assertCount(0, $none['records'], 'no branch code anywhere');
        $this->assertStringContainsString('branch', $none['rejected'][0]['why']);
    }

    public function testDuplicatesAreNeverGuessedAndBlankRowsAreIgnored(): void
    {
        $r = $this->parse([$this->row(), $this->row(['first_name' => 'Other']), array_fill_keys(array_keys(PublicDefaultListingSheet::COLUMNS), ''), $this->row(['account_no' => 'LN-2', 'id_number' => '85032700999'])]);
        $this->assertCount(1, $r['records']);
        $this->assertCount(2, $r['rejected'], 'both rows of the repeated account are refused');
        $this->assertSame(3, $r['rejected'][1]['row']);
    }

    public function testMismatchedDobAndIdAndSamePersonTwiceAreOnlyWarnings(): void
    {
        $r = $this->parse([$this->row(['dob' => '1985-03-28']), $this->row(['account_no' => 'LN-2', 'dob' => '1985-03-27'])]);
        $this->assertCount(2, $r['records']);
        $notes = implode(' | ', array_column($r['warnings'], 'note'));
        $this->assertStringContainsString('first six digits', $notes);
        $this->assertStringContainsString('more than one account', $notes);
    }

    public function testTheTemplateWritesAndReadsBackAndFlowsThroughTheChecker(): void
    {
        $path = sys_get_temp_dir() . '/pd_listing_' . uniqid() . '.xlsx';
        $sheet = new PublicDefaultListingSheet();
        $sheet->write($path, self::TODAY);
        try {
            $rows = $sheet->readFile($path);
            $this->assertCount(PublicDefaultListingSheet::ROWS, $rows);
            $this->assertSame(array_keys(PublicDefaultListingSheet::COLUMNS), array_keys($rows[0]));
            $this->assertSame([], $sheet->parse($rows, self::TODAY)['records'], 'a blank template has nothing in it');

            // fill row 2 the way staff would, through the real file
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $ws = $book->getSheetByName(PublicDefaultListingSheet::DATA_SHEET);
            foreach ($this->row() as $key => $value) {
                $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($key, array_keys(PublicDefaultListingSheet::COLUMNS), true) + 1);
                $ws->setCellValueExplicit($c . '2', $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

            $r = $sheet->parse($sheet->readFile($path), self::TODAY);
            $this->assertSame([], $r['rejected']);
            $this->assertSame('LN-1001', $r['records'][0]['account_no']);
        } finally {
            @unlink($path);
        }
    }

    public function testAmountsThatRoundToNothingAreRefusedAndBranchZeroIsKept(): void
    {
        foreach (['0.4', '0.49'] as $amount) {
            $r = $this->parse([$this->row(['amount' => $amount])]);
            $this->assertCount(0, $r['records'], $amount);
            $this->assertStringContainsString('less than N$ 1', $r['rejected'][0]['why']);
        }
        $this->assertCount(1, $this->parse([$this->row(['amount' => 'N$ 1,250.50'])])['records']);

        $r = $this->parse([$this->row(['branch_code' => '0'])], '0001');
        $this->assertSame('0', $r['records'][0]['branch_code'], 'a typed 0 is not replaced by the default');
    }

    public function testRejectionReasonsNameTheRuleThatFired(): void
    {
        $cases = [
            [['id_number' => '123'], '11 digits'], [['id_type' => 'Driver'], 'National ID or Passport'], [['dob' => '27/03/1985'], 'date of birth'],
            [['dob' => '2012-03-27', 'id_number' => '12032700123'], 'check the date of birth'], [['default_date' => '2026-12-01'], 'in the future'],
            [['amount' => '4,5'], 'digits only'], [['status' => 'Gone'], 'Default status'], [['cell' => '12'], 'cellphone'], [['surname' => ''], 'missing: Surname'],
            [['id_type' => 'Passport', 'id_number' => 'AB12'], 'passport'], [['id_type' => 'Passport', 'id_number' => 'ABCDEFGHIJKLMNOP'], 'passport'],
        ];
        foreach ($cases as [$o, $needle]) {
            $r = $this->parse([$this->row($o)]);
            $this->assertCount(0, $r['records'], json_encode($o));
            $this->assertStringContainsString($needle, $r['rejected'][0]['why'], json_encode($o));
        }
        // boundaries that are allowed: default date today, passport of exactly 5 and 15, age exactly 18 at the default date
        $this->assertCount(1, $this->parse([$this->row(['default_date' => self::TODAY])])['records']);
        $this->assertCount(1, $this->parse([$this->row(['id_type' => 'Passport', 'id_number' => 'AB123'])])['records']);
        $this->assertCount(1, $this->parse([$this->row(['id_type' => 'Passport', 'id_number' => 'ABCDEFGHIJ12345'])])['records']);
        $this->assertCount(1, $this->parse([$this->row(['dob' => '2008-08-01', 'id_number' => '08080100123', 'default_date' => '2026-08-01'])])['records'], 'age18');
        $this->assertCount(0, $this->parse([$this->row(['dob' => '2008-08-02', 'id_number' => '08080200123', 'default_date' => '2026-08-01'])])['records'], 'age19');
    }

    public function testControlCharactersNeverReachAFieldAndTheDropDownsShowInExcel(): void
    {
        $line = (new PublicDefaultListingSheet())->build($this->parse([$this->row(['first_name' => "A|B\tC\u{2028}D", 'employer' => "Line1\nLine2"])])['records'], 'SRN', new \DateTimeImmutable(self::TODAY))[0];
        $this->assertCount(36, explode('|', $line));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x{2028}]/u', $line);

        $path = sys_get_temp_dir() . '/pd_listing_' . uniqid() . '.xlsx';
        (new PublicDefaultListingSheet())->write($path, self::TODAY);
        try {
            $zip = new \ZipArchive();
            $zip->open($path);
            $xml = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
            $zip->close();
            preg_match_all('/<dataValidation[^>]*type="list"[^>]*>/', $xml, $m);
            $this->assertNotEmpty($m[0]);
            foreach ($m[0] as $tag) {
                $this->assertStringNotContainsString('showDropDown="1"', $tag, 'showDropDown="1" would HIDE the arrow in Excel');
            }
        } finally {
            @unlink($path);
        }
    }

    public function testNumberCellsInTextColumnsAreFlagged(): void
    {
        $path = sys_get_temp_dir() . '/pd_listing_' . uniqid() . '.xlsx';
        $sheet = new PublicDefaultListingSheet();
        $sheet->write($path, self::TODAY);
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $ws = $book->getSheetByName(PublicDefaultListingSheet::DATA_SHEET);
            foreach ($this->row() as $key => $value) {
                $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($key, array_keys(PublicDefaultListingSheet::COLUMNS), true) + 1);
                $ws->setCellValueExplicit($c . '2', $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $ws->setCellValueExplicit('N2', 811234567, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

            $r = $sheet->parse($sheet->readFile($path), self::TODAY);
            $this->assertCount(1, $r['records']);
            $this->assertStringContainsString('leading zeros', implode(' ', array_column($r['warnings'], 'note')));
        } finally {
            @unlink($path);
        }
    }

    /** The real command: T by default, CRLF, never replaces files, and a LIVE file is refused while rows are rejected. */
    public function testTheCommandIsSafeByDefault(): void
    {
        $root = dirname(__DIR__, 2);
        $sheet = new PublicDefaultListingSheet();
        $path = sys_get_temp_dir() . '/pd_cli_' . uniqid() . '.xlsx';
        $sheet->write($path, self::TODAY);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $ws = $book->getSheetByName(PublicDefaultListingSheet::DATA_SHEET);
        foreach ([2 => $this->row(), 3 => $this->row(['account_no' => 'LN-9', 'id_number' => '123'])] as $line => $row) {
            foreach ($row as $key => $value) {
                $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($key, array_keys(PublicDefaultListingSheet::COLUMNS), true) + 1);
                $ws->setCellValueExplicit($c . $line, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $run = function (string $args) use ($root): array {
            $p = proc_open('"' . PHP_BINARY . '" bin/public_defaults_listing.php ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($p), $out];
        };
        $made = [];
        try {
            [$code, $out] = $run('--build=' . escapeshellarg($path) . ' --branch=0001 --date=' . self::TODAY);
            $this->assertSame(3, $code, $out);
            $this->assertStringContainsString('PARTIAL', $out);
            $this->assertStringContainsString('TEST file (T)', $out);
            $this->assertSame(1, preg_match('#written to (\S+SRN_T_\d{8}_\d{6}\.txt)#', $out, $m), $out);
            $made[] = $m[1];
            $bytes = (string) file_get_contents($m[1]);
            $this->assertStringEndsWith("|A\r\n", $bytes);
            $this->assertSame(1, substr_count($bytes, "\n"));

            [$code, $out] = $run('--build=' . escapeshellarg($path) . ' --branch=0001 --env=live');
            $this->assertSame(3, $code, $out);
            $this->assertStringContainsString('No LIVE file written', $out);
            $this->assertStringNotContainsString('SRN_L_', $out);

            [$code, $out] = $run('--build=' . escapeshellarg($path) . ' --branch=0001 --env=nonsense');
            $this->assertSame(1, $code);
        } finally {
            foreach ($made as $f) {
                @unlink($f);
            }
            @unlink($path);
        }
    }

    public function testAnExcelDateCellIsReadAsAnIsoDate(): void
    {
        $path = sys_get_temp_dir() . '/pd_listing_' . uniqid() . '.xlsx';
        $sheet = new PublicDefaultListingSheet();
        $sheet->write($path, self::TODAY);
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $ws = $book->getSheetByName(PublicDefaultListingSheet::DATA_SHEET);
            $ws->setCellValueExplicit('F2', (float) \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('1985-03-27')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            $this->assertSame('1985-03-27', $sheet->readFile($path)[0]['dob']);
        } finally {
            @unlink($path);
        }
    }
}
