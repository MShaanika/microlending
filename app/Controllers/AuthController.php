<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Security;
use App\Core\Session;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) $this->redirect('/dashboard');
        $this->view('auth/login', ['title' => 'Login']);
    }
    public function login(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/login');
        }
        $login = trim($_POST['login'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attempt($login, $password)) $this->redirect('/dashboard');
        Session::flash('error', 'Invalid username/email or password.');
        $this->redirect('/login');
    }
    public function logout(): void
    {
        Auth::logout();
        header('Location: ' . url('/login'));
        exit;
    }
}
