<?php

namespace App\Services;

use App\Core\Idempotency;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CreditinfoPublicDefault;
use App\Models\CreditinfoPublicDefaultAction;
use App\Models\CreditinfoPublicDefaultBatch;
use App\Models\CreditinfoPublicDefaultBatchItem;
use App\Models\CreditinfoPublicDefaultSetting;
use App\Models\CreditinfoPublicDefaultSubmission;
use App\Models\Loan;

/**
 * Orchestrates generating, storing and eventually consuming a Public
 * Defaults submission FILE (a batch of many creditinfo_public_defaults
 * records rendered into one .txt at once). CreditinfoPublicDefaultFileService
 * does the byte-level rendering; this class does the DB bookkeeping and the
 * maker-checker workflow around it (via the existing generic Approval
 * Engine, same as CreditinfoPublicDefaultController's listing/removal
 * approval). Never touches SFTP or any network endpoint -- a batch's only
 * output is a file on local storage, handed to a human via downloadBatch()
 * in the controller.
 */
class CreditinfoPublicDefaultBatchService
{
    private const READY_STATUSES = [
        'listing' => 'Awaiting Manual Submission',
        'removal' => 'Awaiting Manual Removal Submission',
    ];

    private const SUBMITTED_STATUSES = [
        'listing' => 'Submitted via Creditinfo UI',
        'removal' => 'Removal Submitted via Creditinfo UI',
    ];

    private CreditinfoPublicDefault $defaults;
    private CreditinfoPublicDefaultBatch $batches;
    private CreditinfoPublicDefaultBatchItem $items;
    private CreditinfoPublicDefaultSubmission $submissions;
    private CreditinfoPublicDefaultAction $actions;
    private CreditinfoPublicDefaultSetting $settings;
    private CreditinfoPublicDefaultFileService $fileService;
    private Loan $loans;
    private Borrower $borrowers;
    private Branch $branches;

    public function __construct()
    {
        $this->defaults = new CreditinfoPublicDefault();
        $this->batches = new CreditinfoPublicDefaultBatch();
        $this->items = new CreditinfoPublicDefaultBatchItem();
        $this->submissions = new CreditinfoPublicDefaultSubmission();
        $this->actions = new CreditinfoPublicDefaultAction();
        $this->settings = new CreditinfoPublicDefaultSetting();
        $this->fileService = new CreditinfoPublicDefaultFileService();
        $this->loans = new Loan();
        $this->borrowers = new Borrower();
        $this->branches = new Branch();
    }

    /** Records in $direction's ready status that aren't already sitting in an open (non-Rejected) batch -- the pool a new batch can be generated from. */
    public function eligible(string $direction): array
    {
        $status = self::READY_STATUSES[$direction] ?? null;
        if (!$status) {
            throw new \InvalidArgumentException('Unknown direction: ' . $direction);
        }
        $rows = $direction === 'listing'
            ? $this->defaults->listingQueue($status, null, 1, 1000)['rows']
            : $this->defaults->removalQueue($status, null, 1, 1000)['rows'];

        return array_values(array_filter($rows, fn ($r) => !$this->batches->hasOpenBatchForPublicDefault((int) $r['id'])));
    }

    /**
     * Builds the file, writes it to storage, and records the batch +
     * every included line -- inside one transaction so a partial write
     * (e.g. one borrower missing an ID number) never leaves an orphaned
     * batch row. Opens the mandatory maker-checker approval request
     * before returning; the batch is unusable (cannot be downloaded)
     * until ApprovalService::approve() runs against it.
     *
     * $idempotencyKey (when supplied) guards the whole operation from the
     * SAME transaction Idempotency::begin()'s usage contract requires --
     * see App\Core\Idempotency's docblock. Callers outside a controller
     * (e.g. a script) can omit it.
     *
     * @return int the new batch id
     */
    public function generate(string $direction, array $publicDefaultIds, string $requestedEnvironment, int $userId, ?string $idempotencyKey = null): int
    {
        if (!isset(self::READY_STATUSES[$direction])) {
            throw new \InvalidArgumentException('Unknown direction: ' . $direction);
        }
        if (empty($publicDefaultIds)) {
            throw new \RuntimeException('Select at least one record to include in the submission file.');
        }

        // Never trust the posted environment past this line -- a 'live'
        // batch can only ever be built once an admin has explicitly
        // switched the setting, mirroring the CBS module's UAT Test
        // Centre ("hidden server-side, not just CSS").
        $environment = ($requestedEnvironment === 'live' && $this->settings->submissionEnvironment() === 'live') ? 'live' : 'test';

        $expectedStatus = self::READY_STATUSES[$direction];
        $action = $direction === 'listing' ? CreditinfoPublicDefaultFileService::ACTION_LISTING : CreditinfoPublicDefaultFileService::ACTION_REMOVAL;
        $supplierReference = $this->settings->supplierReferenceNumber();
        $now = new \DateTimeImmutable();

        return $this->batches->transaction(function () use ($direction, $publicDefaultIds, $environment, $userId, $expectedStatus, $action, $supplierReference, $now, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                Idempotency::begin($idempotencyKey, 'public_defaults.batch.generate', $userId);
            }

            $lines = [];
            $borrowerCache = [];
            $loanCache = [];
            $branchCache = [];
            $employmentCache = [];

            foreach ($publicDefaultIds as $publicDefaultId) {
                $publicDefaultId = (int) $publicDefaultId;
                $pd = $this->defaults->find($publicDefaultId);
                if (!$pd || $pd['status'] !== $expectedStatus) {
                    throw new \RuntimeException('Record #' . $publicDefaultId . ' is not (or is no longer) ' . $expectedStatus . ' -- refresh the queue and try again.');
                }
                if ($this->batches->hasOpenBatchForPublicDefault($publicDefaultId)) {
                    throw new \RuntimeException('Record ' . $pd['listing_reference'] . ' is already included in another open batch.');
                }

                $borrowerId = (int) $pd['borrower_id'];
                $loanId = (int) $pd['loan_id'];
                $borrower = $borrowerCache[$borrowerId] ??= $this->borrowers->find($borrowerId);
                $loan = $loanCache[$loanId] ??= $this->loans->find($loanId);
                if (!$borrower || !$loan) {
                    throw new \RuntimeException('Record ' . $pd['listing_reference'] . ' is missing its borrower or loan record.');
                }
                $branchId = $loan['branch_id'] ?? $pd['branch_id'] ?? null;
                $branch = $branchId ? ($branchCache[$branchId] ??= $this->branches->find((int) $branchId)) : null;
                $employment = $employmentCache[$borrowerId] ??= $this->borrowers->employmentFor($borrowerId);

                $line = $this->fileService->renderLine($pd, $borrower, $loan, $branch, $employment, $supplierReference, $action, $now);
                $lines[] = ['public_default_id' => $publicDefaultId, 'line' => $line, 'reference' => $pd['listing_reference']];
            }

            $filename = $this->fileService->buildFilename($supplierReference, $environment, $now);
            $targetDir = STORAGE_PATH . '/exports/public_defaults';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $fullPath = $targetDir . '/' . $filename;
            file_put_contents($fullPath, implode("\r\n", array_column($lines, 'line')) . "\r\n");

            $batchReference = generate_reference('PDB');
            $batchId = $this->batches->create([
                'batch_reference' => $batchReference,
                'direction' => $direction,
                'environment' => $environment,
                'filename' => $filename,
                'file_path' => 'exports/public_defaults/' . $filename,
                'record_count' => count($lines),
                'status' => 'Pending Review',
                'generated_by' => $userId,
                'generated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $lineNumber = 0;
            foreach ($lines as $entry) {
                $lineNumber++;
                $this->items->create([
                    'batch_id' => $batchId,
                    'public_default_id' => $entry['public_default_id'],
                    'line_number' => $lineNumber,
                    'rendered_line' => $entry['line'],
                ]);
                $this->actions->log($entry['public_default_id'], 'PUBLIC_DEFAULT_BATCH_GENERATED', $userId, null, null, 'Included in submission batch ' . $batchReference . ' (' . $filename . ')');
            }

            $approvalId = ApprovalService::request('public_default_batch_approval', [
                'resource_id' => $batchId,
                'maker_user_id' => $userId,
                'title' => 'Public Default Submission Batch ' . $batchReference . ' (' . ucfirst($direction) . ', ' . count($lines) . ' record(s), ' . $environment . ')',
                'amount' => null,
                'reason' => 'Generated ' . $filename . ' for manual upload to Creditinfo.',
            ]);
            if ($approvalId === null) {
                throw new \RuntimeException('Public Defaults batch approval policy is not active. Contact an administrator before generating a submission file.');
            }

            if ($idempotencyKey !== null) {
                Idempotency::complete($idempotencyKey, 'public_defaults.batch.generate', 'REDIRECT', [
                    'flash_type' => 'success',
                    'flash_message' => 'Submission file generated. It needs approval before it can be downloaded.',
                    'redirect' => '/creditinfo/public-defaults/batches/' . $direction . '/' . $batchId,
                ]);
            }

            return $batchId;
        });
    }

    /**
     * Bulk equivalent of CreditinfoPublicDefaultController::
     * recordListingSubmission()/recordRemovalSubmission(), run once a human
     * has actually uploaded the downloaded file via Creditinfo's own SFTP
     * client -- transitions every included record from its "Awaiting
     * Manual ... Submission" status to "... Submitted via Creditinfo UI",
     * same downstream states the manual-UI path already uses, so the
     * existing per-record "Confirm Listed"/"Confirm Removed" step still
     * applies unchanged afterward.
     */
    public function markSubmitted(int $batchId, ?string $creditinfoReference, ?string $notes, int $userId, ?string $idempotencyKey = null): void
    {
        $batch = $this->batches->find($batchId);
        if (!$batch || $batch['status'] !== 'Downloaded') {
            throw new \RuntimeException('Only a Downloaded batch can be marked submitted.');
        }

        $direction = $batch['direction'];
        $expectedStatus = self::READY_STATUSES[$direction];
        $newStatus = self::SUBMITTED_STATUSES[$direction];
        $items = $this->items->forBatch($batchId);
        $now = date('Y-m-d H:i:s');

        $this->batches->transaction(function () use ($items, $expectedStatus, $newStatus, $direction, $creditinfoReference, $notes, $userId, $now, $batch, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                Idempotency::begin($idempotencyKey, 'public_defaults.batch.mark_submitted', $userId);
            }

            foreach ($items as $item) {
                $publicDefaultId = (int) $item['public_default_id'];
                $pd = $this->defaults->find($publicDefaultId);
                if (!$pd || $pd['status'] !== $expectedStatus) {
                    // Already moved on (or cancelled) by some other action
                    // since the batch was generated -- skip rather than
                    // fail the whole batch; the audit trail below still
                    // shows exactly which records this batch touched.
                    continue;
                }

                $this->submissions->create([
                    'public_default_id' => $publicDefaultId,
                    'direction' => $direction,
                    'method' => 'sftp',
                    'submitted_by' => $userId,
                    'submitted_at' => $now,
                    'creditinfo_reference' => $creditinfoReference,
                    'evidence_document' => null,
                    'notes' => trim(($notes ? $notes . ' -- ' : '') . 'Batch ' . $batch['batch_reference'] . ' (' . $batch['filename'] . ')'),
                ]);

                $updateFields = ['status' => $newStatus];
                if ($direction === 'listing' && $creditinfoReference) {
                    $updateFields['creditinfo_reference'] = $creditinfoReference;
                }
                $this->defaults->updateFields($publicDefaultId, $updateFields);
                $this->actions->log($publicDefaultId, 'PUBLIC_DEFAULT_BATCH_SUBMITTED', $userId, $expectedStatus, $newStatus, 'Submitted via SFTP batch ' . $batch['batch_reference'], $creditinfoReference);
            }

            $this->batches->updateFields((int) $batch['id'], [
                'status' => 'Marked Submitted',
                'marked_submitted_by' => $userId,
                'marked_submitted_at' => $now,
            ]);

            if ($idempotencyKey !== null) {
                Idempotency::complete($idempotencyKey, 'public_defaults.batch.mark_submitted', 'REDIRECT', [
                    'flash_type' => 'success',
                    'flash_message' => 'Batch marked submitted. Confirm each record Listed/Removed once it actually appears/disappears on Creditinfo.',
                    'redirect' => '/creditinfo/public-defaults/batches/' . $direction . '/' . $batch['id'],
                ]);
            }
        });
    }
}
