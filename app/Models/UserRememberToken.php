<?php

namespace App\Models;

use App\Core\Model;

class UserRememberToken extends Model
{
    /** $days drives expires_at via MySQL's own NOW() + INTERVAL, not PHP's clock -- kept consistent with findValidBySelector()'s NOW() comparison. */
    public function create(int $userId, string $selector, string $validatorHash, int $days): int
    {
        $this->query(
            "INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL ? DAY)",
            [$userId, $selector, $validatorHash, $days]
        );
        return (int) $this->db->lastInsertId();
    }

    public function findValidBySelector(string $selector): ?array
    {
        return $this->one(
            "SELECT * FROM user_remember_tokens WHERE selector = ? AND expires_at > NOW()",
            [$selector]
        );
    }

    public function deleteBySelector(string $selector): void
    {
        $this->query("DELETE FROM user_remember_tokens WHERE selector = ?", [$selector]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $this->query("DELETE FROM user_remember_tokens WHERE user_id = ?", [$userId]);
    }
}
