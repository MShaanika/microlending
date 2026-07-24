<?php
namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Auth;
use App\Core\Security;
use App\Core\Session;
use App\Models\User;
use App\Services\EmailSenderService;
use App\Services\TurnstileService;

class AuthController extends Controller
{
    /** Reset links are valid for this many minutes after being requested. */
    private const RESET_TOKEN_TTL_MINUTES = 60;

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
        if (!TurnstileService::verify($_POST['cf-turnstile-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)) {
            Session::flash('error', 'Please complete the verification challenge.');
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

    public function showForgotForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
            return;
        }
        $this->view('auth/forgot_password', ['title' => 'Forgot Password']);
    }

    public function sendResetLink(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/forgot-password');
            return;
        }
        if (!TurnstileService::verify($_POST['cf-turnstile-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)) {
            Session::flash('error', 'Please complete the verification challenge.');
            $this->redirect('/forgot-password');
            return;
        }

        $login = trim($_POST['login'] ?? '');
        $users = new User();
        $user = $login !== '' ? $users->findByEmailOrUsername($login) : null;

        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::RESET_TOKEN_TTL_MINUTES . ' minutes'));
            $users->setResetToken((int) $user['id'], $tokenHash, $expiresAt);

            $resetLink = full_url('/reset-password/' . $rawToken);
            $message = "Hello {$user['name']},\n\nA password reset was requested for your account. "
                . "Click the link below to choose a new password. This link expires in " . self::RESET_TOKEN_TTL_MINUTES . " minutes.\n\n"
                . $resetLink . "\n\nIf you didn't request this, you can safely ignore this email.";

            $result = EmailSenderService::send((string) $user['email'], 'Reset Your Password', $message, (string) $user['name']);

            Audit::log('Create', 'Security', 'Password reset requested for ' . $user['email'] . ($result['success'] ? '' : ' (email send failed: ' . $result['error'] . ')'));
        } else {
            // Same audit trail either way, without revealing to the caller whether the account exists.
            Audit::log('Create', 'Security', 'Password reset requested for unknown login "' . $login . '"');
        }

        // Deliberately identical outcome whether or not the account exists --
        // never let this endpoint be used to enumerate valid usernames/emails.
        Session::flash('success', 'If an account exists for that email or username, we\'ve sent password reset instructions.');
        $this->redirect('/login');
    }

    public function showResetForm(string $token): void
    {
        $users = new User();
        $user = $users->findByValidResetToken(hash('sha256', $token));

        if (!$user) {
            Session::flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
            $this->redirect('/forgot-password');
            return;
        }

        $this->view('auth/reset_password', [
            'title' => 'Reset Password',
            'token' => $token,
            'errors' => [],
        ]);
    }

    public function resetPassword(string $token): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reset-password/' . $token);
            return;
        }

        $users = new User();
        $user = $users->findByValidResetToken(hash('sha256', $token));

        if (!$user) {
            Session::flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
            $this->redirect('/forgot-password');
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors['password'] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            $this->view('auth/reset_password', [
                'title' => 'Reset Password',
                'token' => $token,
                'errors' => $errors,
            ]);
            return;
        }

        $users->resetPassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        $users->clearResetToken((int) $user['id']);

        Audit::log('Update', 'Security', 'Password reset completed for ' . $user['email']);
        Session::flash('success', 'Your password has been reset. You can now log in.');
        $this->redirect('/login');
    }
}
