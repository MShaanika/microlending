<?php

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    public function paginated(string $search = '', string $status = ''): array
    {
        $sql = "SELECT u.*, br.branch_name,
                    (SELECT GROUP_CONCAT(r.role_name SEPARATOR ', ')
                     FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = u.id) AS role_names
                FROM users u
                LEFT JOIN branches br ON br.id = u.branch_id
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $sql .= " AND u.is_active = ?";
            $params[] = $status === 'active' ? 1 : 0;
        }

        $sql .= " ORDER BY u.name";

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT u.*, br.branch_name FROM users u LEFT JOIN branches br ON br.id = u.branch_id WHERE u.id = ?",
            [$id]
        );
    }

    public function roleIds(int $userId): array
    {
        return array_map('intval', array_column(
            $this->all("SELECT role_id FROM user_roles WHERE user_id = ?", [$userId]),
            'role_id'
        ));
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM users WHERE username = ? AND id != ?", [$username, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM users WHERE username = ?", [$username]);
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM users WHERE email = ? AND id != ?", [$email, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM users WHERE email = ?", [$email]);
    }

    public function create(array $data): int
    {
        return $this->insert('users', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('users', $data, 'id', $id);
    }

    public function setRoles(int $userId, array $roleIds): void
    {
        $this->query("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
        foreach (array_unique(array_map('intval', $roleIds)) as $roleId) {
            $this->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    /**
     * Active users holding a given role -- used to guard against removing
     * the last Super Admin and locking everyone out.
     */
    public function activeCountForRole(int $roleId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             WHERE ur.role_id = ? AND u.is_active = 1",
            [$roleId]
        );
    }

    public function resetPassword(int $id, string $hashedPassword): bool
    {
        return $this->update('users', ['password' => $hashedPassword], 'id', $id);
    }

    public function findByEmailOrUsername(string $login): ?array
    {
        return $this->one("SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1", [$login, $login]);
    }

    public function setResetToken(int $id, string $tokenHash, string $expiresAt): void
    {
        $this->update('users', [
            'password_reset_token' => $tokenHash,
            'password_reset_expires' => $expiresAt,
        ], 'id', $id);
    }

    public function findByValidResetToken(string $tokenHash): ?array
    {
        return $this->one(
            "SELECT * FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW() AND is_active = 1 LIMIT 1",
            [$tokenHash]
        );
    }

    public function clearResetToken(int $id): void
    {
        $this->update('users', [
            'password_reset_token' => null,
            'password_reset_expires' => null,
        ], 'id', $id);
    }
}
