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
 * Every safety gate stays upstream of this class: the file only exists
 * because a second person already approved the batch
 * (CplBatchController::approve()), and this never builds or edits file
 * content -- only transports the approved file_content exactly as
 * approved.
 */
class CplSftpService
{
    private CplBatch $batches;
    private CplSetting $cplSettings;
    private CreditinfoPublicDefaultSetting $sftpSettings;

    public function __construct()
    {
        $this->batches = new CplBatch();
        $this->cplSettings = new CplSetting();
        $this->sftpSettings = new CreditinfoPublicDefaultSetting();
    }

    public function isReady(): bool
    {
        return $this->sftpSettings->isSftpReady() && $this->cplSettings->get('sftp_submission_enabled', 'off') === 'on';
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
