<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CreditinfoPublicDefaultSetting;

/**
 * Item 14's Public Default Settings + item 5's eligibility configuration +
 * item 15's tariff configuration -- one screen, since all three are plain
 * admin-editable values on the same creditinfo_public_default_settings
 * table. Item 14's hard rule is enforced here, not just displayed:
 * public_defaults_enabled can never be turned "on" while
 * vendor_documentation_received or vendor_mapping_confirmed is still "no".
 */
class CreditinfoPublicDefaultSettingController extends Controller
{
    private const BOOLEAN_FIELDS = [
        'vendor_documentation_received', 'vendor_mapping_confirmed', 'borrower_notice_required',
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
            'canEnable' => $this->settings->isReadyForProductionSubmission(),
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

        // Item 14: production submission is gated here, not just on the
        // readiness panel -- an admin cannot flip public_defaults_enabled
        // to "on" while either vendor confirmation flag is still "no",
        // regardless of what they submit in the form.
        $vendorDocsReceived = !empty($_POST['vendor_documentation_received']) ? 'yes' : 'no';
        $vendorMappingConfirmed = !empty($_POST['vendor_mapping_confirmed']) ? 'yes' : 'no';
        $requestedEnabled = !empty($_POST['public_defaults_enabled']);

        if ($requestedEnabled && ($vendorDocsReceived !== 'yes' || $vendorMappingConfirmed !== 'yes')) {
            Session::flash('error', 'Public Defaults cannot be enabled until Vendor Documentation Received AND Vendor Mapping Confirmed are both YES.');
            $this->redirect('/creditinfo/public-defaults/settings');
            return;
        }

        $this->settings->set('vendor_documentation_received', $vendorDocsReceived, $userId);
        $this->settings->set('vendor_mapping_confirmed', $vendorMappingConfirmed, $userId);
        $this->settings->set('public_defaults_enabled', $requestedEnabled ? 'on' : 'off', $userId);
        $this->settings->set('borrower_notice_required', !empty($_POST['borrower_notice_required']) ? 'yes' : 'no', $userId);

        foreach (['api_status', 'listing_endpoint', 'removal_endpoint', 'api_version',
                  'minimum_days_in_arrears', 'minimum_outstanding_balance', 'notice_waiting_period_days',
                  'listing_fee', 'removal_fee', 'vat_rate', 'tariff_effective_date'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $this->settings->set($key, trim((string) $_POST[$key]), $userId);
            }
        }

        Audit::log('Update', 'Creditinfo', 'Updated Public Defaults settings', [
            'public_defaults_enabled' => $requestedEnabled ? 'on' : 'off',
            'vendor_documentation_received' => $vendorDocsReceived,
            'vendor_mapping_confirmed' => $vendorMappingConfirmed,
        ]);

        Session::flash('success', 'Settings saved.');
        $this->redirect('/creditinfo/public-defaults/settings');
    }
}
