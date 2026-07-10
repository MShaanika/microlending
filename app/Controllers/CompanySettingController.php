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
    private const MAX_LOGO_SIZE = 2 * 1024 * 1024; // 2MB

    private Company $company;

    public function __construct()
    {
        $this->company = new Company();
    }

    public function edit(): void
    {
        Auth::requireLogin();
        $this->view('settings/company/edit', [
            'title' => 'Company Settings',
            'company' => $this->company->primary(),
            'errors' => [],
        ]);
    }

    public function update(): void
    {
        Auth::requireLogin();

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

        $logoPath = $company['logo'];
        $logoFile = $_FILES['logo'] ?? null;
        if ($logoFile && $logoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($logoFile['error'] !== UPLOAD_ERR_OK) {
                $errors['logo'] = 'Logo upload failed. Please try again.';
            } elseif ($logoFile['size'] > self::MAX_LOGO_SIZE) {
                $errors['logo'] = 'Logo is too large (max 2MB).';
            } else {
                $ext = strtolower(pathinfo($logoFile['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_LOGO_EXTENSIONS, true)) {
                    $errors['logo'] = 'Only JPG, PNG and SVG logos are accepted.';
                }
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
            // Unlike borrower KYC documents, the logo is a public brand
            // asset displayed on every page -- store it under the
            // web-servable public/ dir rather than storage/, so it can be
            // linked to directly instead of streamed through a controller.
            $targetDir = PUBLIC_PATH . '/uploads/company';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $ext = strtolower(pathinfo($logoFile['name'], PATHINFO_EXTENSION));
            $storedName = 'logo_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($logoFile['tmp_name'], $targetDir . '/' . $storedName)) {
                $logoPath = 'uploads/company/' . $storedName;
            }
        }

        $this->company->updateRecord((int) $company['id'], [
            'company_name' => trim($_POST['company_name']),
            'registration_no' => trim($_POST['registration_no'] ?? '') ?: null,
            'namfisa_license_no' => trim($_POST['namfisa_license_no'] ?? '') ?: null,
            'tax_no' => trim($_POST['tax_no'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'address' => trim($_POST['address'] ?? '') ?: null,
            'logo' => $logoPath,
        ]);

        Audit::log('Update', 'Admin', 'Updated company settings');
        Session::flash('success', 'Company settings updated.');
        $this->redirect('/settings/company');
    }
}
