<?php

namespace App\Models;

use App\Core\Model;

class PortalUser extends Model
{
    public function findByBorrower(int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM borrower_portal_users WHERE borrower_id = ?", [$borrowerId]);
    }

    /**
     * Create portal access for a borrower, or reset credentials if it
     * already exists (acts as both "create" and "reset password").
     */
    public function provision(int $borrowerId, string $username, ?string $email, string $passwordHash): int
    {
        $existing = $this->findByBorrower($borrowerId);

        if ($existing) {
            $this->update('borrower_portal_users', [
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'is_active' => 1,
            ], 'id', $existing['id']);
            return (int) $existing['id'];
        }

        return $this->insert('borrower_portal_users', [
            'borrower_id' => $borrowerId,
            'username' => $username,
            'email' => $email,
            'password' => $passwordHash,
            'is_active' => 1,
        ]);
    }

    public function findByUsernameOrEmail(string $login): ?array
    {
        return $this->one(
            "SELECT * FROM borrower_portal_users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1",
            [$login, $login]
        );
    }

    public function setResetToken(int $id, string $tokenHash, string $expiresAt): void
    {
        $this->update('borrower_portal_users', [
            'password_reset_token' => $tokenHash,
            'password_reset_expires' => $expiresAt,
        ], 'id', $id);
    }

    public function findByValidResetToken(string $tokenHash): ?array
    {
        return $this->one(
            "SELECT * FROM borrower_portal_users WHERE password_reset_token = ? AND password_reset_expires > NOW() AND is_active = 1 LIMIT 1",
            [$tokenHash]
        );
    }

    public function clearResetToken(int $id): void
    {
        $this->update('borrower_portal_users', [
            'password_reset_token' => null,
            'password_reset_expires' => null,
        ], 'id', $id);
    }

    public function resetPassword(int $id, string $hashedPassword): bool
    {
        return $this->update('borrower_portal_users', ['password' => $hashedPassword], 'id', $id);
    }
}
