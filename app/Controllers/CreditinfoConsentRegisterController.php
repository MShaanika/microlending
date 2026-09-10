<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\CreditBureauConsent;

/**
 * Read-only register over credit_bureau_consents -- the same consent table
 * the existing CBS module (CreditinfoAssessmentController) writes to.
 * Nothing here writes to that table or changes CBS behaviour. CBS-only:
 * Public Defaults does NOT write to credit_bureau_consents -- its own
 * borrower notice/evidence is captured separately, in
 * creditinfo_public_default_notices (see CreditinfoPublicDefaultNotice and
 * each default's own detail screen), which this register does not show.
 */
class CreditinfoConsentRegisterController extends Controller
{
    public function index(): void
    {
        Auth::authorize('applications.credit_check');
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->view('creditinfo/consent_register/index', [
            'title' => 'Creditinfo Consent Register',
            'result' => (new CreditBureauConsent())->paginated($page),
            'page' => $page,
        ]);
    }
}
