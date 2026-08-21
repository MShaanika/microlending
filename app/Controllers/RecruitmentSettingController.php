<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentSetting;

class RecruitmentSettingController extends Controller
{
    /** Settings this screen manages -- each is plain text/HTML shown on the public careers pages. */
    private const KEYS = ['about_company', 'application_tips', 'what_happens_next', 'need_help', 'tracking_faq'];

    private RecruitmentSetting $settings;

    public function __construct()
    {
        $this->settings = new RecruitmentSetting();
    }

    public function edit(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/settings/edit', [
            'title' => 'Recruitment System Setup',
            'settings' => $this->settings->allSettings(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/settings');
            return;
        }

        foreach (self::KEYS as $key) {
            $this->settings->set($key, trim($_POST[$key] ?? '') ?: null);
        }

        Audit::log('Update', 'Recruitment', 'Updated recruitment careers page settings');
        Session::flash('success', 'Settings saved.');
        $this->redirect('/recruitment/settings');
    }
}
