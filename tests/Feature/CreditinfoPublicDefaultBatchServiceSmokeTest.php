<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\CreditinfoPublicDefaultBatch;
use App\Services\CreditinfoPublicDefaultBatchService;
use PHPUnit\Framework\TestCase;

/**
 * Pre-production-deploy smoke test for CreditinfoPublicDefaultBatchService,
 * app/Controllers/CreditinfoPublicDefaultBatchController.php, and the whole
 * batch-generation feature these support -- none of this had ever actually
 * run end-to-end before (creditinfo_public_default_batches/_batch_items
 * were confirmed empty in every environment checked this session), and it
 * was about to be deployed to production for the first time to fix a real
 * vendor-reported filename bug. Uses the one pre-existing seed borrower/loan
 * (id 100000, "Ndapewa Shikongo") rather than fabricating new data -- never
 * creates anything that could be mistaken for a real borrower's default.
 */
class CreditinfoPublicDefaultBatchServiceSmokeTest extends TestCase
{
    private const SEED_BORROWER_ID = 100000;
    private const SEED_LOAN_ID = 100000;
    private const SEED_BRANCH_ID = 1;

    private ?int $publicDefaultId = null;
    private ?int $batchId = null;
    private ?string $generatedFilePath = null;

    protected function tearDown(): void
    {
        $db = Database::connection();
        if ($this->batchId !== null) {
            $db->prepare('DELETE FROM creditinfo_public_default_batch_items WHERE batch_id = ?')->execute([$this->batchId]);
            $db->prepare('DELETE FROM creditinfo_public_default_batches WHERE id = ?')->execute([$this->batchId]);
        }
        if ($this->publicDefaultId !== null) {
            $db->prepare('DELETE FROM creditinfo_public_default_actions WHERE public_default_id = ?')->execute([$this->publicDefaultId]);
            $db->prepare('DELETE FROM creditinfo_public_defaults WHERE id = ?')->execute([$this->publicDefaultId]);
        }
        if ($this->generatedFilePath !== null && file_exists($this->generatedFilePath)) {
            unlink($this->generatedFilePath);
        }
    }

    public function testGenerateProducesACorrectlyFormattedFileFromARealListingReadyRecord(): void
    {
        $db = Database::connection();
        $ref = 'PD-PHPUNIT-' . bin2hex(random_bytes(4));
        $stmt = $db->prepare(
            "INSERT INTO creditinfo_public_defaults
             (listing_reference, borrower_id, loan_id, branch_id, default_type, outstanding_amount, original_amount, default_date, days_in_arrears_at_listing, listing_reason, default_status_category, status, listing_requested_by, listing_requested_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'Loan Default', ?, ?, ?, ?, ?, 'Delinquent', 'Awaiting Manual Submission', 1, NOW(), NOW(), NOW())"
        );
        $stmt->execute([$ref, self::SEED_BORROWER_ID, self::SEED_LOAN_ID, self::SEED_BRANCH_ID, 1500.00, 2000.00, date('Y-m-d', strtotime('-95 days')), 95, 'PHPUnit smoke test -- 3+ missed installments.']);
        $this->publicDefaultId = (int) $db->lastInsertId();

        $service = new CreditinfoPublicDefaultBatchService();

        $eligible = $service->eligible('listing');
        $this->assertNotEmpty(array_filter($eligible, fn ($r) => (int) $r['id'] === $this->publicDefaultId), 'The new record must appear in the eligible queue before generate() is called.');

        $this->batchId = $service->generate('listing', [$this->publicDefaultId], 'test', 1, null);
        $this->assertGreaterThan(0, $this->batchId);

        $batch = (new CreditinfoPublicDefaultBatch())->find($this->batchId);
        $this->assertNotNull($batch);
        // file_path is stored relative to STORAGE_PATH by design (see
        // CreditinfoPublicDefaultBatchService::generate()) -- resolve it the
        // same way the app's own download action does.
        $this->generatedFilePath = STORAGE_PATH . '/' . $batch['file_path'];

        // Filename: must start with the real Supplier Reference Number,
        // never the literal placeholder "SRN" -- this is the exact bug
        // Creditinfo reported.
        $this->assertStringStartsNotWith('SRN_', $batch['filename'], 'Filename must never start with the literal placeholder "SRN".');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+_T_\d{8}_\d{6}\.txt$/', $batch['filename'], 'Filename must match {SupplierReferenceNumber}_{T|L}_CCYYMMDD_HHMMSS.txt, with T for a test-environment batch.');

        $this->assertFileExists($this->generatedFilePath);
        $content = file_get_contents($this->generatedFilePath);
        $fields = explode('|', trim($content));

        $this->assertCount(36, $fields, 'Every line must have exactly the 36 pipe-delimited fields in Creditinfo\'s legend.');
        $this->assertSame($fields[0], explode('_', $batch['filename'])[0], 'Field 1 (SUPPLIER REFERENCE NUMBER) must match the filename prefix.');
        $this->assertNotEmpty($fields[12], 'Field 13 (DEFAULT DATE) must not be blank -- this is the second bug Creditinfo reported.');
        $this->assertMatchesRegularExpression('/^\d{8}$/', $fields[12], 'DEFAULT DATE must be CCYYMMDD per the legend, not a blank or a differently-formatted date.');
    }
}
