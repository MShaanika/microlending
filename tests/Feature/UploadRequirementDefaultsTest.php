<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\UploadRequirement;
use PHPUnit\Framework\TestCase;

/** An empty requirements table (as on production) must still give the Add Borrower form an ID copy and a payslip box. */
class UploadRequirementDefaultsTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function types(): array
    {
        return array_column((new UploadRequirement())->forBorrowers(), 'document_type');
    }

    public function testAnEmptyTableGetsTheIdCopyAndThePayslip(): void
    {
        $this->db->exec('DELETE FROM application_upload_requirements');

        $types = $this->types();

        $this->assertEqualsCanonicalizing(['ID Copy', 'Payslip'], $types);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM application_upload_requirements WHERE is_active = 1 AND applies_to = "All"')->fetchColumn());
    }

    public function testAskingAgainNeverAddsThemTwice(): void
    {
        $this->db->exec('DELETE FROM application_upload_requirements');
        $model = new UploadRequirement();

        $model->forBorrowers();
        $model->forBorrowers();
        (new UploadRequirement())->forBorrowers();

        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM application_upload_requirements')->fetchColumn());
    }

    public function testExistingRequirementsAreLeftAlone(): void
    {
        $this->db->exec('DELETE FROM application_upload_requirements');
        $this->db->exec("INSERT INTO application_upload_requirements (requirement_name, document_type, is_required, applies_to, is_active) VALUES ('Our ID rule', 'ID Copy', 0, 'All', 1), ('Employer letter', 'Employment Confirmation', 0, 'All', 1)");

        $rows = (new UploadRequirement())->forBorrowers();

        $this->assertEqualsCanonicalizing(['ID Copy', 'Employment Confirmation', 'Payslip'], array_column($rows, 'document_type'));
        $this->assertSame('Our ID rule', array_values(array_filter($rows, fn ($r) => $r['document_type'] === 'ID Copy'))[0]['requirement_name']);
    }

    public function testATypeAnAdministratorSwitchedOffIsNotBroughtBack(): void
    {
        $this->db->exec('DELETE FROM application_upload_requirements');
        $this->db->exec("INSERT INTO application_upload_requirements (requirement_name, document_type, is_required, applies_to, is_active) VALUES ('Payslip', 'Payslip', 0, 'All', 0)");

        $types = $this->types();

        $this->assertSame(['ID Copy'], $types);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM application_upload_requirements WHERE document_type = 'Payslip'")->fetchColumn());
    }
}
