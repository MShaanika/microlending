<?php

namespace App\Services;

use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Models\CreditinfoPublicDefaultSetting;
use phpseclib3\Net\SFTP;

/**
 * Uploads an already-Approved CPL batch to Creditinfo's SFTP gateway --
 * the same physical server/account Public Defaults already submits to
 * (confirmed 2026-10-11: Creditinfo's own reply, "File well received",
 * to a CPL file sent through that channel; their shared example filename
 * CP0001_ALL_T702_M_20130331_1_1.txt matches the pattern
 * CplBatchController::download() already builds).
 *
 * Deliberately reads the SFTP host/port/username/secret from
 * CreditinfoPublicDefaultSetting, NOT a second copy in CplSetting --
 * it's the same Creditinfo SFTP account for both file types, and storing
 * the password in two places would let them silently drift out of sync
 * (e.g. a rotated password updated in one settings screen but not the
 * other). CplSetting only needs to know whether CPL-via-this-channel is
 * switched on and which remote subfolder to use.
 *
 * Every safety gate stays upstream of this class. For a live batch the file
 * only exists because a second person already approved it
 * (CplBatchController::approve()), and submit() only transports the approved
 * file_content exactly as approved. The separate uploadTestFile() path is for
 * Creditinfo sign-off TEST files: it has its own upstream guards
 * (CplUatFileService, CplUatUploadService), refuses any name that is not a
 * T702 test file name, and likewise never builds or edits content.
 */
class CplSftpService
{
    private CplBatch $batches;
    private CplSetting $cplSettings;
    private CreditinfoPublicDefaultSetting $sftpSettings;

    public function __construct(?CplBatch $batches = null, ?CplSetting $cplSettings = null, ?CreditinfoPublicDefaultSetting $sftpSettings = null)
    {
        $this->batches = $batches ?? new CplBatch();
        $this->cplSettings = $cplSettings ?? new CplSetting();
        $this->sftpSettings = $sftpSettings ?? new CreditinfoPublicDefaultSetting();
    }

    public function isReady(): bool
    {
        return $this->sftpSettings->isSftpReady() && $this->cplSettings->get('sftp_submission_enabled', 'off') === 'on';
    }

    /**
     * True when the saved Public Defaults SFTP connection is complete and
     * switched on. A sign-off TEST upload deliberately does not need the
     * live-CPL switch (sftp_submission_enabled): that switch guards real
     * submissions of approved batches, which a synthetic test file is not.
     */
    public function isTestUploadReady(): bool
    {
        return $this->sftpSettings->isSftpReady();
    }

    /** Where a file would be dropped, for display only: host[:port] and the remote folder. Never includes the username or secret. */
    public function destinationLabel(): string
    {
        $host = $this->sftpSettings->get('sftp_host');
        if ($host === '') {
            return '';
        }
        $port = (int) $this->sftpSettings->get('sftp_port', '22');
        $directory = $this->remoteDirectory();
        return $host . ($port > 0 && $port !== 22 ? ':' . $port : '') . ' / ' . ($directory !== '' ? $directory : '(home folder)');
    }

    /**
     * Uploads ONE synthetic sign-off test file (file type T702) to the same
     * Creditinfo SFTP folder live files use, with the connection saved in
     * Public Defaults Settings. The password is read here and goes straight
     * to the SFTP login -- it is never logged, returned or shown.
     *
     * Refuses anything whose name is not a sign-off test file name
     * (CplUatFileService::isSafeTestFilename), so an L702 live name or a path
     * can never reach the server through this method. What the content is
     * allowed to contain is decided upstream (CplUatFileService::assertUploadable).
     *
     * @param callable|null $connect fn(string $host, int $port): object with login(string, ?string): bool,
     *                               put(string, string): bool and getLastSFTPError(): string -- tests pass a
     *                               fake; null connects with phpseclib.
     * @return array{host:string, remote_path:string, bytes:int}
     * @throws \RuntimeException with a message written for the person who pressed the button
     */
    public function uploadTestFile(string $filename, string $content, ?callable $connect = null): array
    {
        if (!CplUatFileService::isSafeTestFilename($filename)) {
            throw new \RuntimeException('Refusing to upload: the file name is not a sign-off test file name.');
        }
        if (!$this->sftpSettings->isSftpReady()) {
            throw new \RuntimeException('The SFTP connection is not set up or not switched on. Complete it in Public Defaults Settings first (host, username, password, folder, and SFTP enabled).');
        }

        $host = $this->sftpSettings->get('sftp_host');
        $port = (int) $this->sftpSettings->get('sftp_port', '22');
        $username = $this->sftpSettings->get('sftp_username');
        $password = $this->sftpSettings->getDecryptedSftpSecret();
        $directory = $this->remoteDirectory();
        $remotePath = ($directory !== '' ? $directory . '/' : '') . $filename;

        $sftp = $connect !== null ? $connect($host, $port) : new SFTP($host, $port, 15);
        try {
            try {
                $loggedIn = $sftp->login($username, $password);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not connect to ' . $host . ':' . $port . ' (' . $e->getMessage() . ').', 0, $e);
            }
            if (!$loggedIn) {
                throw new \RuntimeException('SFTP login failed: Creditinfo\'s server refused the saved login. Nothing was sent. Do not keep trying; ask your administrator to check the user name and password in Public Defaults Settings.');
            }
            try {
                $stored = $sftp->put($remotePath, $content);
            } catch (\Throwable $e) {
                // Timeouts and dropped connections surface here, possibly after the last bytes were already written.
                throw new CplSftpUncertainException('The connection broke while the file was being sent (' . $e->getMessage() . '), so it may already have reached Creditinfo. Check with Creditinfo before sending it again.', 0, $e);
            }
            if (!$stored) {
                throw new \RuntimeException('SFTP upload failed: ' . ($sftp->getLastSFTPError() ?: 'unknown error') . '. Nothing was recorded. A partly sent file may be on Creditinfo\'s server, so tell them before you try again.');
            }
        } finally {
            if (method_exists($sftp, 'disconnect')) {
                try {
                    $sftp->disconnect();
                } catch (\Throwable $ignored) {
                    // The file is already sent, or the real error is already on its way out. A socket that
                    // will not close must not turn a successful upload into a reported failure (a person
                    // would then send it again), nor hide the real error.
                }
            }
        }

        return ['host' => $host, 'remote_path' => $remotePath, 'bytes' => strlen($content)];
    }

    /** The remote folder files are dropped in: CPL's own override if set, otherwise the Public Defaults outbound folder (the same account and server). */
    private function remoteDirectory(): string
    {
        return rtrim($this->cplSettings->get('sftp_directory') ?: $this->sftpSettings->get('sftp_outbound_directory'), '/');
    }

    /** Uploads $batchId's approved file content to Creditinfo's inbound SFTP folder, then marks the batch Submitted. */
    public function submit(int $batchId, int $userId): void
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('CPL SFTP submission is not enabled -- switch it on in CPL Settings (it reuses the Public Defaults SFTP connection, which must also be configured).');
        }

        $batch = $this->batches->find($batchId);
        if (!$batch || $batch['status'] !== 'Approved' || empty($batch['file_content'])) {
            throw new \RuntimeException('Only an Approved batch with generated file content can be submitted via SFTP.');
        }

        $host = $this->sftpSettings->get('sftp_host');
        $port = (int) $this->sftpSettings->get('sftp_port', '22');
        $username = $this->sftpSettings->get('sftp_username');
        $password = $this->sftpSettings->getDecryptedSftpSecret();
        $directory = rtrim($this->cplSettings->get('sftp_directory') ?: $this->sftpSettings->get('sftp_outbound_directory'), '/');

        $sftp = new SFTP($host, $port, 15);
        if (!$sftp->login($username, $password)) {
            throw new \RuntimeException('SFTP login failed -- check the stored host/username/password in Public Defaults Settings (CPL reuses that connection).');
        }

        $filename = $this->filename($batch);
        $remotePath = ($directory !== '' ? $directory . '/' : '') . $filename;
        if (!$sftp->put($remotePath, $batch['file_content'])) {
            throw new \RuntimeException('SFTP upload failed: ' . ($sftp->getLastSFTPError() ?: 'unknown error'));
        }

        $this->batches->updateRecord($batchId, [
            'status' => 'Submitted',
            'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => 'Submitted automatically via SFTP to ' . $host . ':' . $remotePath,
            'submission_recorded_by' => $userId,
            'submission_recorded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Exact same naming rule as CplBatchController::download() -- kept in one place so the uploaded filename and the human-downloadable one can never drift apart. */
    public function filename(array $batch): string
    {
        $fileType = $this->cplSettings->isProductionEnvironment() ? 'L702' : 'T702';
        $supplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->cplSettings->supplierReferenceNumber() ?: 'PENDING');
        return $supplierRef . '_' . $this->cplSettings->recipient() . '_' . $fileType . '_M_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt';
    }
}
