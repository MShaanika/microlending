<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SecurityRule;
use App\Models\SystemSetting;

class SecurityRuleController extends Controller
{
    private SecurityRule $rules;
    private SystemSetting $settings;

    public function __construct()
    {
        $this->rules = new SecurityRule();
        $this->settings = new SystemSetting();
    }

    public function index(): void
    {
        Auth::authorize('security.view');

        $this->view('security/rules/index', [
            'title' => 'Security Rules',
            'rules' => $this->rules->allWithUpdater(),
            'alertRecipientEmail' => $this->settings->get('security_alert_recipient_email', ''),
        ]);
    }

    /** Edits one rule's admin-configurable numbers -- rule types themselves stay code-defined in Phase 1, only the thresholds/severity/response are data. */
    public function update(string $id): void
    {
        Auth::authorize('security.rules.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/security/rules');
            return;
        }

        $rule = $this->rules->find($id);
        if (!$rule) {
            Session::flash('error', 'Rule not found.');
            $this->redirect('/security/rules');
            return;
        }

        $threshold = max(1, (int) ($_POST['threshold_count'] ?? $rule['threshold_count']));
        $window = max(1, (int) ($_POST['window_minutes'] ?? $rule['window_minutes']));
        $severity = in_array($_POST['severity'] ?? '', ['Low', 'Medium', 'High', 'Critical'], true) ? $_POST['severity'] : $rule['severity'];
        $scoreDelta = (int) ($_POST['risk_score_delta'] ?? $rule['risk_score_delta']);
        $responseAction = in_array($_POST['response_action'] ?? '', ['none', 'rate_limit_source', 'lock_account'], true) ? $_POST['response_action'] : $rule['response_action'];
        $responseDuration = ($_POST['response_duration_minutes'] ?? '') !== '' ? max(1, (int) $_POST['response_duration_minutes']) : null;
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $this->rules->updateConfig($id, [
            'threshold_count' => $threshold,
            'window_minutes' => $window,
            'severity' => $severity,
            'risk_score_delta' => $scoreDelta,
            'response_action' => $responseAction,
            'response_duration_minutes' => $responseDuration,
            'is_active' => $isActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log(
            'Update',
            'Security',
            'Updated security rule ' . $rule['rule_key'] . ' (threshold ' . $rule['threshold_count'] . '->' . $threshold . ', active ' . $rule['is_active'] . '->' . $isActive . ')',
            ['rule_id' => $id]
        );

        Session::flash('success', 'Rule updated.');
        $this->redirect('/security/rules');
    }

    /** The one settings field folded into this page per the Phase 1 plan, rather than a separate Security Settings nav item. */
    public function updateSettings(): void
    {
        Auth::authorize('security.rules.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/security/rules');
            return;
        }

        $email = trim((string) ($_POST['security_alert_recipient_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Enter a valid email address.');
            $this->redirect('/security/rules');
            return;
        }

        $db = \App\Core\Database::connection();
        $db->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = 'security_alert_recipient_email'")
            ->execute([$email, Auth::user()['id'] ?? null]);

        Audit::log('Update', 'Security', 'Changed security alert recipient email');
        Session::flash('success', 'Security notification settings updated.');
        $this->redirect('/security/rules');
    }
}
