<?php
namespace App\Core;

class Permission
{
    public static function can(string $permission): bool
    {
        $perms = Session::get('user.permissions', []);
        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }
}
