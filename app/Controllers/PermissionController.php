<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Permission;

class PermissionController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $this->view('settings/permissions/index', [
            'title' => 'Permissions',
            'groupedPermissions' => (new Permission())->groupedByModule(),
        ]);
    }
}
