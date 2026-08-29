<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\DataQualityService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class DataQualityServiceTest extends TestCase
{
    private array $createdBorrowerIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdBorrowerIds as $id) {
            $db->prepare('DELETE FROM data_quality_issues WHERE resource_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM borrowers WHERE id = ?')->execute([$id]);
        }
        $this->createdBorrowerIds = [];
    }

    private function insertBorrower(?string $idNumber, ?string $phone = null): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO borrowers (branch_id, borrower_no, first_name, last_name, status, id_number, phone)
             VALUES (1, ?, 'PHPUnit', 'TestBorrower', 'Approved', ?, ?)"
        )->execute(['PHPUNIT-' . uniqid(), $idNumber, $phone]);
        $id = (int) $db->lastInsertId();
        $this->createdBorrowerIds[] = $id;
        return $id;
    }

    private function issueFor(string $resourceType, int $resourceId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM data_quality_issues WHERE resource_type = ? AND resource_id = ?');
        $stmt->execute([$resourceType, $resourceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function testRuleDetectionFindsAMissingRequiredField(): void
    {
        $borrowerId = $this->insertBorrower(null);

        DataQualityService::scan();

        $issue = $this->issueFor('borrower', $borrowerId);
        $this->assertNotNull($issue);
        $this->assertSame('OPEN', $issue['status']);
    }

    public function testIssueAutoResolvesOnceTheUnderlyingConditionIsFixed(): void
    {
        $borrowerId = $this->insertBorrower(null);
        DataQualityService::scan();
        $this->assertSame('OPEN', $this->issueFor('borrower', $borrowerId)['status']);

        Database::connection()->prepare('UPDATE borrowers SET id_number = ? WHERE id = ?')->execute(['PHPUNIT-ID-12345', $borrowerId]);
        DataQualityService::scan();

        $this->assertSame('RESOLVED', $this->issueFor('borrower', $borrowerId)['status']);
    }

    public function testScanNeverModifiesTheRecordItChecks(): void
    {
        // Part 33/99: the engine only ever reports -- correction happens
        // through the record's own normal, authorized workflow.
        $borrowerId = $this->insertBorrower(null);

        DataQualityService::scan();

        $stmt = Database::connection()->prepare('SELECT id_number FROM borrowers WHERE id = ?');
        $stmt->execute([$borrowerId]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testDuplicateDetectionFindsTwoBorrowersSharingAPhoneNumber(): void
    {
        $phone = '+264-81-PHPUNIT-' . uniqid();
        $first = $this->insertBorrower('ID-A', $phone);
        $this->insertBorrower('ID-B', $phone);

        DataQualityService::scan();

        // The anchor is the lower of the two IDs (see
        // DataQualityService::duplicateBorrowerPhones()).
        $issue = $this->issueFor('borrower_phone_group', $first);
        $this->assertNotNull($issue);
        $this->assertStringContainsString('2 borrowers share phone number', $issue['description']);
    }

    public function testDistinctPhoneNumbersAreNotFlagged(): void
    {
        $this->insertBorrower('ID-C', '+264-81-' . uniqid());
        $this->insertBorrower('ID-D', '+264-81-' . uniqid());

        $summary = DataQualityService::scan();

        $this->assertSame(0, $summary['duplicate_borrower_phone']['failing']);
    }
}
