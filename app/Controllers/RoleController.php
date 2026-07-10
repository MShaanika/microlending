<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Permission;
use App\Models\Role;

class RoleController extends Controller
{
    private Role $roles;
    private Permission $permissions;

    public function __construct()
    {
        $this->roles = new Role();
        $this->permissions = new Permission();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $this->view('settings/roles/index', [
            'title' => 'Roles',
            'roles' => $this->roles->allRoles(),
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $this->view('settings/roles/create', [
            'title' => 'Add Role',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/roles/create');
            return;
        }

        $roleName = trim($_POST['role_name'] ?? '');
        $errors = [];
        if ($roleName === '') {
            $errors['role_name'] = 'Name is required.';
        } elseif ($this->roles->nameExists($roleName)) {
            $errors['role_name'] = 'A role with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('settings/roles/create', [
                'title' => 'Add Role',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $roleId = $this->roles->create([
            'role_name' => $roleName,
            'description' => trim($_POST['description'] ?? '') ?: null,
        ]);

        Audit::log('Create', 'Admin', 'Created role ' . $roleName);
        Session::flash('success', 'Role created. Now assign its permissions.');
        $this->redirect('/settings/roles/' . $roleId . '/permissions');
    }

    public function permissions(string $id): void
    {
        Auth::requireLogin();
        $role = $this->roles->find((int) $id);

        if (!$role) {
            Session::flash('error', 'Role not found.');
            $this->redirect('/settings/roles');
            return;
        }

        $this->view('settings/roles/permissions', [
            'title' => 'Permissions - ' . $role['role_name'],
            'role' => $role,
            'groupedPermissions' => $this->permissions->groupedByModule(),
            'selectedPermissionIds' => $this->roles->permissionIds((int) $id),
        ]);
    }

    public function updatePermissions(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/roles/' . $id . '/permissions');
            return;
        }

        $role = $this->roles->find($id);
        if (!$role) {
            Session::flash('error', 'Role not found.');
            $this->redirect('/settings/roles');
            return;
        }

        $permissionIds = array_map('intval', $_POST['permission_ids'] ?? []);
        $this->roles->setPermissions($id, $permissionIds);

        Audit::log('Update', 'Admin', 'Updated permissions for role ' . $role['role_name'] . ' (' . count($permissionIds) . ' assigned)');
        Session::flash('success', 'Permissions updated.');
        $this->redirect('/settings/roles');
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $role = $this->roles->find((int) $id);

        if (!$role) {
            Session::flash('error', 'Role not found.');
            $this->redirect('/settings/roles');
            return;
        }

        $this->view('settings/roles/edit', [
            'title' => 'Edit Role',
            'role' => $role,
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/roles/' . $id . '/edit');
            return;
        }

        $role = $this->roles->find($id);
        if (!$role) {
            Session::flash('error', 'Role not found.');
            $this->redirect('/settings/roles');
            return;
        }

        $roleName = trim($_POST['role_name'] ?? '');
        $errors = [];
        if ($roleName === '') {
            $errors['role_name'] = 'Name is required.';
        } elseif ($this->roles->nameExists($roleName, $id)) {
            $errors['role_name'] = 'A role with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('settings/roles/edit', [
                'title' => 'Edit Role',
                'role' => array_merge($role, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->roles->updateRecord($id, [
            'role_name' => $roleName,
            'description' => trim($_POST['description'] ?? '') ?: null,
        ]);

        Audit::log('Update', 'Admin', 'Updated role #' . $id);
        Session::flash('success', 'Role updated.');
        $this->redirect('/settings/roles');
    }
}
