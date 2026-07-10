<?php

namespace App\Models;

use App\Core\Model;

class Role extends Model
{
    public function allRoles(): array
    {
        return $this->all(
            "SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.role_name"
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM roles WHERE id = ?", [$id]);
    }

    public function idByName(string $roleName): ?int
    {
        $id = $this->scalar("SELECT id FROM roles WHERE role_name = ?", [$roleName]);
        return $id ? (int) $id : null;
    }

    public function nameExists(string $roleName, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM roles WHERE role_name = ? AND id != ?", [$roleName, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM roles WHERE role_name = ?", [$roleName]);
    }

    public function create(array $data): int
    {
        return $this->insert('roles', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('roles', $data, 'id', $id);
    }

    public function permissionIds(int $roleId): array
    {
        return array_map('intval', array_column(
            $this->query("SELECT permission_id FROM role_permissions WHERE role_id = ?", [$roleId])->fetchAll(),
            'permission_id'
        ));
    }

    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->query("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
        foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
            $this->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function userCount(int $roleId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM user_roles WHERE role_id = ?", [$roleId]);
    }
}
