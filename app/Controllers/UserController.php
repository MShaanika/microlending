<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;

class UserController extends Controller
{
    private const USER_TYPES = ['Super Admin', 'Admin', 'Manager', 'Loan Officer', 'Cashier', 'Accountant', 'Collector'];

    private User $users;
    private Role $roles;
    private Branch $branches;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
        $this->branches = new Branch();
    }

    public function index(): void
    {
        Auth::authorize('admin.users');
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('settings/users/index', [
            'title' => 'Users',
            'users' => $this->users->paginated($search, $status),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('admin.users');
        $this->view('settings/users/create', [
            'title' => 'Add User',
            'userTypes' => self::USER_TYPES,
            'roles' => $this->roles->allRoles(),
            'branches' => $this->branches->all(),
            'selectedRoleIds' => [],
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('admin.users');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/users/create');
            return;
        }

        $errors = $this->validate($_POST, null);
        $roleIds = array_map('intval', $_POST['role_ids'] ?? []);

        if (!empty($errors)) {
            $this->view('settings/users/create', [
                'title' => 'Add User',
                'userTypes' => self::USER_TYPES,
                'roles' => $this->roles->allRoles(),
                'branches' => $this->branches->all(),
                'selectedRoleIds' => $roleIds,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $userId = $this->users->create([
            'branch_id' => $_POST['branch_id'] ?: null,
            'name' => trim($_POST['name']),
            'username' => trim($_POST['username']),
            'email' => trim($_POST['email']),
            'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'user_type' => $_POST['user_type'],
            'is_active' => 1,
        ]);

        $this->users->setRoles($userId, $roleIds);

        Audit::log('Create', 'Admin', 'Created user ' . $_POST['username']);
        Session::flash('success', 'User created.');
        $this->redirect('/settings/users');
    }

    public function edit(string $id): void
    {
        Auth::authorize('admin.users');
        $user = $this->users->find((int) $id);

        if (!$user) {
            Session::flash('error', 'User not found.');
            $this->redirect('/settings/users');
            return;
        }

        $this->view('settings/users/edit', [
            'title' => 'Edit User',
            'user' => $user,
            'userTypes' => self::USER_TYPES,
            'roles' => $this->roles->allRoles(),
            'branches' => $this->branches->all(),
            'selectedRoleIds' => $this->users->roleIds((int) $id),
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('admin.users');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/users/' . $id . '/edit');
            return;
        }

        $user = $this->users->find($id);
        if (!$user) {
            Session::flash('error', 'User not found.');
            $this->redirect('/settings/users');
            return;
        }

        $errors = $this->validate($_POST, $id);
        $roleIds = array_map('intval', $_POST['role_ids'] ?? []);

        if (empty($errors)) {
            $superAdminRoleId = $this->roles->idByName('Super Admin');
            $losingSuperAdmin = $superAdminRoleId
                && in_array($superAdminRoleId, $this->users->roleIds($id), true)
                && !in_array($superAdminRoleId, $roleIds, true);
            if ($losingSuperAdmin && $this->users->activeCountForRole($superAdminRoleId) <= 1) {
                $errors['role_ids'] = 'Cannot remove the last active Super Admin.';
            }
        }

        if (!empty($errors)) {
            $this->view('settings/users/edit', [
                'title' => 'Edit User',
                'user' => array_merge($user, $_POST),
                'userTypes' => self::USER_TYPES,
                'roles' => $this->roles->allRoles(),
                'branches' => $this->branches->all(),
                'selectedRoleIds' => $roleIds,
                'errors' => $errors,
            ]);
            return;
        }

        $this->users->updateRecord($id, [
            'branch_id' => $_POST['branch_id'] ?: null,
            'name' => trim($_POST['name']),
            'username' => trim($_POST['username']),
            'email' => trim($_POST['email']),
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'user_type' => $_POST['user_type'],
        ]);

        $this->users->setRoles($id, $roleIds);

        Audit::log('Update', 'Admin', 'Updated user #' . $id);
        Session::flash('success', 'User updated.');
        $this->redirect('/settings/users');
    }

    public function toggleActive(string $id): void
    {
        Auth::authorize('admin.users');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/users');
            return;
        }

        $user = $this->users->find($id);
        if (!$user) {
            Session::flash('error', 'User not found.');
            $this->redirect('/settings/users');
            return;
        }

        if ((int) $user['is_active'] === 1) {
            $superAdminRoleId = $this->roles->idByName('Super Admin');
            $isSuperAdmin = $superAdminRoleId && in_array($superAdminRoleId, $this->users->roleIds($id), true);
            if ($isSuperAdmin && $this->users->activeCountForRole($superAdminRoleId) <= 1) {
                Session::flash('error', 'Cannot deactivate the last active Super Admin.');
                $this->redirect('/settings/users');
                return;
            }
            if ((int) ($user['id']) === (int) (Auth::user()['id'] ?? 0)) {
                Session::flash('error', 'You cannot deactivate your own account.');
                $this->redirect('/settings/users');
                return;
            }
        }

        $newStatus = (int) $user['is_active'] === 1 ? 0 : 1;
        $this->users->updateRecord($id, ['is_active' => $newStatus]);

        Audit::log($newStatus ? 'Activate' : 'Deactivate', 'Admin', ($newStatus ? 'Activated' : 'Deactivated') . ' user #' . $id);
        Session::flash('success', 'User ' . ($newStatus ? 'activated' : 'deactivated') . '.');
        $this->redirect('/settings/users');
    }

    public function resetPasswordForm(string $id): void
    {
        Auth::authorize('admin.users');
        $user = $this->users->find((int) $id);

        if (!$user) {
            Session::flash('error', 'User not found.');
            $this->redirect('/settings/users');
            return;
        }

        $this->view('settings/users/reset_password', [
            'title' => 'Reset Password - ' . $user['name'],
            'user' => $user,
            'errors' => [],
        ]);
    }

    public function resetPassword(string $id): void
    {
        Auth::authorize('admin.users');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/users/' . $id . '/reset-password');
            return;
        }

        $user = $this->users->find($id);
        if (!$user) {
            Session::flash('error', 'User not found.');
            $this->redirect('/settings/users');
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
            $this->view('settings/users/reset_password', [
                'title' => 'Reset Password - ' . $user['name'],
                'user' => $user,
                'errors' => $errors,
            ]);
            return;
        }

        $this->users->resetPassword($id, password_hash($password, PASSWORD_DEFAULT));

        Audit::log('Update', 'Admin', 'Reset password for user #' . $id);
        Session::flash('success', 'Password reset.');
        $this->redirect('/settings/users');
    }

    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];
        foreach (['name', 'username', 'email', 'user_type'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (empty($errors['username']) && $this->users->usernameExists(trim($data['username']), $excludeId)) {
            $errors['username'] = 'This username is already taken.';
        }
        if (empty($errors['email']) && $this->users->emailExists(trim($data['email']), $excludeId)) {
            $errors['email'] = 'This email is already registered.';
        }
        if ($excludeId === null) {
            if (strlen((string) ($data['password'] ?? '')) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            }
        }
        return $errors;
    }
}
