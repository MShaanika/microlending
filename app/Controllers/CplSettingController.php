<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CplSetting;
use App\Services\CplAutoSubmissionService;

/**
 * CPLv1.1 configuration screen -- Supplier Reference Number, trading name,
 * recipient, version, submission environment, agreed billing date /
 * deadline rule, and the configurable one-month/multi-month account-type
 * code mapping (see App\Models\CplSetting for why account_type_mapping_confirmed
 * defaults 'no' and only drives a warning, never a block).
 */
class CplSettingController extends Controller
{
    private const TEXT_FIELDS = [
        'supplier_reference_number', 'trading_name', 'recipient', 'cpl_version',
        'submission_environment', 'agreed_billing_date', 'monthly_submission_deadline_rule',
        'account_type_one_month', 'account_type_multi_month', 'sftp_directory',
        // automatic submission (the on/off switch itself is NOT here: it has its own confirmation page on the CPL dashboard)
        'cpl_monthly_send_day', 'cpl_auto_excluded_names', 'cpl_auto_alert_user_ids', 'cpl_public_base_url',
    ];

    /**
     * Settings that decide WHERE and WHETHER a live file goes to Creditinfo, or what the automatic submission does. Changing one needs the approval
     * permission (reports.cpl_approve), not only the right to use the CPL reports, and every change is audited with the old and the new value (none of these
     * is a secret; the SFTP password is not stored here and is never logged).
     */
    private const RESTRICTED = [
        'submission_environment', 'sftp_submission_enabled', 'sftp_directory', 'supplier_reference_number', 'recipient',
        'cpl_auto_excluded_names', 'cpl_monthly_send_day', 'cpl_auto_alert_user_ids', 'cpl_public_base_url',
        'trading_name', // it is written into every record of the monthly file (the Creditinfo "trading name" field)
    ];

    /** Changing one of these revokes the standing approval of the automatic submission (it was given for exactly this destination). */
    private const REVOKES_APPROVAL = ['submission_environment', 'sftp_submission_enabled', 'sftp_directory', 'supplier_reference_number', 'recipient', 'trading_name'];

    private CplSetting $settings;

    public function __construct()
    {
        $this->settings = new CplSetting();
    }

    private static function defaultFor(string $key): string
    {
        return ['recipient' => 'ALL', 'submission_environment' => 'uat', 'sftp_submission_enabled' => 'off', 'cpl_monthly_send_day' => '3'][$key] ?? '';
    }

    /**
     * Which of the restricted settings the posted values would change (key => [old, new]). Changing any of them needs reports.cpl_approve; the caller
     * checks that BEFORE anything is saved.
     *
     * @param array<string,string> $current the stored settings
     * @param array<string,string> $new the values about to be saved
     * @return array<string, array{0:string, 1:string}>
     */
    public static function changedRestricted(array $current, array $new): array
    {
        $changed = [];
        foreach (self::RESTRICTED as $key) {
            if (array_key_exists($key, $new) && (string) ($current[$key] ?? self::defaultFor($key)) !== (string) $new[$key]) {
                $changed[$key] = [(string) ($current[$key] ?? self::defaultFor($key)), (string) $new[$key]];
            }
        }
        return $changed;
    }

    /** @return string[] */
    public static function restrictedKeys(): array
    {
        return self::RESTRICTED;
    }

    /** @return string[] */
    public static function approvalRevokingKeys(): array
    {
        return self::REVOKES_APPROVAL;
    }

    public function edit(): void
    {
        Auth::authorize('reports.cpl_export');

        $this->view('reports/cpl_export/settings', [
            'title' => 'CPL Settings',
            'settings' => $this->settings->allSettings(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('reports.cpl_export');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;

        // what would change: key => [old, new]
        $new = [];
        foreach (self::TEXT_FIELDS as $key) {
            if (array_key_exists($key, $_POST)) {
                $new[$key] = trim((string) $_POST[$key]);
            }
        }
        // Deliberately requires an explicit opt-in separate from Public
        // Defaults' own sftp_enabled -- see database/cpl_sftp_submission.sql.
        $new['sftp_submission_enabled'] = !empty($_POST['sftp_submission_enabled']) ? 'on' : 'off';
        $current = $this->settings->allSettings();
        $changedRestricted = self::changedRestricted($current, $new);
        if ($changedRestricted !== [] && !Auth::can('reports.cpl_approve')) {
            Session::flash('error', 'Changing ' . implode(', ', array_keys($changedRestricted)) . ' needs the CPL approval permission (reports.cpl_approve). Nothing was saved.');
            $this->redirect('/reports/cpl-export/settings');
            return;
        }

        foreach ($new as $key => $value) {
            $this->settings->set($key, $value, $userId);
        }
        $this->settings->set('account_type_mapping_confirmed', !empty($_POST['account_type_mapping_confirmed']) ? 'yes' : 'no', $userId);

        foreach ($changedRestricted as $key => [$old, $value]) {
            Audit::log('Update', 'CPL', 'Changed CPL setting ' . $key . ' from "' . $old . '" to "' . $value . '"', ['key' => $key, 'old' => $old, 'new' => $value]);
        }
        Audit::log('Update', 'CPL', 'Updated CPL settings');

        // the standing approval of the automatic submission was given for one destination: a changed one revokes it and turns the switch off
        $notice = '';
        if (array_intersect(array_keys($changedRestricted), self::REVOKES_APPROVAL) !== []) {
            try {
                if ((new CplAutoSubmissionService())->revokeIfSettingsChanged($userId)) {
                    $notice = ' Automatic CPL submission was switched OFF and its standing approval revoked, because the submission environment, supplier reference, trading name, recipient or SFTP destination changed. Turn it on again on the CPL Dashboard to approve the new settings.';
                }
            } catch (\Throwable $e) {
                error_log('CplSettingController: could not revoke the standing approval: ' . get_class($e));
            }
        }

        Session::flash('success', 'CPL settings saved.' . $notice);
        $this->redirect('/reports/cpl-export/settings');
    }
}
