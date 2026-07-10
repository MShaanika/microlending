<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\PortalAuth;
use App\Core\Security;
use App\Core\Session;

class PortalAuthController extends Controller
{
    public function showLogin(): void
    {
        if (PortalAuth::check()) {
            $this->redirect('/portal/dashboard');
        }
        $this->view('portal/login', ['title' => 'Borrower Portal Login']);
    }

    public function login(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (PortalAuth::attempt($username, $password)) {
            $this->redirect('/portal/dashboard');
        }

        Session::flash('error', 'Invalid username or password.');
        $this->redirect('/portal/login');
    }

    public function logout(): void
    {
        PortalAuth::logout();
        header('Location: ' . url('/portal/login'));
        exit;
    }
}
