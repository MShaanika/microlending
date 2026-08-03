<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\User;

/**
 * Self-service "My Profile" -- every logged-in user manages their own name/
 * email/phone and password here. Deliberately does not expose username,
 * user_type, branch_id, or roles: those are account-structure/permission
 * fields and stay admin-only via UserController, so a user can never
 * self-escalate by editing their own profile.
 */
class ProfileController extends Controller
{
    private User $users;

    public function __construct()
    {
        $this->users = new User();
    }

    public function edit(): void
    {
        Auth::requireLogin();
        $user = $this->users->find((int) Auth::user()['id']);

        $this->view('profile/edit', [
            'title' => 'My Profile',
            'user' => $user,
            'old' => [],
            'errors' => [],
            'passwordErrors' => [],
        ]);
    }

    public function update(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::user()['id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/profile');
            return;
        }

        $user = $this->users->find($userId);
        $errors = $this->validate($_POST, $userId);

        if (!empty($errors)) {
            $this->view('profile/edit', [
                'title' => 'My Profile',
                'user' => array_merge($user, $_POST),
                'old' => $_POST,
                'errors' => $errors,
                'passwordErrors' => [],
            ]);
            return;
        }

        $this->users->updateRecord($userId, [
            'name' => trim($_POST['name']),
            'email' => trim($_POST['email']),
            'phone' => trim($_POST['phone'] ?? '') ?: null,
        ]);

        // Session carries its own snapshot of name/email (topbar, sidebar) --
        // refresh it so the change shows immediately instead of at next login.
        Session::put('user', array_merge(Auth::user(), [
            'name' => trim($_POST['name']),
            'email' => trim($_POST['email']),
        ]));

        Audit::log('Update', 'Profile', 'Updated own profile details');
        Session::flash('success', 'Profile updated.');
        $this->redirect('/profile');
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::user()['id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/profile');
            return;
        }

        $user = $this->users->find($userId);
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];

        if (!password_verify($current, $user['password'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'New password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors['password'] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            $this->view('profile/edit', [
                'title' => 'My Profile',
                'user' => $user,
                'old' => [],
                'errors' => [],
                'passwordErrors' => $errors,
            ]);
            return;
        }

        $this->users->resetPassword($userId, password_hash($password, PASSWORD_DEFAULT));

        Audit::log('Update', 'Profile', 'Changed own password');
        Session::flash('success', 'Password changed.');
        $this->redirect('/profile');
    }

    private function validate(array $data, int $userId): array
    {
        $errors = [];
        foreach (['name', 'email'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (empty($errors['email']) && $this->users->emailExists(trim($data['email']), $userId)) {
            $errors['email'] = 'This email is already registered.';
        }
        return $errors;
    }
}
