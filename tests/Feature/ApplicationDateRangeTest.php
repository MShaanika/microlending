<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\LoanApplication;
use PHPUnit\Framework\TestCase;

/** The applications list can be limited to the days they were received; both ends are inclusive, and either may be left open. */
class ApplicationDateRangeTest extends TestCase
{
    private \PDO $db;
    private string $prefix;
    private array $ids = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->prefix = 'PHPUNIT-DR-' . uniqid() . '-';
        foreach (['early' => '2026-09-01 08:00:00', 'lateInDay' => '2026-09-15 23:59:59', 'later' => '2026-10-01 00:00:00'] as $key => $createdAt) {
            $this->db->prepare(
                "INSERT INTO loan_applications (application_no, application_source, application_type, requested_amount, requested_term_months, applicant_first_name, applicant_last_name, status, created_at)
                 VALUES (?, 'Online', 'New Loan', 1000, 1, 'Range', ?, 'Submitted', ?)"
            )->execute([$this->prefix . $key, $key, $createdAt]);
            $this->ids[$key] = (int) $this->db->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM loan_applications WHERE application_no LIKE ?')->execute([$this->prefix . '%']);
    }

    /** @return string[] the keys of the three test applications the filter returns */
    private function found(?string $from, ?string $to): array
    {
        $rows = (new LoanApplication())->paginated('', '', 5000, null, $from, $to);
        $byId = array_flip($this->ids);
        $out = [];
        foreach ($rows as $row) {
            if (isset($byId[(int) $row['id']])) {
                $out[] = $byId[(int) $row['id']];
            }
        }
        sort($out);
        return $out;
    }

    public function testWithoutDatesEverythingIsReturned(): void
    {
        $this->assertSame(['early', 'lateInDay', 'later'], $this->found(null, null));
    }

    public function testBothEndsAreInclusiveWholeDays(): void
    {
        $this->assertSame(['lateInDay'], $this->found('2026-09-15', '2026-09-15'), 'a single day includes its last second');
        $this->assertSame(['early', 'lateInDay'], $this->found('2026-09-01', '2026-09-15'));
        $this->assertSame(['later'], $this->found('2026-10-01', '2026-10-01'), 'midnight belongs to its own day');
    }

    public function testOneOpenEndedSide(): void
    {
        $this->assertSame(['lateInDay', 'later'], $this->found('2026-09-02', null));
        $this->assertSame(['early'], $this->found(null, '2026-09-14'));
    }

    public function testAPeriodWithNothingInItReturnsNothing(): void
    {
        $this->assertSame([], $this->found('2026-09-16', '2026-09-30'));
    }
}
