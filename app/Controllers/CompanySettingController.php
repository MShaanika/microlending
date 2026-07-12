<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Company;

class CompanySettingController extends Controller
{
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg'];
    private const ALLOWED_FAVICON_EXTENSIONS = ['jpg', 'jpeg', 'png', 'ico', 'svg'];
    private const MAX_LOGO_SIZE = 2 * 1024 * 1024; // 2MB

    private Company $company;

    public function __construct()
    {
        $this->company = new Company();
    }

    public function edit(): void
    {
        Auth::authorize('admin.company');
        $this->view('settings/company/edit', [
            'title' => 'Company Settings',
            'company' => $this->company->primary(),
            'errors' => [],
        ]);
    }

    public function update(): void
    {
        Auth::authorize('admin.company');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/company');
            return;
        }

        $company = $this->company->primary();
        if (!$company) {
            Session::flash('error', 'No company record found to update.');
            $this->redirect('/settings/company');
            return;
        }

        $errors = [];
        if (trim($_POST['company_name'] ?? '') === '') {
            $errors['company_name'] = 'Company name is required.';
        }
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $primaryColor = trim($_POST['primary_color'] ?? '');
        if ($primaryColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
            $errors['primary_color'] = 'Enter a valid hex color (e.g. #25a9e0).';
        }

        $logoPath = $company['logo'];
        $logoFile = $_FILES['logo'] ?? null;
        if ($logoFile && $logoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($logoFile['error'] !== UPLOAD_ERR_OK) {
                $errors['logo'] = 'Logo upload failed. Please try again.';
            } elseif ($logoFile['size'] > self::MAX_LOGO_SIZE) {
                $errors['logo'] = 'Logo is too large (max 2MB).';
            } elseif (!in_array(strtolower(pathinfo($logoFile['name'], PATHINFO_EXTENSION)), self::ALLOWED_LOGO_EXTENSIONS, true)) {
                $errors['logo'] = 'Only JPG, PNG and SVG logos are accepted.';
            }
        }

        $faviconPath = $company['favicon'];
        $faviconFile = $_FILES['favicon'] ?? null;
        if ($faviconFile && $faviconFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($faviconFile['error'] !== UPLOAD_ERR_OK) {
                $errors['favicon'] = 'Favicon upload failed. Please try again.';
            } elseif ($faviconFile['size'] > self::MAX_LOGO_SIZE) {
                $errors['favicon'] = 'Favicon is too large (max 2MB).';
            } elseif (!in_array(strtolower(pathinfo($faviconFile['name'], PATHINFO_EXTENSION)), self::ALLOWED_FAVICON_EXTENSIONS, true)) {
                $errors['favicon'] = 'Only JPG, PNG, ICO and SVG favicons are accepted.';
            }
        }

        if (!empty($errors)) {
            $this->view('settings/company/edit', [
                'title' => 'Company Settings',
                'company' => array_merge($company, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        if ($logoFile && $logoFile['error'] === UPLOAD_ERR_OK) {
            $stored = $this->storeUpload($logoFile, 'logo');
            if ($stored) {
                $logoPath = $stored;
            }
        }
        if ($faviconFile && $faviconFile['error'] === UPLOAD_ERR_OK) {
            $stored = $this->storeUpload($faviconFile, 'favicon');
            if ($stored) {
                $faviconPath = $stored;
            }
        }

        $this->company->updateRecord((int) $company['id'], [
            'company_name' => trim($_POST['company_name']),
            'brand_name' => trim($_POST['brand_name'] ?? '') ?: null,
            'registration_no' => trim($_POST['registration_no'] ?? '') ?: null,
            'namfisa_license_no' => trim($_POST['namfisa_license_no'] ?? '') ?: null,
            'tax_no' => trim($_POST['tax_no'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'address' => trim($_POST['address'] ?? '') ?: null,
            'logo' => $logoPath,
            'primary_color' => $primaryColor ?: '#25a9e0',
            'footer_tagline' => trim($_POST['footer_tagline'] ?? '') ?: null,
            'favicon' => $faviconPath,
        ]);

        Audit::log('Update', 'Admin', 'Updated company settings');
        Session::flash('success', 'Company settings updated.');
        $this->redirect('/settings/company');
    }

    /**
     * Both logo and favicon are public brand assets displayed on every
     * page -- stored under the web-servable public/ dir rather than
     * storage/, so they can be linked to directly instead of streamed
     * through a controller (unlike borrower KYC documents).
     */
    private function storeUpload(array $file, string $prefix): ?string
    {
        $targetDir = PUBLIC_PATH . '/uploads/company';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $storedName = $prefix . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $targetDir . '/' . $storedName)) {
            return 'uploads/company/' . $storedName;
        }
        return null;
    }
}
