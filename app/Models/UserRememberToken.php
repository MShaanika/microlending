<?php

namespace App\Models;

use App\Core\Model;

class UserRememberToken extends Model
{
    public function create(int $userId, string $selector, string $validatorHash, string $expiresAt): int
    {
        return $this->insert('user_remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
        ]);
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
