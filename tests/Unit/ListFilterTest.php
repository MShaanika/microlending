<?php

namespace Tests\Unit;

use App\Support\CsvExport;
use App\Support\ListFilter;
use PHPUnit\Framework\TestCase;

/** The filters every list screen shares: cleaned input, bound SQL, safe CSV cells. */
class ListFilterTest extends TestCase
{
    public function testRequestIsCleanedAndNothingBadSurvives(): void
    {
        $f = ListFilter::fromRequest(['q' => '  smith ', 'status' => 'Active', 'from' => '2026-10-01', 'to' => 'not-a-date', 'min' => '1,000.50', 'max' => 'abc', 'export' => 'csv']);
        $this->assertSame('smith', $f['q']);
        $this->assertSame('Active', $f['status']);
        $this->assertSame('2026-10-01', $f['from']);
        $this->assertNull($f['to']);
        $this->assertSame(1000.5, $f['min']);
        $this->assertNull($f['max']);
        $this->assertTrue($f['export']);

        $empty = ListFilter::fromRequest([]);
        $this->assertSame(['q' => '', 'status' => '', 'from' => null, 'to' => null, 'min' => null, 'max' => null, 'export' => false], $empty);
        $this->assertFalse(ListFilter::isRange($empty));
        $this->assertTrue(ListFilter::isRange(ListFilter::fromRequest(['from' => '2026-01-01'])));

        // arrays and impossible dates are ignored rather than trusted
        $odd = ListFilter::fromRequest(['q' => ['x'], 'from' => '2026-02-30', 'min' => '-5']);
        $this->assertSame('', $odd['q']);
        $this->assertNull($odd['from']);
        $this->assertNull($odd['min']);
    }

    public function testBackwardsRangesAreSwapped(): void
    {
        $this->assertSame(['2026-01-01', '2026-02-01'], ListFilter::dateRange('2026-02-01', '2026-01-01'));
        $this->assertSame([10.0, 500.0], ListFilter::amountRange('500', '10'));
        $this->assertSame([null, '2026-03-01'], ListFilter::dateRange('', '2026-03-01'));
    }

    public function testDateSqlCoversWholeDaysAndBindsEveryValue(): void
    {
        $params = [];
        $sql = ListFilter::dateSql('a.created_at', '2026-09-15', '2026-09-15', $params);
        $this->assertSame(' AND a.created_at >= ? AND a.created_at < ?', $sql);
        $this->assertSame(['2026-09-15 00:00:00', '2026-09-16 00:00:00'], $params);

        $params = [];
        $this->assertSame('', ListFilter::dateSql('a.created_at', null, null, $params));
        $this->assertSame([], $params);

        $params = [];
        ListFilter::dateSql('d', null, '2026-12-31', $params);
        $this->assertSame(['2027-01-01 00:00:00'], $params, 'the last day of a year rolls over correctly');
    }

    public function testAmountAndSearchSql(): void
    {
        $params = [];
        $this->assertSame(' AND p.amount >= ? AND p.amount <= ?', ListFilter::amountSql('p.amount', 10.0, 99.5, $params));
        $this->assertSame([10.0, 99.5], $params);

        $params = [];
        $sql = ListFilter::searchSql(['a.name', 'a.phone'], "50%_off\\", $params);
        $this->assertSame(' AND (a.name LIKE ? OR a.phone LIKE ?)', $sql);
        $this->assertSame(['%50\\%\\_off\\\\%', '%50\\%\\_off\\\\%'], $params, 'wildcards typed by the user are searched for, not interpreted');

        $params = [];
        $this->assertSame('', ListFilter::searchSql(['a.name'], '', $params));
        $this->assertSame('', ListFilter::searchSql([], 'x', $params));
    }

    public function testExportQueryKeepsTheFilterAndDropsPaging(): void
    {
        $q = ListFilter::exportQuery(['q' => 'abc', 'status' => '', 'page' => '3', 'from' => '2026-01-01', 'export' => 'x']);
        parse_str($q, $out);
        $this->assertSame(['q' => 'abc', 'from' => '2026-01-01', 'export' => 'csv'], $out);
    }

    public function testCsvCellsCannotRunAsFormulas(): void
    {
        $this->assertSame("'=SUM(A1:A9)", CsvExport::cell('=SUM(A1:A9)'));
        $this->assertSame("'+264811234567x", CsvExport::cell('+264811234567x'));
        $this->assertSame("'@cmd", CsvExport::cell('@cmd'));
        $this->assertSame('-1500.00', CsvExport::cell('-1500.00'), 'a negative number stays a number');
        $this->assertSame('+264811234567', CsvExport::cell('+264811234567'), 'a plain phone number is numeric and left alone');
        $this->assertSame('Normal text', CsvExport::cell('Normal text'));
        $this->assertSame('', CsvExport::cell(null));
    }
}
