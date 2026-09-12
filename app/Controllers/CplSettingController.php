<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CplSetting;

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
        'account_type_one_month', 'account_type_multi_month',
    ];

    private CplSetting $settings;

    public function __construct()
    {
        $this->settings = new CplSetting();
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

        foreach (self::TEXT_FIELDS as $key) {
            if (array_key_exists($key, $_POST)) {
                $this->settings->set($key, trim((string) $_POST[$key]), $userId);
            }
        }

        $this->settings->set('account_type_mapping_confirmed', !empty($_POST['account_type_mapping_confirmed']) ? 'yes' : 'no', $userId);

        Audit::log('Update', 'CPL', 'Updated CPL settings');

        Session::flash('success', 'CPL settings saved.');
        $this->redirect('/reports/cpl-export/settings');
    }
}
