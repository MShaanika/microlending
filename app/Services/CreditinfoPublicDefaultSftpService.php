<?php

namespace App\Services;

use App\Models\CreditinfoPublicDefaultBatch;
use App\Models\CreditinfoPublicDefaultSetting;
use phpseclib3\Net\SFTP;

/**
 * The one real SFTP client in this module -- connects to Creditinfo's own
 * SFTP gateway and drops an already-approved batch file into their inbound
 * folder, then records the same "Marked Submitted" transition the manual
 * workflow already used. Every safety gate stays upstream of this class:
 * the file itself only exists because two different people already
 * approved the listing and the batch (see CreditinfoPublicDefaultBatchService),
 * and CreditinfoPublicDefaultSetting::isSftpReady() still requires an
 * admin to have explicitly switched SFTP on in Settings before this class
 * will do anything. This class never builds or edits file content, only
 * transports what's already on disk.
 */
class CreditinfoPublicDefaultSftpService
{
    private CreditinfoPublicDefaultBatch $batches;
    private CreditinfoPublicDefaultSetting $settings;
    private CreditinfoPublicDefaultBatchService $batchService;

    public function __construct()
    {
        $this->batches = new CreditinfoPublicDefaultBatch();
        $this->settings = new CreditinfoPublicDefaultSetting();
        $this->batchService = new CreditinfoPublicDefaultBatchService();
    }

    /** Uploads $batchId's file to Creditinfo's inbound SFTP folder, then runs it through the existing "Mark Submitted" bookkeeping (same downstream state as the manual path). */
    public function submit(int $batchId, int $userId): void
    {
        if (!$this->settings->isSftpReady()) {
            throw new \RuntimeException('SFTP is not configured/enabled -- set host, username, secret and outbound folder, and switch SFTP on, in Public Defaults Settings first.');
        }

        $batch = $this->batches->find($batchId);
        if (!$batch || !in_array($batch['status'], ['Approved', 'Downloaded'], true)) {
            throw new \RuntimeException('Only an Approved batch can be submitted via SFTP.');
        }

        $fullPath = STORAGE_PATH . '/' . $batch['file_path'];
        if (!is_file($fullPath)) {
            throw new \RuntimeException('File is missing from storage.');
        }

        $host = $this->settings->get('sftp_host');
        $port = (int) $this->settings->get('sftp_port', '22');
        $username = $this->settings->get('sftp_username');
        $password = $this->settings->getDecryptedSftpSecret();
        $directory = rtrim($this->settings->get('sftp_outbound_directory'), '/');

        $sftp = new SFTP($host, $port, 15);
        if (!$sftp->login($username, $password)) {
            throw new \RuntimeException('SFTP login failed -- check the stored host/username/password in Public Defaults Settings.');
        }

        $remotePath = ($directory !== '' ? $directory . '/' : '') . $batch['filename'];
        $contents = file_get_contents($fullPath);
        if ($contents === false || !$sftp->put($remotePath, $contents)) {
            throw new \RuntimeException('SFTP upload failed: ' . ($sftp->getLastSFTPError() ?: 'unknown error'));
        }

        if ($batch['status'] === 'Approved') {
            $this->batches->updateFields($batchId, [
                'status' => 'Downloaded',
                'downloaded_by' => $userId,
                'downloaded_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->batchService->markSubmitted($batchId, null, 'Submitted automatically via SFTP to ' . $host . ':' . $remotePath, $userId);
    }
}
