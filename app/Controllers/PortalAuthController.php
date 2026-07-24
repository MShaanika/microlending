<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\PortalAuth;
use App\Core\Security;
use App\Core\Session;
use App\Models\PortalUser;
use App\Services\EmailSenderService;
use App\Services\TurnstileService;

class PortalAuthController extends Controller
{
    /** Reset links are valid for this many minutes after being requested. */
    private const RESET_TOKEN_TTL_MINUTES = 60;

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
        if (!TurnstileService::verify($_POST['cf-turnstile-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)) {
            Session::flash('error', 'Please complete the verification challenge.');
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

    public function showForgotForm(): void
    {
        if (PortalAuth::check()) {
            $this->redirect('/portal/dashboard');
            return;
        }
        $this->view('portal/forgot_password', ['title' => 'Forgot Password']);
    }

    public function sendResetLink(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/forgot-password');
            return;
        }
        if (!TurnstileService::verify($_POST['cf-turnstile-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)) {
            Session::flash('error', 'Please complete the verification challenge.');
            $this->redirect('/portal/forgot-password');
            return;
        }

        $login = trim($_POST['login'] ?? '');
        $portalUsers = new PortalUser();
        $user = $login !== '' ? $portalUsers->findByUsernameOrEmail($login) : null;

        if ($user && !empty($user['email'])) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::RESET_TOKEN_TTL_MINUTES . ' minutes'));
            $portalUsers->setResetToken((int) $user['id'], $tokenHash, $expiresAt);

            $resetLink = full_url('/portal/reset-password/' . $rawToken);
            $message = "Hello,\n\nA password reset was requested for your borrower portal account. "
                . "Click the link below to choose a new password. This link expires in " . self::RESET_TOKEN_TTL_MINUTES . " minutes.\n\n"
                . $resetLink . "\n\nIf you didn't request this, you can safely ignore this email.";

            $result = EmailSenderService::send((string) $user['email'], 'Reset Your Borrower Portal Password', $message);

            Audit::log('Create', 'Borrower Portal', 'Password reset requested for portal user #' . $user['id'] . ($result['success'] ? '' : ' (email send failed: ' . $result['error'] . ')'));
        } else {
            // Same audit trail either way, without revealing to the caller whether the account exists.
            Audit::log('Create', 'Borrower Portal', 'Password reset requested for unknown login "' . $login . '"');
        }

        // Deliberately identical outcome whether or not the account exists,
        // and whether or not it has an email on file -- never let this
        // endpoint be used to enumerate valid usernames/emails.
        Session::flash('success', 'If an account exists for that username or email, we\'ve sent password reset instructions.');
        $this->redirect('/portal/login');
    }

    public function showResetForm(string $token): void
    {
        $portalUsers = new PortalUser();
        $user = $portalUsers->findByValidResetToken(hash('sha256', $token));

        if (!$user) {
            Session::flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
            $this->redirect('/portal/forgot-password');
            return;
        }

        $this->view('portal/reset_password', [
            'title' => 'Reset Password',
            'token' => $token,
            'errors' => [],
        ]);
    }

    public function resetPassword(string $token): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/reset-password/' . $token);
            return;
        }

        $portalUsers = new PortalUser();
        $user = $portalUsers->findByValidResetToken(hash('sha256', $token));

        if (!$user) {
            Session::flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
            $this->redirect('/portal/forgot-password');
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
            $this->view('portal/reset_password', [
                'title' => 'Reset Password',
                'token' => $token,
                'errors' => $errors,
            ]);
            return;
        }

        $portalUsers->resetPassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        $portalUsers->clearResetToken((int) $user['id']);

        Audit::log('Update', 'Borrower Portal', 'Password reset completed for portal user #' . $user['id']);
        Session::flash('success', 'Your password has been reset. You can now log in.');
        $this->redirect('/portal/login');
    }
}
