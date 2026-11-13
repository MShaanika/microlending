<?php

namespace App\Services;

use App\Models\CplBatch;
use App\Models\CplSetting;

/**
 * The "Upload to Creditinfo" features of the CPL sign-off test screens:
 *  - upload(): builds a stored test batch into its T702 file, checks that
 *    nothing but the vendor-approved synthetic test data is in it, and sends it;
 *  - uploadFile(): sends a standard test file chosen from the computer, as it is,
 *    after checking it is exactly the standard file for the date in its name.
 * Both use the SFTP connection saved in Public Defaults Settings and only then
 * write down that the file was sent.
 *
 * The order matters. Guards run first; the upload runs second; the batch is
 * marked "Awaiting Load Report" last, so a refused or failed upload leaves
 * the batch exactly as it was. Nothing here ever marks a file Accepted --
 * only a person recording Creditinfo's own load report does that
 * (CplUatTestController::recordOutcome) -- and a file Creditinfo has already
 * accepted is never sent again, so a recorded Accepted cannot be undone by a
 * second upload. The one deliberate exception to "a failed upload leaves no
 * trace": when the connection breaks WHILE sending (the file may or may not
 * be at Creditinfo), the batch is marked UNCLEAR so nobody sends it again
 * without asking Creditinfo first.
 */
class CplUatUploadService
{
    /** The note written on the batch after an upload; the sign-off page recognises an uploaded batch by this prefix. */
    public const NOTE_PREFIX = 'Uploaded to Creditinfo SFTP';

    /** The note written instead when the connection broke while sending: the file may or may not be at Creditinfo. */
    public const UNCLEAR_PREFIX = 'Upload attempt UNCLEAR';

    /** A second upload of the same batch this soon after the first is refused: a double click, a second tab or a replayed request. */
    public const RESEND_WINDOW_SECONDS = 120;

    private const CONNECTION_NOT_READY = 'The SFTP connection is not set up or not switched on yet. Complete it in Public Defaults Settings (host, username, password, folder, and SFTP enabled).';

    private const EARLIER_RESULT = ' Earlier result: ';

    private CplBatch $batches;
    private CplSetting $settings;
    private CplUatFileService $files;
    private CplSftpService $sftp;
    private CplUatBatchService $batchService;

    public function __construct(?CplBatch $batches = null, ?CplSetting $settings = null, ?CplUatFileService $files = null, ?CplSftpService $sftp = null, ?CplUatBatchService $batchService = null)
    {
        $this->batches = $batches ?? new CplBatch();
        $this->settings = $settings ?? new CplSetting();
        $this->files = $files ?? new CplUatFileService();
        $this->sftp = $sftp ?? new CplSftpService();
        $this->batchService = $batchService ?? new CplUatBatchService($this->batches);
    }

    /**
     * What the sign-off dashboard needs to draw its "upload a file you already have" section.
     *
     * @return array{ready:bool, destination:string, problem:?string}
     */
    public function connectionStatus(): array
    {
        $ready = $this->sftp->isTestUploadReady();
        return ['ready' => $ready, 'destination' => $this->sftp->destinationLabel(), 'problem' => $ready ? null : self::CONNECTION_NOT_READY];
    }

    /**
     * What the sign-off test page needs to draw the upload section.
     * 'problem' is the reason the button is not offered (null = ready); 'hold' says when that reason is not an error but
     * a deliberate wait ('recent': uploaded a moment ago; 'accepted': Creditinfo already accepted the file).
     * 'recorded_outcome' is any result already recorded for the batch (a file was sent, by this app or by hand);
     * 'unclear_note' is set when the last attempt ended with the connection breaking while sending.
     *
     * @param array<string,mixed> $batch cpl_batches row of a sign-off test batch
     * @return array{filename:?string, kind:string, record_count:int, problem:?string, hold:?string, can_upload:bool, destination:string, uploaded_at:?string, uploaded_note:?string, unclear_note:?string, recorded_outcome:?string, supplier_ref:string, trading_name:string}
     */
    public function describe(array $batch): array
    {
        $supplierRef = $this->settings->supplierReferenceNumber();
        $tradingName = $this->settings->tradingName();
        $outcome = (string) ($batch['submission_outcome'] ?? '');
        $out = [
            'filename' => null,
            'kind' => (string) ($batch['batch_type'] ?? ''),
            'record_count' => 0,
            'problem' => null,
            'hold' => null,
            'can_upload' => false,
            'destination' => $this->sftp->destinationLabel(),
            'uploaded_at' => null,
            'uploaded_note' => null,
            'unclear_note' => null,
            'recorded_outcome' => $outcome !== '' ? $outcome : null,
            'supplier_ref' => trim($supplierRef),
            'trading_name' => $tradingName,
        ];

        try {
            $file = $this->files->build($batch, $supplierRef, $tradingName);
            $out['filename'] = $file['filename'];
            $out['record_count'] = $file['record_count'];
            $this->files->assertUploadable($batch, $supplierRef, $file);
            $hold = $this->holdReason($batch);
            if ($hold !== null) {
                $out['hold'] = $hold;
                $out['problem'] = $this->holdMessage($hold, $batch);
            } else {
                $this->assertNoRealFileClash((string) ($batch['batch_type'] ?? ''), (string) ($batch['month_end'] ?? ''));
            }
        } catch (\RuntimeException $e) {
            $out['problem'] = $e->getMessage();
        }

        if ($out['problem'] === null && !$this->sftp->isTestUploadReady()) {
            $out['problem'] = self::CONNECTION_NOT_READY;
        }
        $out['can_upload'] = $out['problem'] === null;

        $notes = (string) ($batch['submission_notes'] ?? '');
        if (str_starts_with($notes, self::NOTE_PREFIX) && !empty($batch['submission_recorded_at'])) {
            $out['uploaded_at'] = (string) $batch['submission_recorded_at'];
            $out['uploaded_note'] = $notes;
        }
        if (str_starts_with($notes, self::UNCLEAR_PREFIX)) {
            $out['unclear_note'] = $notes;
        }

        return $out;
    }

    /**
     * @param callable|null $connect see CplSftpService::uploadTestFile() -- tests pass a fake connection
     * @return array{filename:string, kind:string, record_count:int, host:string, remote_path:string, bytes:int, uploaded_at:string, recorded:bool}
     * @throws \RuntimeException when the batch may not be uploaded or the upload fails; the batch is then left unchanged
     *                           (except after a CplSftpUncertainException, which marks it UNCLEAR)
     */
    public function upload(int $batchId, int $userId, ?callable $connect = null): array
    {
        $batch = $this->batches->find($batchId);
        if (!$batch || empty($batch['is_uat_test'])) {
            throw new \RuntimeException('Test batch not found.');
        }

        $supplierRef = $this->settings->supplierReferenceNumber();
        $file = $this->files->build($batch, $supplierRef, $this->settings->tradingName());
        $this->files->assertUploadable($batch, $supplierRef, $file);
        $this->assertMaySend($batch, $file['kind'], (string) $batch['month_end']);

        try {
            $sent = $this->sftp->uploadTestFile($file['filename'], $file['content'], $connect);
        } catch (CplSftpUncertainException $e) {
            $this->recordUnclear($batchId, $userId, $file['filename'], $batch);
            throw $e;
        }

        // The file is with Creditinfo from here on. If writing it down fails, say so
        // instead of pretending the upload did not happen.
        $uploadedAt = date('Y-m-d H:i:s');
        $recorded = true;
        try {
            $this->recordUpload($batchId, $userId, $uploadedAt, $file['filename'], $file['record_count'], $sent['bytes'], '', $batch);
        } catch (\Throwable $e) {
            $recorded = false;
            error_log('CPL sign-off test file ' . $file['filename'] . ' was uploaded but could not be recorded on batch #' . $batchId . ': ' . $e->getMessage());
        }

        return [
            'filename' => $file['filename'],
            'kind' => $file['kind'],
            'record_count' => $file['record_count'],
            'host' => $sent['host'],
            'remote_path' => $sent['remote_path'],
            'bytes' => $sent['bytes'],
            'uploaded_at' => $uploadedAt,
            'recorded' => $recorded,
        ];
    }

    /**
     * Sends a file chosen from the computer -- one made earlier and kept, for
     * example, in the Downloads folder -- exactly as it is, but only if it is
     * the standard test file for the date in its name
     * (CplUatFileService::inspectStandardFile). The upload is then recorded on
     * the sign-off batch of that type and date, which is generated first if
     * there is none yet. That happens only after a successful upload, so a
     * refused or failed attempt leaves no trace in the batches (again except
     * after a CplSftpUncertainException, which marks the batch UNCLEAR).
     *
     * @param callable|null $connect see CplSftpService::uploadTestFile() -- tests pass a fake connection
     * @return array{filename:string, kind:string, record_count:int, host:string, remote_path:string, bytes:int, uploaded_at:string, recorded:bool, batch_id:?int}
     * @throws \RuntimeException when the file is not the standard test file or the upload fails
     */
    public function uploadFile(string $filename, string $content, int $userId, ?callable $connect = null): array
    {
        $info = $this->files->inspectStandardFile($filename, $content, trim($this->settings->supplierReferenceNumber()), $this->settings->tradingName());

        $existing = $this->batches->findByTypeAndMonth($info['kind'], $info['date'], true);
        $this->assertMaySend($existing, $info['kind'], $info['date']);

        try {
            $sent = $this->sftp->uploadTestFile($filename, $content, $connect);
        } catch (CplSftpUncertainException $e) {
            try {
                $this->recordUnclear($this->batchService->ensure($info['kind'], $info['date'], $userId > 0 ? $userId : null), $userId, $filename, $existing);
            } catch (\Throwable $inner) {
                error_log('CPL sign-off test file ' . $filename . ' ended unclear and its batch could not be marked: ' . $inner->getMessage());
            }
            throw $e;
        }

        $uploadedAt = date('Y-m-d H:i:s');
        $recorded = true;
        $batchId = null;
        try {
            $batchId = $this->batchService->ensure($info['kind'], $info['date'], $userId > 0 ? $userId : null);
            $this->recordUpload($batchId, $userId, $uploadedAt, $filename, $info['record_count'], $sent['bytes'], 'file chosen from the computer', $existing);
        } catch (\Throwable $e) {
            $recorded = false;
            error_log('CPL sign-off test file ' . $filename . ' was uploaded but could not be recorded: ' . $e->getMessage());
        }

        return [
            'filename' => $filename,
            'kind' => $info['kind'],
            'record_count' => $info['record_count'],
            'host' => $sent['host'],
            'remote_path' => $sent['remote_path'],
            'bytes' => $sent['bytes'],
            'uploaded_at' => $uploadedAt,
            'recorded' => $recorded,
            'batch_id' => $batchId,
        ];
    }

    /**
     * Why this batch must not be sent right now although the file itself is fine: 'accepted' (Creditinfo already accepted
     * it) or 'recent' (this app uploaded it within RESEND_WINDOW_SECONDS). Null when nothing holds it back.
     *
     * @param array<string,mixed>|null $batch
     */
    private function holdReason(?array $batch): ?string
    {
        if ($batch === null) {
            return null;
        }
        if (($batch['submission_outcome'] ?? null) === 'Accepted') {
            return 'accepted';
        }
        $recordedAt = (string) ($batch['submission_recorded_at'] ?? '');
        if (($batch['submission_outcome'] ?? null) === 'Awaiting Load Report'
            && $recordedAt !== ''
            && str_starts_with((string) ($batch['submission_notes'] ?? ''), self::NOTE_PREFIX)
        ) {
            $age = time() - (int) strtotime($recordedAt);
            if ($age >= 0 && $age < self::RESEND_WINDOW_SECONDS) {
                return 'recent';
            }
        }
        return null;
    }

    /** @param array<string,mixed> $batch */
    private function holdMessage(string $reason, array $batch): string
    {
        if ($reason === 'accepted') {
            return 'Creditinfo has already accepted this test file (the result is recorded on its page), so it does not need to be sent again.';
        }
        return 'This test file was uploaded a moment ago (at ' . date('H:i', (int) strtotime((string) $batch['submission_recorded_at'])) . '). Wait a couple of minutes, and check with Creditinfo, before sending it again.';
    }

    /**
     * Refuses a send that would do harm even though the file itself is fine. Read-only, and run before any connection.
     *
     * @param array<string,mixed>|null $batch the existing sign-off batch of this type and date, if there is one
     * @throws \RuntimeException
     */
    private function assertMaySend(?array $batch, string $kind, string $date): void
    {
        $hold = $this->holdReason($batch);
        if ($hold !== null) {
            throw new \RuntimeException($this->holdMessage($hold, $batch));
        }
        $this->assertNoRealFileClash($kind, $date);
    }

    /**
     * While the environment is not Production, the live monthly flow also names its files T702, so a real batch that was
     * already approved or submitted for this month end has this very file name -- and put() overwrites.
     *
     * @throws \RuntimeException
     */
    private function assertNoRealFileClash(string $kind, string $date): void
    {
        if ($kind !== 'Monthly' || $this->settings->isProductionEnvironment()) {
            return;
        }
        $real = $this->batches->findByTypeAndMonth('Monthly', $date, false);
        if ($real && in_array($real['status'] ?? '', ['Approved', 'Submitted'], true)) {
            throw new \RuntimeException('A real CPL monthly batch for ' . $date . ' is already ' . strtolower((string) $real['status']) . ' and, while the environment in CPL Settings is not Production, it uses this very file name. Sending the test file would overwrite it, so nothing was sent.');
        }
    }

    /**
     * Writes the upload on the batch: Awaiting Load Report, never Accepted -- only a person recording Creditinfo's load
     * report does that. A result a person recorded earlier (for example Rejected, with the reason) is kept in the note.
     *
     * @param array<string,mixed>|null $before the batch as it was before this upload
     */
    private function recordUpload(int $batchId, int $userId, string $uploadedAt, string $filename, int $records, int $bytes, string $source, ?array $before): void
    {
        $this->batches->updateRecord($batchId, [
            'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => self::NOTE_PREFIX . ' on ' . date('d M Y H:i', strtotime($uploadedAt)) . ' as ' . $filename
                . ' (' . $records . ' records, ' . $bytes . ' bytes' . ($source !== '' ? ', ' . $source : '') . '). Waiting for the Creditinfo load report.' . $this->earlierResult($before),
            'submission_recorded_by' => $userId > 0 ? $userId : null,
            'submission_recorded_at' => $uploadedAt,
        ]);
    }

    /**
     * The connection broke while sending, so the file may or may not be at Creditinfo. Mark the batch so the page keeps
     * saying so (a flash message disappears on the next page load). Best effort: it never hides the original problem.
     *
     * @param array<string,mixed>|null $before
     */
    private function recordUnclear(int $batchId, int $userId, string $filename, ?array $before): void
    {
        try {
            $this->batches->updateRecord($batchId, [
                'submission_outcome' => 'Awaiting Load Report',
                'submission_notes' => self::UNCLEAR_PREFIX . ' on ' . date('d M Y H:i') . ': the connection broke while ' . $filename
                    . ' was being sent, so Creditinfo may or may not have it. Ask Creditinfo before sending it again.' . $this->earlierResult($before),
                'submission_recorded_by' => $userId > 0 ? $userId : null,
                'submission_recorded_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('CPL sign-off test file ' . $filename . ' ended unclear and batch #' . $batchId . ' could not be marked: ' . $e->getMessage());
        }
    }

    /**
     * The " Earlier result: ..." tail for a new note, so a result somebody recorded (Rejected, with the reason) is not
     * lost when the file is sent again. Our own earlier notes already carry that tail: it is passed on, not rebuilt,
     * so repeated uploads neither lose the reason nor pile up notes.
     *
     * @param array<string,mixed>|null $before
     */
    private function earlierResult(?array $before): string
    {
        $outcome = (string) ($before['submission_outcome'] ?? '');
        if ($outcome === '') {
            return '';
        }
        $notes = trim((string) ($before['submission_notes'] ?? ''));
        if (str_starts_with($notes, self::NOTE_PREFIX) || str_starts_with($notes, self::UNCLEAR_PREFIX)) {
            $at = strpos($notes, self::EARLIER_RESULT);
            return $at !== false ? substr($notes, $at) : self::EARLIER_RESULT . $outcome . '.';
        }
        // Cut by characters, not bytes, so a multi-byte letter is never split (and the UPDATE never sees broken UTF-8).
        $shortened = $notes === '' ? '' : mb_substr(mb_scrub($notes, 'UTF-8'), 0, 300, 'UTF-8');
        return self::EARLIER_RESULT . $outcome . ($shortened !== '' ? ' (' . $shortened . ')' : '') . '.';
    }
}
