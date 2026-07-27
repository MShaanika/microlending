<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SocialAnalyticsMetric;
use App\Models\SocialAnalyticsSetting;

class SocialAnalyticsController extends Controller
{
    private SocialAnalyticsSetting $settings;
    private SocialAnalyticsMetric $metrics;

    public function __construct()
    {
        $this->settings = new SocialAnalyticsSetting();
        $this->metrics = new SocialAnalyticsMetric();
    }

    public function index(): void
    {
        Auth::authorize('social_analytics.view');

        $platforms = [];
        foreach ($this->settings->allPlatforms() as $platform) {
            $platform['recent_entries'] = $this->metrics->forSetting((int) $platform['id'], 12);
            $platform['trend'] = $this->metrics->trend((int) $platform['id'], 12);
            $platforms[] = $platform;
        }

        $this->view('social-analytics/index', [
            'title' => 'Social & Web Analytics',
            'platforms' => $platforms,
            'today' => date('Y-m-d'),
        ]);
    }

    public function storeMetric(int $settingId): void
    {
        Auth::authorize('social_analytics.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/social-analytics');
            return;
        }

        $setting = $this->settings->find($settingId);
        if (!$setting) {
            Session::flash('error', 'Unknown platform.');
            $this->redirect('/social-analytics');
            return;
        }

        $entryDate = trim($_POST['entry_date'] ?? '');
        if ($entryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            Session::flash('error', 'Enter a valid entry date.');
            $this->redirect('/social-analytics');
            return;
        }

        $data = [
            'setting_id' => $settingId,
            'entry_date' => $entryDate,
            'metric_1' => (float) ($_POST['metric_1'] ?? 0),
            'metric_2' => (float) ($_POST['metric_2'] ?? 0),
            'metric_3' => (float) ($_POST['metric_3'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'created_by' => Auth::user()['id'] ?? null,
        ];

        $existing = $this->metrics->findByPlatformAndDate($settingId, $entryDate);
        if ($existing) {
            $this->metrics->updateRecord((int) $existing['id'], $data);
            Audit::log('Update', 'Social Analytics', "Updated {$setting['display_name']} entry for {$entryDate}");
        } else {
            $this->metrics->create($data);
            Audit::log('Create', 'Social Analytics', "Logged {$setting['display_name']} entry for {$entryDate}");
        }

        Session::flash('success', "{$setting['display_name']} entry saved.");
        $this->redirect('/social-analytics');
    }

    public function deleteMetric(int $id): void
    {
        Auth::authorize('social_analytics.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/social-analytics');
            return;
        }

        $entry = $this->metrics->find($id);
        if ($entry) {
            $this->metrics->delete($id);
            Audit::log('Delete', 'Social Analytics', "Deleted analytics entry #{$id}");
            Session::flash('success', 'Entry deleted.');
        }

        $this->redirect('/social-analytics');
    }

    public function settingsEdit(): void
    {
        Auth::authorize('social_analytics.manage');

        $this->view('settings/social-analytics/edit', [
            'title' => 'Social & Web Analytics Settings',
            'platforms' => $this->settings->allPlatforms(),
            'errors' => [],
        ]);
    }

    public function settingsUpdate(int $id): void
    {
        Auth::authorize('social_analytics.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/social-analytics');
            return;
        }

        $setting = $this->settings->find($id);
        if (!$setting) {
            Session::flash('error', 'Unknown platform.');
            $this->redirect('/settings/social-analytics');
            return;
        }

        $errors = [];
        $metric1 = trim($_POST['metric_1_label'] ?? '');
        $metric2 = trim($_POST['metric_2_label'] ?? '');
        $metric3 = trim($_POST['metric_3_label'] ?? '');
        if ($metric1 === '' || $metric2 === '' || $metric3 === '') {
            $errors['labels'] = 'All three metric labels are required.';
        }

        if (!empty($errors)) {
            $this->view('settings/social-analytics/edit', [
                'title' => 'Social & Web Analytics Settings',
                'platforms' => $this->settings->allPlatforms(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->settings->updateSettings($id, [
            'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
            'handle_or_property' => trim($_POST['handle_or_property'] ?? '') ?: null,
            'metric_1_label' => $metric1,
            'metric_2_label' => $metric2,
            'metric_3_label' => $metric3,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Social Analytics', "Updated {$setting['display_name']} settings");
        Session::flash('success', "{$setting['display_name']} settings updated.");
        $this->redirect('/settings/social-analytics');
    }
}
