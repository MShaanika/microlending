<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CreditinfoPublicDefaultSetting;

/**
 * Item 14's Public Default Settings (submission architecture) + item 5's
 * eligibility configuration + item 15's tariff configuration -- one
 * screen, since all three are plain admin-editable values on the same
 * creditinfo_public_default_settings table.
 *
 * Creditinfo has confirmed in writing there is no REST API for Public
 * Defaults -- only their own User Interface (fully supported by this
 * module) and SFTP. public_defaults_enabled can therefore be freely
 * toggled -- the manual workflow is real and complete -- there is nothing
 * left to gate it on. sftp_enabled now actually gates a real client
 * (CreditinfoPublicDefaultSftpService, wired in via "Submit via SFTP" on
 * an approved batch) once host/username/secret/outbound folder are all
 * filled in here -- see CreditinfoPublicDefaultSetting::isSftpReady().
 */
class CreditinfoPublicDefaultSettingController extends Controller
{
    private const BOOLEAN_FIELDS = ['borrower_notice_required', 'sftp_enabled'];

    private const TEXT_FIELDS = [
        'minimum_days_in_arrears', 'minimum_outstanding_balance', 'notice_waiting_period_days',
        'listing_fee', 'removal_fee', 'vat_rate', 'tariff_effective_date',
        'supplier_reference_number',
        'sftp_host', 'sftp_port', 'sftp_username', 'sftp_auth_method',
        'sftp_outbound_directory', 'sftp_inbound_directory', 'sftp_archive_directory',
        'sftp_file_format', 'sftp_file_naming_convention', 'sftp_submission_schedule',
    ];

    private CreditinfoPublicDefaultSetting $settings;

    public function __construct()
    {
        $this->settings = new CreditinfoPublicDefaultSetting();
    }

    public function edit(): void
    {
        Auth::authorize('creditinfo.public_defaults.settings');
        $all = $this->settings->allSettings();

        $this->view('creditinfo/public_defaults/settings', [
            'title' => 'Public Defaults Settings',
            'settings' => $all,
            'sftpSecretSet' => $this->settings->isSftpSecretSet(),
            'sftpReady' => $this->settings->isSftpReady(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('creditinfo.public_defaults.settings');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;

        $this->settings->set('public_defaults_enabled', !empty($_POST['public_defaults_enabled']) ? 'on' : 'off', $userId);

        foreach (self::BOOLEAN_FIELDS as $key) {
            $this->settings->set($key, !empty($_POST[$key]) ? ($key === 'borrower_notice_required' ? 'yes' : 'on') : ($key === 'borrower_notice_required' ? 'no' : 'off'), $userId);
        }

        foreach (self::TEXT_FIELDS as $key) {
            if (array_key_exists($key, $_POST)) {
                $this->settings->set($key, trim((string) $_POST[$key]), $userId);
            }
        }

        // A dedicated allowlist of exactly 'test'/'live', not TEXT_FIELDS --
        // this is the "hidden server-side, not just CSS" gate a submission
        // batch checks before it's allowed to render an environment=live
        // file (see CreditinfoPublicDefaultBatchService::generate()), so a
        // stray posted value can never silently become a third state.
        if (array_key_exists('submission_environment', $_POST)) {
            $this->settings->set('submission_environment', $_POST['submission_environment'] === 'live' ? 'live' : 'test', $userId);
        }

        // Blank means "leave the stored secret as it is" -- see
        // CreditinfoPublicDefaultSetting::setEncryptedSftpSecret(). Never
        // logged, audited, or echoed back.
        $this->settings->setEncryptedSftpSecret($_POST['sftp_secret'] ?? '', $userId);

        Audit::log('Update', 'Creditinfo', 'Updated Public Defaults settings');

        Session::flash('success', 'Settings saved.');
        $this->redirect('/creditinfo/public-defaults/settings');
    }
}
